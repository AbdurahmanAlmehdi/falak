<?php

namespace Falak\Sandboxes\Application\Jobs;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every 5 minutes: sandbox.inventory on each server with live sandboxes, or with sandboxes killed in the last day whose
 * kill the agent hasn't confirmed (an agent that was offline); HandleCommandOutcome reconciles the answer. Those kills
 * are sent again (once an hour: a queued kill waits up to an hour for an offline agent).
 */
final class SyncSandboxes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(AgentGateway $agents, KillSandbox $kill): void
    {
        $unconfirmed = Sandbox::query()->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')
            ->where('killed_at', '>', now()->subDay())->limit(500)->get();
        $serverIds = Sandbox::query()->live()->distinct()->pluck('server_id')->merge($unconfirmed->pluck('server_id'))->unique();
        $slot = intdiv(time(), 300);

        foreach ($unconfirmed as $sandbox) {
            $kill->remove($sandbox, "sandbox.kill:{$sandbox->id}:retry:".intdiv(time(), 3600), $sandbox->kill_reason);
        }

        foreach ($serverIds as $serverId) {
            try {
                $agents->dispatch((string) $serverId, 'sandbox.inventory', (object) [], 120, "sandbox.inventory:{$serverId}:{$slot}");
            } catch (AgentUnavailable) {
                // The agent reaps on its own; the next sync catches up.
            }
        }
    }
}
