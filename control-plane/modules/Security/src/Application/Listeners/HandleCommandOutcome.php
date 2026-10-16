<?php

namespace Falak\Security\Application\Listeners;

use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Security\Application\Actions\SettleAudit;
use Falak\Security\Application\Actions\UndoFix;
use Falak\Security\Application\FixRunner;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Enums\FixStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\FixRun;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Settles audits, fixes and undos from the outcome of the commands Security dispatched (matched by command id within
 * the organization).
 */
final class HandleCommandOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(
        private readonly SettleAudit $settle,
        private readonly FixRunner $runner,
        private readonly UndoFix $undo,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'security.audit' => ($audit = $this->audit($event->commandId, $event->organizationId)) ? ($this->settle)($audit, $event->result) : null,
            'security.fix' => $this->fixed($event),
            'security.undo' => ($run = $this->run('undo_command_id', $event->commandId, $event->organizationId, FixStatus::Undoing)) ? $this->undo->undone($run) : null,
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = mb_substr($event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : ''), 0, 1000);

        match ($event->type) {
            'security.audit' => $this->audit($event->commandId, $event->organizationId)?->forceFill(['status' => AuditStatus::Failed, 'error' => $reason])->save(),
            'security.fix' => ($run = $this->run('command_id', $event->commandId, $event->organizationId, FixStatus::Applying)) ? $this->runner->settle($run, FixStatus::Failed, error: $reason) : null,
            'security.undo' => ($run = $this->run('undo_command_id', $event->commandId, $event->organizationId, FixStatus::Undoing)) ? $this->undo->failed($run, $reason) : null,
            default => null,
        };
    }

    private function fixed(CommandFinished $event): void
    {
        $run = $this->run('command_id', $event->commandId, $event->organizationId, FixStatus::Applying);

        if ($run === null) {
            return;
        }

        $result = $event->result ?? [];
        $backup = is_string($result['backup_id'] ?? null) && preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$/', $result['backup_id']) ? $result['backup_id'] : null;

        $this->runner->settle(
            $run,
            ($result['changed'] ?? false) === true ? FixStatus::Applied : FixStatus::Unchanged,
            is_string($result['message'] ?? null) ? $result['message'] : null,
            backupId: $backup,
            undoable: $backup !== null,
        );
    }

    private function audit(string $commandId, string $organizationId): ?Audit
    {
        return Audit::query()->where('command_id', $commandId)->where('organization_id', $organizationId)->where('status', AuditStatus::Running)->first();
    }

    private function run(string $column, string $commandId, string $organizationId, FixStatus $status): ?FixRun
    {
        return FixRun::query()->where($column, $commandId)->where('organization_id', $organizationId)->where('status', $status)->first();
    }
}
