<?php

namespace Falak\Recipes\Application\Listeners;

use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Recipes\Application\RunProgress;
use Falak\Recipes\Domain\Enums\TargetStatus;
use Falak\Recipes\Domain\Models\RunTarget;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Records the outcome of recipe system.exec commands and rolls up the run status.
 */
final class SettleRecipeTargets implements ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(private readonly RunProgress $progress) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'system.exec') {
            return;
        }

        $this->settle($event->commandId, $event->organizationId, TargetStatus::Succeeded, $event->exitCode, null, $event->result);
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type !== 'system.exec') {
            return;
        }

        $error = $event->error ?: match ($event->status) {
            'timed_out' => 'Timed out.',
            'cancelled' => 'Cancelled.',
            default => $event->exitCode !== null ? "Exited with code {$event->exitCode}." : 'Failed.',
        };

        $this->settle($event->commandId, $event->organizationId, TargetStatus::Failed, $event->exitCode, $error, $event->result);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function settle(string $commandId, string $organizationId, TargetStatus $status, ?int $exitCode, ?string $error, ?array $result): void
    {
        $target = RunTarget::query()->where('command_id', $commandId)->first();

        if (! $target || $target->organization_id !== $organizationId) {
            return;
        }

        // A late success may overturn a control-plane timeout; anything else is final.
        if ($target->status->isFinished() && ! ($status === TargetStatus::Succeeded && $target->status === TargetStatus::Failed)) {
            return;
        }

        $duration = is_numeric($result['duration_ms'] ?? null)
            ? (int) $result['duration_ms']
            : ($target->started_at ? (int) $target->started_at->diffInMilliseconds(now(), true) : null);

        $target->forceFill([
            'status' => $status,
            'exit_code' => $exitCode ?? (is_numeric($result['exit_code'] ?? null) ? (int) $result['exit_code'] : null),
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'duration_ms' => $duration,
            'started_at' => $target->started_at ?? now(),
            'finished_at' => now(),
        ])->save();

        $this->progress->refresh($target->run()->firstOrFail());
    }
}
