<?php

namespace Falak\Terminal\Application\Listeners;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Terminal\Domain\Models\NetToolRun;

/** Settles a network tool run when its net.tool command ends. */
final class SettleNetToolRun
{
    public function handleFinished(CommandFinished $event): void
    {
        $this->settle($event->type, $event->commandId, NetToolRun::SUCCEEDED, $event->exitCode ?? 0, null);
    }

    public function handleFailed(CommandFailed $event): void
    {
        // A cancelled command (or a tool the agent reports cancelled, exit 130) is not a timeout; an output-cap kill is a
        // failure with its own message ("output limit reached").
        $status = match (true) {
            $event->status === 'cancelled' || $event->exitCode === 130 => NetToolRun::CANCELLED,
            $event->status === 'timed_out' || $event->exitCode === 124 => NetToolRun::TIMED_OUT,
            default => NetToolRun::FAILED,
        };
        $this->settle($event->type, $event->commandId, $status, $event->exitCode, $event->error);
    }

    private function settle(string $type, string $commandId, string $status, ?int $exitCode, ?string $error): void
    {
        if ($type !== 'net.tool') {
            return;
        }

        NetToolRun::query()->where('command_id', $commandId)->where('status', NetToolRun::RUNNING)->update([
            'status' => $status,
            'exit_code' => $exitCode,
            'error' => $error !== null ? mb_substr($error, 0, 255) : null,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
