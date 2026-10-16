<?php

namespace Falak\Sandboxes\Application\Jobs;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every 5 minutes: sandbox.inventory on each server with live sandboxes; HandleCommandOutcome reconciles the answer.
 */
final class SyncSandboxes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(AgentGateway $agents): void
    {
        $serverIds = Sandbox::query()->live()->distinct()->pluck('server_id');
        $slot = intdiv(time(), 300);

        foreach ($serverIds as $serverId) {
            try {
                $agents->dispatch((string) $serverId, 'sandbox.inventory', (object) [], 120, "sandbox.inventory:{$serverId}:{$slot}");
            } catch (AgentUnavailable) {
                // The agent reaps on its own; the next sync catches up.
            }
        }
    }
}
