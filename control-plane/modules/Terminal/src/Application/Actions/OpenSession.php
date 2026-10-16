<?php

namespace Falak\Terminal\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Events\TerminalSessionOpened;
use Falak\Terminal\Infrastructure\RelayTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open a terminal session: a shell on a server, or a console (docs/plans/CONSOLE.md) as a site's user or in a site's or
 * database's container. Agents with feature `terminal.v2` get the container target and, when the relay is on, a relay
 * token; older agents only open host shells, over the Reverb path.
 */
final class OpenSession
{
    public const FEATURE = 'terminal.v2';

    public const AGENT_TOO_OLD = 'The Falak agent on this server is too old for consoles; update it first.';

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $agentDirectory,
        private readonly AuditLog $audit,
        private readonly RelayTokens $tokens,
    ) {}

    /** A shell on a server, as $unixUser (default: the configured user, root). */
    public function __invoke(string $organizationId, string $userId, string $serverId, ?string $unixUser = null, int $cols = 80, int $rows = 24): TerminalSession
    {
        $server = $this->servers->find($serverId);

        if (! $server || $server->organizationId !== $organizationId) {
            abort(404);
        }

        return $this->open($organizationId, $userId, ConsoleTarget::server($server->id, $server->name, $unixUser ?: (string) config('terminal.default_user', 'root')), $cols, $rows);
    }

    /**
     * @param  bool  $requireRelay  the caller only speaks the relay (the API / `falak console`): refuse agents without it
     */
    public function open(string $organizationId, string $userId, ConsoleTarget $target, int $cols = 80, int $rows = 24, bool $requireRelay = false): TerminalSession
    {
        $server = $this->servers->find($target->serverId);

        if (! $server || $server->organizationId !== $organizationId) {
            abort(404);
        }

        if (! $server->isActive()) {
            throw ValidationException::withMessages(['server' => 'Terminals can be opened once the server is active.']);
        }

        $agent = $this->agentDirectory->forServers([$server->id])[$server->id] ?? null;
        $v2 = (bool) $agent?->supports(self::FEATURE);

        if (! $v2 && ($target->inContainer() || $requireRelay)) {
            throw ValidationException::withMessages(['server' => self::AGENT_TOO_OLD]);
        }

        if ($target->sandbox !== null && ! $agent?->supports(SandboxDirectory::FEATURE)) {
            throw ValidationException::withMessages(['server' => 'The Falak agent on this server is too old for sandboxes; update it first.']);
        }

        $relayed = $v2 && RelayTokens::enabled();
        $unixUser = $target->inContainer() ? '' : (string) $target->unixUser;
        $idle = max(0, (int) config('terminal.idle_timeout', 900));

        // The relay accepts the agent token only over a connection presenting this certificate (claim `fp`).
        $fingerprint = $agent?->certificateFingerprint;

        return DB::transaction(function () use ($organizationId, $userId, $server, $target, $unixUser, $cols, $rows, $idle, $relayed, $fingerprint) {
            $session = TerminalSession::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'target_type' => $target->type,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'container' => $target->containerLabel,
                'relayed' => $relayed,
                'user_id' => $userId,
                'unix_user' => $unixUser,
                'status' => SessionStatus::Opening,
                'cols' => $cols,
                'rows' => $rows,
                'initial_cols' => $cols,
                'initial_rows' => $rows,
                'idle_timeout_s' => $idle,
                'shared' => false,
                'last_activity_at' => now(),
            ]);

            try {
                $handle = $this->agents->dispatch($server->id, 'terminal.open', $this->payload($session, $target, $cols, $rows, $idle, $fingerprint), (int) config('terminal.max_duration', 3600), "terminal.open:{$session->id}");
            } catch (AgentUnavailable) {
                throw ValidationException::withMessages(['server' => 'The server agent is not connected.']);
            }

            $session->forceFill(['command_id' => $handle->id])->save();

            $this->audit->record('terminal.session_opened', 'terminal_session', $session->id, array_filter([
                'server_id' => $server->id,
                'server_name' => $server->name,
                'unix_user' => $unixUser !== '' ? $unixUser : null,
                'target_type' => $target->type,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'container' => $target->containerLabel,
            ], fn ($value) => $value !== null), $organizationId);

            TerminalSessionOpened::dispatch($session->id, $organizationId, $server->id, $userId, $unixUser, $target->type, $target->id, $target->name);

            return $session;
        });
    }

    /**
     * terminal.open: host shells get user, shell, cwd and env; containers get `container` (the agent ignores user,
     * shell and cwd there) and env. `relay_token` and `container` only go to terminal.v2 agents.
     *
     * @return array<string, mixed>
     */
    private function payload(TerminalSession $session, ConsoleTarget $target, int $cols, int $rows, int $idle, ?string $fingerprint): array
    {
        $env = [...$target->env, 'TERM' => (string) config('terminal.term', 'xterm-256color')];
        ksort($env);

        $payload = ['session_id' => $session->id];

        if (! $target->inContainer()) {
            $payload['user'] = $target->unixUser;
            $payload['shell'] = (string) config('terminal.shell', '/bin/bash');

            if ($target->cwd !== null) {
                $payload['cwd'] = $target->cwd;
            }
        }

        $payload += [
            'cols' => $cols,
            'rows' => $rows,
            'idle_timeout_s' => $idle,
            'env' => $env,
        ];

        if ($target->container !== null) {
            $payload['container'] = $target->container;
        } elseif ($target->sandbox !== null) {
            $payload['sandbox'] = $target->sandbox;
        }

        if ($session->relayed) {
            $payload['relay_token'] = $this->tokens->agentToken($session, $fingerprint);
        }

        return $payload;
    }
}
