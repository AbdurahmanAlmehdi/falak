<?php

namespace Falak\Sandboxes\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Validation\ValidationException;

/**
 * sandbox.* commands from HTTP handlers: exec and files wait for the agent's answer (dispatch + await, cancelled when
 * it doesn't come), and agent errors become HTTP errors the CLI and MCP can show as they are.
 */
final class SandboxAgent
{
    public const NOT_CONNECTED = 'The server agent is not connected.';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * Snapshots, forks, pause/resume and database copies need an agent with sandbox.v2: a clear 422 otherwise.
     *
     * @throws ValidationException
     */
    public function requireV2(string $serverId): void
    {
        if (! ($this->directory->forServer($serverId)?->supports(SandboxDirectory::FEATURE_V2) ?? false)) {
            $name = $this->servers->find($serverId)?->name ?? 'this server';

            throw ValidationException::withMessages(['server' => "The agent on {$name} is too old for snapshots, forks, pause and database copies (they need sandbox.v2); update it first."]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function tryDispatch(Sandbox|SandboxSnapshot $on, string $type, array $payload, string $key, int $timeout = 600): ?CommandHandle
    {
        try {
            return $this->agents->dispatch($on->server_id, $type, $payload, $timeout, $key);
        } catch (AgentUnavailable) {
            return null;
        }
    }

    /**
     * Run a command in the sandbox and wait up to $wait seconds for its result.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ask(Sandbox $sandbox, string $type, array $payload, int $wait): array
    {
        try {
            $handle = $this->agents->dispatch($sandbox->server_id, $type, $payload, $wait, "{$type}:{$sandbox->id}:".bin2hex(random_bytes(8)));
        } catch (AgentUnavailable) {
            abort(503, self::NOT_CONNECTED);
        }

        try {
            $result = $this->agents->await($handle, $wait);
        } catch (CommandTimedOut) {
            $this->agents->cancel($handle);
            abort(504, 'The server did not answer in time.');
        } finally {
            // A call's end is activity too: idle pause counts from it, not from when a long exec or copy started.
            Sandbox::query()->whereKey($sandbox->id)->update(['last_activity_at' => now()]);
        }

        if (! $result->isFinished()) {
            $this->agents->cancel($handle);
            abort(504, "The sandbox did not answer within {$wait} seconds.");
        }

        if (! $result->isSuccessful()) {
            $error = (string) ($result->error ?: 'The command failed in the sandbox.');
            $lower = strtolower($error);

            if (str_contains($lower, 'is_directory') || str_contains($lower, 'is a directory')) {
                abort(422, 'The path is a directory.');
            }

            if (str_contains($lower, 'not_directory') || str_contains($lower, 'not a directory')) {
                abort(422, 'The path is not a directory.');
            }

            // sandbox.pause while an exec or a database copy runs in it.
            if (str_starts_with($lower, 'busy:')) {
                abort(409, 'The sandbox is running a command; pause it once that finished.');
            }

            // The agent doesn't know the sandbox (reaped, or gone with a reboot).
            if (preg_match('/\bsandbox \S+ not found/', $lower) === 1) {
                abort(409, 'The sandbox is not running.');
            }

            abort(str_contains($lower, 'not_found') || str_contains($lower, 'no such file') ? 404 : 502, mb_substr($error, 0, 1000));
        }

        return $result->result ?? [];
    }
}
