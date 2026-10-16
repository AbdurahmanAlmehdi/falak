<?php

namespace Falak\Sandboxes\Infrastructure\AgentRequests;

use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Domain\Models\Sandbox;

/**
 * sandbox.reaped: the agent removed a sandbox on its own (past expires_at, out of memory, its init exited). The sandbox
 * is marked killed with that reason; nothing is sent back to the agent.
 */
final class SandboxReaped implements AgentRequestHandler
{
    public function __construct(private readonly KillSandbox $kill) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $sandbox = Sandbox::query()
            ->where('organization_id', $caller->organizationId)
            ->where('server_id', $caller->serverId)
            ->find(strtolower((string) $body['id']));

        if ($sandbox === null) {
            return ['accepted' => false];
        }

        $this->kill->markKilled($sandbox, (string) $body['reason']);

        return ['accepted' => true];
    }
}
