<?php

namespace Falak\Sandboxes\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Sandboxes\Domain\Models\Sandbox;

/**
 * sandbox.* commands from HTTP handlers: exec and files wait for the agent's answer (dispatch + await, cancelled when
 * it doesn't come), and agent errors become HTTP errors the CLI and MCP can show as they are.
 */
final class SandboxAgent
{
    public const NOT_CONNECTED = 'The server agent is not connected.';

    public function __construct(private readonly AgentGateway $agents) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function tryDispatch(Sandbox $sandbox, string $type, array $payload, string $key, int $timeout = 600): ?CommandHandle
    {
        try {
            return $this->agents->dispatch($sandbox->server_id, $type, $payload, $timeout, $key);
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

            // The agent doesn't know the sandbox (reaped, or gone with a reboot).
            if (preg_match('/\bsandbox \S+ not found/', $lower) === 1) {
                abort(409, 'The sandbox is not running.');
            }

            abort(str_contains($lower, 'not_found') || str_contains($lower, 'no such file') ? 404 : 502, mb_substr($error, 0, 1000));
        }

        return $result->result ?? [];
    }
}
