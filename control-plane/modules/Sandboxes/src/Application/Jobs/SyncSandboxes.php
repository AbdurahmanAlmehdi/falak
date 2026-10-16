<?php

namespace Falak\Sandboxes\Application\Jobs;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Listeners\HandleCommandOutcome;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every 5 minutes: sandbox.inventory on each server with live sandboxes, or with sandboxes killed in the last day whose
 * kill the agent hasn't confirmed (an agent that was offline); HandleCommandOutcome reconciles the answer. Those kills
 * are sent again (once an hour: a queued kill waits up to an hour for an offline agent), and so is the expiry of a running
 * sandbox whose agent doesn't enforce it yet.
 */
final class SyncSandboxes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(AgentGateway $agents, KillSandbox $kill, ?HandleCommandOutcome $outcomes = null): void
    {
        $outcomes ??= app(HandleCommandOutcome::class);
        $unconfirmed = Sandbox::query()->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')
            ->where('killed_at', '>', now()->subDay())->limit(500)->get();
        $serverIds = Sandbox::query()->live()->distinct()->pluck('server_id')->merge($unconfirmed->pluck('server_id'))->unique();
        $slot = intdiv(now()->getTimestamp(), 300);

        foreach ($unconfirmed as $sandbox) {
            $kill->remove($sandbox, "sandbox.kill:{$sandbox->id}:retry:".intdiv(time(), 3600), $sandbox->kill_reason);
        }

        // An expiry the agent wasn't told (an extend that raced the create, or a sandbox.extend that never arrived).
        Sandbox::query()->where('status', Sandbox::RUNNING)->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('agent_expires_at')->orWhereColumn('agent_expires_at', '!=', 'expires_at'))
            ->limit(500)->get()
            ->each(fn (Sandbox $sandbox) => $outcomes->sendExpiry($sandbox, "sync:{$slot}"));

        foreach ($serverIds as $serverId) {
            try {
                $agents->dispatch((string) $serverId, 'sandbox.inventory', (object) [], 120, "sandbox.inventory:{$serverId}:{$slot}");
            } catch (AgentUnavailable) {
                // The agent reaps on its own; the next sync catches up.
            }
        }
    }
}
