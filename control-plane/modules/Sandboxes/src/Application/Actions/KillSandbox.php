<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;

/**
 * Kill a sandbox: sandbox.kill (idempotent, redelivered until the agent has it) and the row is killed at once. An
 * agent that is offline still reaps the sandbox at expires_at on its own. Killing a killed sandbox does nothing.
 */
final class KillSandbox
{
    public function __construct(
        private readonly SandboxAgent $agent,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $reason  killed (by someone) | expired | lost | oom | exited | server_deleted
     */
    public function __invoke(Sandbox $sandbox, string $reason = 'killed'): void
    {
        if ($sandbox->status === Sandbox::KILLED) {
            return;
        }

        $this->remove($sandbox, "sandbox.kill:{$sandbox->id}", $reason);
        $this->markKilled($sandbox, $reason, confirmed: false);
    }

    /**
     * sandbox.kill only, the row unchanged (a sandbox that failed to start, or one the agent should not run).
     *
     * @param  string  $key  the idempotency key: the agent answers a repeated key from its cache
     */
    public function remove(Sandbox $sandbox, string $key, ?string $reason = null): void
    {
        $this->agent->tryDispatch($sandbox, 'sandbox.kill', array_filter(['id' => $sandbox->id, 'reason' => $reason]), $key);
    }

    /**
     * The agent removed it (or is told to): killed, with the reason.
     *
     * @param  bool  $confirmed  the agent doesn't run it (reaped, lost, its server deleted); otherwise SyncSandboxes
     *                           sends sandbox.kill again until a kill finishes
     */
    public function markKilled(Sandbox $sandbox, string $reason, bool $confirmed = true): void
    {
        if ($sandbox->status === Sandbox::KILLED) {
            return;
        }

        $sandbox->forceFill(['status' => Sandbox::KILLED, 'killed_at' => now(), 'kill_reason' => $reason, 'kill_confirmed_at' => $confirmed ? now() : null])->save();

        $this->audit->record('sandbox.killed', 'sandbox', $sandbox->id, ['name' => $sandbox->name, 'reason' => $reason], $sandbox->organization_id);
    }
}
