<?php

namespace Falak\Volumes\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\Actions\DeleteVolume;
use Falak\Volumes\Application\Actions\SettleVolumeDrill;
use Falak\Volumes\Application\Jobs\PruneScheduleBackups;
use Falak\Volumes\Application\Transfers;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Events\VolumeAlmostFull;
use Falak\Volumes\Events\VolumeBackupFinished;
use Falak\Volumes\Events\VolumeSpaceRecovered;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Settles volumes, operations and backups when the volume.* commands Volumes dispatched finish, and moves multi-step
 * operations on: a clone or move to another server restores once its archive is in storage, a move hands the services
 * over and deletes its source once the restore succeeded.
 */
final class HandleCommandOutcome implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use SkipsSandboxCommands;

    private const TYPES = ['volume.create', 'volume.delete', 'volume.resize', 'volume.archive', 'volume.restore', 'volume.clone', 'volume.download', 'volume.inventory', 'volume.drill'];

    /** Payload secrets dropped once a command settled: archive keys, a customer's age identity. */
    private const KEY_PATHS = ['encryption.key', 'encryption.identity'];

    public function __construct(
        private readonly Transfers $transfers,
        private readonly DeleteVolume $delete,
        private readonly AgentGateway $agents,
        private readonly SettleVolumeDrill $drills,
        private readonly AuditLog $audit,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->settle($event->type, $event->commandId, $event->organizationId, true, null, $event->result ?? [], $event->serverId);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
            $this->settle($event->type, $event->commandId, $event->organizationId, false, mb_substr($reason, 0, 1000), $event->result ?? [], $event->serverId);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settle(string $type, string $commandId, string $organizationId, bool $succeeded, ?string $error, array $result, string $serverId = ''): void
    {
        if (in_array($type, ['volume.archive', 'volume.restore', 'volume.drill'], true)) {
            if (! $this->agents->forgetSecrets($commandId, self::KEY_PATHS)) {
                // Not settled yet as far as Fleet knows: the fleet sweep forgets them later.
                Log::info('Command secrets left for the fleet sweep.', ['command_id' => $commandId]);
            }
        }

        match ($type) {
            'volume.drill' => ($this->drills)($commandId, $succeeded, $error, $result),
            'volume.create' => $this->created($commandId, $succeeded, $error, $result),
            'volume.delete' => $this->deleted($commandId, $succeeded, $error),
            'volume.resize' => $this->resized($commandId, $succeeded, $error, $result),
            'volume.archive' => $this->archived($commandId, $succeeded, $error, $result),
            'volume.restore', 'volume.clone' => $this->filled($commandId, $succeeded, $error, $result),
            'volume.download' => $this->downloaded($commandId, $succeeded, $error, $result),
            'volume.inventory' => $succeeded ? $this->inventory($organizationId, $serverId, $result) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $result  volume.create $defs/result: path, size_bytes, created
     */
    private function created(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $volume = Volume::query()->where('command_id', $commandId)->where('status', VolumeStatus::Pending)->first();

        if ($volume === null) {
            return;
        }

        $volume->forceFill([
            'status' => $succeeded ? VolumeStatus::Active : VolumeStatus::Failed,
            'status_message' => $succeeded ? null : $error,
            ...$succeeded && $volume->kind !== VolumeKind::Bind && is_string($result['path'] ?? null) ? ['host_path' => mb_substr($result['path'], 0, 1024)] : [],
        ])->save();
    }

    private function deleted(string $commandId, bool $succeeded, ?string $error): void
    {
        $volume = Volume::query()->where('command_id', $commandId)->where('status', VolumeStatus::Deleting)->first();

        if ($volume === null) {
            return;
        }

        if ($succeeded) {
            $volume->delete();

            return;
        }

        $volume->forceFill(['status' => VolumeStatus::Active, 'status_message' => "Delete failed: {$error}"])->save();
    }

    /**
     * @param  array<string, mixed>  $result  volume.resize $defs/result: size_bytes, previous_bytes, grown
     */
    private function resized(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $operation = $this->operation($commandId);

        if ($operation === null) {
            return;
        }

        if (! $succeeded) {
            $operation->fail((string) $error);

            return;
        }

        $operation->volume?->forceFill(['size_limit_bytes' => (int) ($result['size_bytes'] ?? $operation->meta('to'))])->save();
        $operation->succeed($result);
    }

    /**
     * A backup's archive is in storage (or failed). Transfers between servers then restore it on the target.
     *
     * @param  array<string, mixed>  $result  volume.archive $defs/result: size_bytes, sha256, location, uncompressed_bytes, files, duration_ms
     */
    private function archived(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $backup = VolumeBackup::query()->where('command_id', $commandId)->first();

        // A late success may overturn a control-plane timeout; anything else is settled once.
        if ($backup === null || in_array($backup->status, [BackupStatus::Succeeded, BackupStatus::Pruned], true)
            || ($backup->status === BackupStatus::Failed && ! $succeeded)) {
            return;
        }

        $sha = is_string($result['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1 ? $result['sha256'] : null;
        $previous = VolumeBackup::query()->whereKeyNot($backup->id)->whereNotNull('finished_at')
            ->when($backup->schedule_id !== null, fn ($q) => $q->where('schedule_id', $backup->schedule_id), fn ($q) => $q->whereNull('schedule_id')->where('volume_id', $backup->volume_id))
            ->latest('finished_at')->value('status');

        $plainSha = is_string($result['plaintext_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['plaintext_sha256']) === 1 ? $result['plaintext_sha256'] : null;

        if ($succeeded && $sha === null) {
            [$succeeded, $error] = [false, 'The agent reported no checksum for the upload.'];
        } elseif ($succeeded && ($plainSha === null || ($result['key_id'] ?? null) !== $backup->id || ($result['encryption'] ?? null) !== ($backup->isCustomerHeld() ? 'age' : 'cp'))) {
            // An agent that did not encrypt (or not with this archive's key) left a file nobody should trust.
            [$succeeded, $error] = [false, 'The agent did not report the archive as encrypted with its key.'];
        }

        $backup->forceFill($succeeded ? [
            'status' => BackupStatus::Succeeded,
            'size_bytes' => (int) ($result['size_bytes'] ?? 0),
            'uncompressed_bytes' => is_int($result['uncompressed_bytes'] ?? null) ? $result['uncompressed_bytes'] : null,
            'files' => is_int($result['files'] ?? null) ? $result['files'] : null,
            'plaintext_sha256' => $plainSha,
            'sha256' => $sha,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'error' => null,
            'finished_at' => now(),
        ] : [
            'status' => BackupStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        if ($succeeded && $backup->schedule_id !== null) {
            PruneScheduleBackups::dispatch($backup->schedule_id);
        }

        // Failures alert; the first success after one resolves it.
        if (! $succeeded || $previous === BackupStatus::Failed) {
            VolumeBackupFinished::dispatch($succeeded, $backup->id, $backup->organization_id, $backup->volume_id, $backup->volume_name, $backup->schedule_id, $succeeded ? null : $error);
        }

        $operation = $this->operation($commandId);

        if ($operation === null || $operation->step !== 'archive') {
            return;
        }

        $target = $operation->volume;

        if (! $succeeded || $target === null) {
            $this->transfers->failed($operation, $target, (string) ($error ?? 'The target volume is gone.'));

            return;
        }

        $this->transfers->restore($operation, $backup, $target, background: true);
    }

    /**
     * A volume was filled from a backup (restore, a transfer's second half) or a local clone: it is ready, and the
     * operation finishes (a swap or a move hands the services over first).
     *
     * @param  array<string, mixed>  $result  bytes, files, duration_ms
     */
    private function filled(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $operation = $this->operation($commandId);

        if ($operation === null) {
            return;
        }

        $target = $operation->volume;

        if (! $succeeded || $target === null) {
            $this->transfers->failed($operation, $target, (string) ($error ?? 'The target volume is gone.'));

            return;
        }

        $target->forceFill(['status' => VolumeStatus::Active, 'status_message' => null, 'used_bytes' => isset($result['bytes']) ? (int) $result['bytes'] : $target->used_bytes, 'used_at' => now()])->save();
        $this->transfers->discard(VolumeBackup::query()->find($operation->meta('backup_id')));

        // A compose volume's move (a site moving) hands nothing over: its stack switches when it deploys on the target.
        $composeMove = $operation->kind === OperationKind::Move && $operation->meta('compose') === true;
        $from = $operation->meta('swap_from') ?? ($operation->kind === OperationKind::Move && ! $composeMove ? $operation->meta('source_id') : null);
        $source = is_string($from) ? Volume::query()->find($from) : null;
        $redeployed = [];

        if ($source !== null) {
            $redeployed = $this->transfers->handOver($source, $target, $operation->requested_by, $operation->kind === OperationKind::Move
                ? "Volume {$target->name} moved to another server"
                : "Volume {$source->name} swapped for its restore {$target->name}");
        }

        $operation->succeed([...$result, 'redeployed' => $redeployed]);

        if ($operation->kind === OperationKind::Move && $source !== null) {
            // The move recreated the protection on the target; the source goes once its containers stopped using it.
            $source->forceFill(['protected' => false])->save();

            try {
                ($this->delete)($source, $operation->requested_by, (int) config('volumes.delete_wait_s', 120), background: true);
            } catch (ValidationException $e) {
                $operation->forceFill(['result' => [...(array) $operation->result, 'source_kept' => collect($e->errors())->flatten()->first()]])->save();
            }
        }

        $this->audit->record("volumes.{$operation->kind->value}_succeeded", 'volume', $target->id, ['name' => $target->name, 'operation_id' => $operation->id], $target->organization_id);
    }

    /**
     * @param  array<string, mixed>  $result  volume.download $defs/result: size_bytes, sha256, format, name, files
     */
    private function downloaded(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $operation = $this->operation($commandId);

        if ($operation === null) {
            return;
        }

        $succeeded
            ? $operation->succeed(array_intersect_key($result, array_flip(['size_bytes', 'sha256', 'format', 'name', 'files'])))
            : $operation->fail((string) $error);
    }

    /**
     * Usage of the volumes a server reported (only its own volumes, of the organization that owns it). A volume over
     * {@see VolumeAlmostFull::THRESHOLD} of its limit alerts once per crossing, resolved once it is down to
     * {@see VolumeAlmostFull::RESOLVE_BELOW}.
     *
     * @param  array<string, mixed>  $result  volume.inventory $defs/result
     */
    private function inventory(string $organizationId, string $serverId, array $result): void
    {
        $items = collect((array) ($result['volumes'] ?? []))->filter(fn ($item) => is_array($item) && is_string($item['id'] ?? null))->keyBy('id');

        if ($items->isEmpty()) {
            return;
        }

        // A server reports its own volumes (shared paths: those of its sites, measured on their leader).
        Volume::query()->where('organization_id', $organizationId)->whereIn('id', $items->keys()->all())
            ->where(fn ($q) => $q->where('server_id', $serverId)->orWhere(fn ($q) => $q->whereNull('server_id')->where('kind', VolumeKind::SharedPath)))
            ->get()
            ->each(function (Volume $volume) use ($items) {
                $item = $items->get($volume->id);

                if (($item['exists'] ?? false) !== true) {
                    $volume->forceFill(['status_message' => is_string($item['error'] ?? null) ? mb_substr($item['error'], 0, 1000) : 'Not found on its server.', 'used_at' => now()])->save();

                    return;
                }

                $before = $volume->usage();
                $volume->forceFill([
                    'used_bytes' => isset($item['used_bytes']) ? (int) $item['used_bytes'] : $volume->used_bytes,
                    'used_at' => now(),
                    'status_message' => is_string($item['error'] ?? null) ? mb_substr($item['error'], 0, 1000) : null,
                ])->save();
                $after = $volume->usage();

                if ($after !== null && $after > VolumeAlmostFull::THRESHOLD && ($before === null || $before <= VolumeAlmostFull::THRESHOLD)) {
                    VolumeAlmostFull::dispatch($volume->id, $volume->organization_id, $volume->server_id, $volume->name, (int) $volume->used_bytes, (int) $volume->size_limit_bytes);
                } elseif ($before !== null && $before > VolumeAlmostFull::RESOLVE_BELOW && ($after === null || $after <= VolumeAlmostFull::RESOLVE_BELOW)) {
                    VolumeSpaceRecovered::dispatch($volume->id, $volume->organization_id, $volume->name, (int) $volume->used_bytes, max(1, (int) $volume->size_limit_bytes));
                }
            });
    }

    private function operation(string $commandId): ?Operation
    {
        return Operation::query()->with('volume')->where('command_id', $commandId)->where('status', OperationStatus::Running)->first();
    }
}
