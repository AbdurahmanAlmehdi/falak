<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;

/**
 * Pause and resume a sandbox (`runsc pause/resume`, sandbox.v2), waiting for the agent. A paused sandbox uses no CPU;
 * its memory stays allocated (it still counts against quotas) and its time keeps running. Exec, files and consoles
 * resume it first (SandboxApiController, SandboxDirectory::wake); SweepSandboxes pauses idle ones without waiting.
 */
final class PauseSandbox
{
    public function __construct(private readonly SandboxAgent $agent) {}

    public function pause(Sandbox $sandbox): Sandbox
    {
        if ($sandbox->status === Sandbox::PAUSED) {
            return $sandbox;
        }

        abort_unless($sandbox->isRunning(), 409, $sandbox->status === Sandbox::CREATING ? 'The sandbox is still starting.' : "The sandbox is {$sandbox->status}.");
        $this->agent->requireV2($sandbox->server_id);
        $this->agent->ask($sandbox, 'sandbox.pause', ['id' => $sandbox->id], 30);
        self::settle($sandbox->id, Sandbox::PAUSED);

        return $sandbox->refresh();
    }

    public function resume(Sandbox $sandbox): Sandbox
    {
        if ($sandbox->isRunning()) {
            return $sandbox;
        }

        abort_unless($sandbox->status === Sandbox::PAUSED, 409, $sandbox->status === Sandbox::CREATING ? 'The sandbox is still starting.' : "The sandbox is {$sandbox->status}.");
        $this->agent->requireV2($sandbox->server_id);
        $this->agent->ask($sandbox, 'sandbox.resume', ['id' => $sandbox->id], 30);
        self::settle($sandbox->id, Sandbox::RUNNING);

        return $sandbox->refresh();
    }

    /**
     * What the agent answered (here, or a sandbox.pause/resume outcome): running ↔ paused only, never a sandbox killed
     * meanwhile. A resume counts as activity, so idle pause doesn't pause it again at once.
     */
    public static function settle(string $sandboxId, string $status): void
    {
        $from = $status === Sandbox::PAUSED ? Sandbox::RUNNING : Sandbox::PAUSED;

        Sandbox::query()->whereKey($sandboxId)->where('status', $from)
            ->update(array_filter(['status' => $status, 'last_activity_at' => $status === Sandbox::RUNNING ? now() : null, 'updated_at' => now()]));
    }
}
