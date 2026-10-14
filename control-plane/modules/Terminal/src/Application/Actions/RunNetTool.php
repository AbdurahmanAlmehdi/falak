<?php

namespace Falak\Terminal\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Data\NetToolSpec;
use Falak\Terminal\Domain\Models\NetToolRun;
use Illuminate\Validation\ValidationException;

/**
 * Run a network tool (docs/plans/network/tools.md) where a console would open: on a server's host network, or in a
 * site's or database's container network namespace. Containers are selected as for consoles (name or labels); native
 * sites run on the host. Only agents with feature `net.tools` get the command.
 */
final class RunNetTool
{
    public const FEATURE = 'net.tools';

    public const AGENT_TOO_OLD = 'The Falak agent on this server is too old for network tools; update it first.';

    public const BUSY = 'Wait for your other network tools to finish.';

    /** Runs of one user that may be running at once (older than STALE_SECONDS don't count). */
    public const MAX_RUNNING = 2;

    public const STALE_SECONDS = 120;

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $agentDirectory,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  tool + its arguments (validated here)
     */
    public function __invoke(string $organizationId, string $userId, ConsoleTarget $target, array $input): NetToolRun
    {
        $payload = NetToolSpec::payload($input);
        $server = $this->servers->find($target->serverId);

        if (! $server || $server->organizationId !== $organizationId) {
            abort(404);
        }

        if (! $server->isActive()) {
            throw ValidationException::withMessages(['server' => 'Network tools run once the server is active.']);
        }

        $agent = $this->agentDirectory->forServers([$server->id])[$server->id] ?? null;

        if (! $agent?->supports(self::FEATURE)) {
            throw ValidationException::withMessages(['server' => self::AGENT_TOO_OLD]);
        }

        $running = NetToolRun::query()->where('user_id', $userId)->where('status', NetToolRun::RUNNING)
            ->where('created_at', '>=', now()->subSeconds(self::STALE_SECONDS))->count();
        abort_if($running >= self::MAX_RUNNING, 429, self::BUSY);

        $container = null;

        if ($target->container !== null) {
            // net.tool's selector: name or labels, never a user or workdir.
            $container = array_intersect_key($target->container, ['name' => true, 'labels' => true]);
            $payload['container'] = $container;
        }

        $run = NetToolRun::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'target_type' => $target->type,
            'target_id' => $target->id,
            'target_name' => $target->name ?? $server->name,
            'container' => $target->containerLabel,
            'tool' => $payload['tool'],
            'input' => array_diff_key($payload, ['tool' => true, 'container' => true]),
            'status' => NetToolRun::RUNNING,
        ]);

        try {
            $handle = $this->agents->dispatch($server->id, 'net.tool', $payload, (int) $payload['timeout_s'] + 15, "net.tool:{$run->id}");
        } catch (AgentUnavailable) {
            $run->delete();

            throw ValidationException::withMessages(['server' => 'The server agent is not connected.']);
        }

        $run->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('network.tool_run', 'net_tool_run', $run->id, [
            'tool' => $run->tool,
            'target_type' => $run->target_type,
            'target_name' => $run->target_name,
            'server_name' => $run->server_name,
            'container' => $run->container,
            ...$run->input,
        ], $organizationId);

        return $run;
    }
}
