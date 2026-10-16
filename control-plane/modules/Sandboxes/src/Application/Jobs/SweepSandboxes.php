<?php

namespace Falak\Sandboxes\Application\Jobs;

use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every minute: kill sandboxes past expires_at (the agent reaps them too, so a dead control plane can't leave them
 * running), fail sandboxes still creating after `sandboxes.create_timeout` and snapshots still being taken after
 * SNAPSHOT_TIMEOUT, and pause running sandboxes idle for their idle_pause_seconds (sandbox.pause, without waiting:
 * HandleCommandOutcome settles the status).
 */
final class SweepSandboxes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** A snapshot still `creating` after this many seconds failed (sandbox.snapshot's own timeout is 900 s). */
    public const SNAPSHOT_TIMEOUT = 1800;

    public function handle(KillSandbox $kill, ?SandboxAgent $agent = null): void
    {
        $agent ??= app(SandboxAgent::class);

        Sandbox::query()->live()->where('expires_at', '<=', now())->orderBy('expires_at')->limit(500)->get()
            ->each(fn (Sandbox $sandbox) => $kill($sandbox, 'expired'));

        Sandbox::query()->where('status', Sandbox::CREATING)
            ->where('created_at', '<', now()->subSeconds((int) config('sandboxes.create_timeout', 300)))
            ->limit(500)->get()
            ->each(function (Sandbox $sandbox) use ($kill) {
                $sandbox->forceFill(['status' => Sandbox::FAILED, 'error' => 'The sandbox did not start within '.intdiv((int) config('sandboxes.create_timeout', 300), 60).' minutes.'])->save();
                $kill->remove($sandbox, "sandbox.kill:{$sandbox->id}", 'failed');
            });

        SandboxSnapshot::query()->where('status', SandboxSnapshot::CREATING)->where('created_at', '<', now()->subSeconds(self::SNAPSHOT_TIMEOUT))
            ->update(['status' => SandboxSnapshot::FAILED, 'error' => 'The snapshot was not taken within '.intdiv(self::SNAPSHOT_TIMEOUT, 60).' minutes.', 'updated_at' => now()]);

        // Idle: no exec, file call, console or API call on it for idle_pause_seconds (an open console keeps it active:
        // Terminal marks those every minute through SandboxDirectory::markActive).
        $slot = intdiv(now()->getTimestamp(), 60);
        Sandbox::query()->where('status', Sandbox::RUNNING)->whereNotNull('idle_pause_seconds')->limit(500)->get()
            ->filter(fn (Sandbox $sandbox) => ($sandbox->last_activity_at ?? $sandbox->started_at ?? $sandbox->created_at)->lte(now()->subSeconds((int) $sandbox->idle_pause_seconds)))
            ->each(fn (Sandbox $sandbox) => $agent->tryDispatch($sandbox, 'sandbox.pause', ['id' => $sandbox->id], "sandbox.pause:{$sandbox->id}:idle:{$slot}", 120));
    }
}
