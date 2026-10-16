<?php

namespace Falak\Processes\Application\Listeners;

use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Processes\Application\OctaneRoutes;
use Falak\Processes\Application\StatusPoller;
use Falak\Processes\Domain\Enums\ApplyStatus;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Events\SchedulesApplied;
use Falak\Processes\Infrastructure\AgentProcessControl;
use Falak\Processes\Infrastructure\StateScheduleDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Settles proc.apply / cron.apply / proc.status commands dispatched by Processes. Only the currently
 * tracked command counts; results of superseded applies are ignored.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(
        private readonly StatusPoller $status,
        private readonly OctaneRoutes $octane,
        private readonly AgentProcessControl $processes,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'proc.apply' => $this->applied($event, 'proc'),
            'cron.apply' => $this->applied($event, 'cron'),
            'proc.status' => StatusPoller::isStatusCommand($event->idempotencyKey) ? $this->status->record($event->serverId, $event->result) : null,
            // A restarted Octane that was never verified (e.g. the first deploy replaced the placeholder) is probed now.
            'proc.restart' => $this->restarted($event->idempotencyKey, $event->serverId),
            'system.exec' => OctaneRoutes::isProbe($event->idempotencyKey) ? $this->octane->settleProbe($event->commandId, true, null) : null,
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        if ($event->type === 'system.exec' && OctaneRoutes::isProbe($event->idempotencyKey)) {
            $this->octane->settleProbe($event->commandId, false, $reason);

            return;
        }

        if ($event->type === 'system.exec' && str_starts_with($event->idempotencyKey, AgentProcessControl::RELOAD_PREFIX)) {
            $this->processes->reloadFailed($event->idempotencyKey);

            return;
        }

        if (! in_array($event->type, ['proc.apply', 'cron.apply'], true)) {
            return;
        }

        $prefix = $event->type === 'proc.apply' ? 'proc' : 'cron';

        ServerState::query()
            ->where('server_id', $event->serverId)
            ->where("{$prefix}_command_id", $event->commandId)
            ->update(["{$prefix}_status" => ApplyStatus::Failed, "{$prefix}_error" => Str::limit($reason, 990), 'updated_at' => now()]);
    }

    private function applied(CommandFinished $event, string $prefix): void
    {
        $state = ServerState::query()->where('server_id', $event->serverId)->where("{$prefix}_command_id", $event->commandId)->first();

        if ($state === null) {
            return;
        }

        if ($prefix === 'proc') {
            $sliceErrors = array_filter((array) ($event->result['slice_errors'] ?? []), 'is_string');
            $state->forceFill([
                'proc_status' => ApplyStatus::Applied,
                // Slices the agent skipped (their programs run without limits): applied, with what to fix.
                'proc_error' => $sliceErrors === [] ? null : Str::limit('Limits not applied: '.implode('; ', array_map(fn ($name, $error) => "{$name}: {$error}", array_keys($sliceErrors), $sliceErrors)), 990),
                'proc_applied_at' => now(),
                'applied_programs' => array_keys($state->programs ?? []),
                // Removed programs can no longer crash.
                'crash_looping' => array_values(array_intersect($state->crash_looping ?? [], array_keys($state->programs ?? []))) ?: null,
            ])->save();

            // Octane programs that were (re)configured: the edge switches to them once they answer.
            $this->octane->probeServer($state->server_id);

            return;
        }

        $state->forceFill([
            'cron_status' => ApplyStatus::Applied,
            'cron_error' => null,
            'cron_applied_at' => now(),
            'applied_jobs' => array_keys($state->jobs ?? []),
        ])->save();

        SchedulesApplied::dispatch($state->server_id, $state->organization_id, StateScheduleDirectory::jobsOf($state));
    }

    private function restarted(string $idempotencyKey, string $serverId): void
    {
        if (! str_starts_with($idempotencyKey, 'processes.restart:')) {
            return;
        }

        $siteId = explode(':', $idempotencyKey)[1] ?? '';

        if ($siteId !== '') {
            $this->octane->probeServer($serverId, $siteId);
        }
    }
}
