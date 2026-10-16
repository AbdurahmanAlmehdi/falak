<?php

namespace Falak\Sandboxes\Application\Jobs;

use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every minute: kill sandboxes past expires_at (the agent reaps them too, so a dead control plane can't leave them
 * running), and fail sandboxes still creating after `sandboxes.create_timeout`.
 */
final class SweepSandboxes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(KillSandbox $kill): void
    {
        Sandbox::query()->live()->where('expires_at', '<=', now())->orderBy('expires_at')->limit(500)->get()
            ->each(fn (Sandbox $sandbox) => $kill($sandbox, 'expired'));

        Sandbox::query()->where('status', Sandbox::CREATING)
            ->where('created_at', '<', now()->subSeconds((int) config('sandboxes.create_timeout', 300)))
            ->limit(500)->get()
            ->each(function (Sandbox $sandbox) use ($kill) {
                $sandbox->forceFill(['status' => Sandbox::FAILED, 'error' => 'The sandbox did not start within '.intdiv((int) config('sandboxes.create_timeout', 300), 60).' minutes.'])->save();
                $kill->remove($sandbox, "sandbox.kill:{$sandbox->id}", 'failed');
            });
    }
}
