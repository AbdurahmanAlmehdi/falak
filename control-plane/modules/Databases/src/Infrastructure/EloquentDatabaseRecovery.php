<?php

namespace Falak\Databases\Infrastructure;

use DateTimeImmutable;
use Falak\Databases\Application\Actions\ConfigurePitr;
use Falak\Databases\Application\Actions\DecidePitrRestore;
use Falak\Databases\Application\Actions\RelocateInstance;
use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Application\Actions\RestoreToTime;
use Falak\Databases\Application\Actions\RunBackup;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class EloquentDatabaseRecovery implements DatabaseRecovery
{
    public function __construct(
        private readonly RelocateInstance $relocate,
        private readonly RestoreBackup $restore,
        private readonly RestoreToTime $restoreToTime,
        private readonly DecidePitrRestore $decide,
        private readonly ConfigurePitr $configurePitr,
        private readonly PitrTimeline $timeline,
        private readonly RunBackup $backup,
        private readonly AgentCommands $commands,
    ) {}

    public function instancesOn(string $serverId): array
    {
        $instances = DatabaseInstance::query()->with('databases')->where('server_id', $serverId)
            ->whereNotIn('status', [InstanceStatus::Retired, InstanceStatus::Deleting])->orderBy('name')->get();
        $points = $this->points($instances->flatMap(fn (DatabaseInstance $instance) => $instance->databases->modelKeys())->values()->all());

        return $instances->map(fn (DatabaseInstance $instance) => new InstanceRecoveryPoint(
            $instance->id,
            $instance->organization_id,
            $instance->server_id,
            $instance->name,
            $instance->engine->value,
            $instance->version,
            $instance->environment_id,
            $instance->pitr_enabled,
            array_values(array_filter(array_map(fn (string $id) => $points[$id] ?? null, $instance->databases->modelKeys()))),
        ))->values()->all();
    }

    public function points(array $databaseIds): array
    {
        if ($databaseIds === []) {
            return [];
        }

        $databases = Database::query()->with('instance')->whereIn('id', $databaseIds)->get();
        $instanceIds = $databases->pluck('database_instance_id')->unique()->values()->all();
        $scheduled = BackupSchedule::query()->whereIn('database_instance_id', $instanceIds)->where('enabled', true)->pluck('database_instance_id')->flip();
        $backups = Backup::query()->where('status', BackupStatus::Succeeded)
            ->where(fn ($q) => $q->whereIn('database_id', $databases->modelKeys())->orWhereIn('database_instance_id', $instanceIds))
            ->orderByDesc('finished_at')->get();

        $points = [];
        $pitr = [];

        foreach ($databases->pluck('instance')->filter()->unique('id') as $instance) {
            /** @var DatabaseInstance $instance */
            if (! $instance->pitr_enabled || ! $instance->supportsPitr()) {
                continue;
            }

            $plan = $this->timeline->plan($instance, null);

            if ($plan !== null) {
                $pitr[$instance->id] = [
                    'to' => $plan['to']->toDateTimeImmutable(),
                    'customer' => $plan['base']->isCustomerHeld() || collect($plan['segments'])->contains(fn (PitrSegment $segment) => $segment->isCustomerHeld()),
                ];
            }
        }

        foreach ($databases as $database) {
            /** @var Database $database */
            $own = $backups->filter(fn (Backup $backup) => $backup->database_id === $database->id
                || ($backup->database_id === null && $backup->database_instance_id === $database->database_instance_id && $backup->database_name === $database->name));
            $latest = $own->first(fn (Backup $backup) => $backup->isRestorable());
            $drilled = $own->max(fn (Backup $backup) => $backup->verified_at?->getTimestamp());
            $instance = $database->instance;

            $points[$database->id] = new DatabaseRecoveryPoint(
                $database->id,
                $database->organization_id,
                $instance->id,
                $instance->name,
                $database->name,
                $instance->engine->value,
                $database->site_id,
                $latest?->id,
                self::time($latest?->finished_at ?? $latest?->created_at),
                (bool) $latest?->isCustomerHeld(),
                $drilled !== null ? (new DateTimeImmutable)->setTimestamp((int) $drilled) : null,
                $scheduled->has($instance->id),
                $instance->pitr_enabled,
                ! $instance->engine->isKeyValue(),
                $pitr[$instance->id]['to'] ?? null,
                $pitr[$instance->id]['customer'] ?? false,
            );
        }

        return $points;
    }

    public function relocate(string $instanceId, string $targetServerId, ?string $actorId = null, bool $suspendPitr = false): void
    {
        ($this->relocate)($this->instance($instanceId), $targetServerId, $actorId, $suspendPitr);
    }

    public function restoreToLatest(string $instanceId, ?string $actorId = null): string
    {
        return ($this->restoreToTime)($this->instance($instanceId), null, $actorId)->id;
    }

    public function pitrProgress(string $restoreId, ?string $actorId = null, bool $retry = false): array
    {
        $restore = Restore::query()->find($restoreId);

        if ($restore === null) {
            return ['state' => 'failed', 'message' => 'The restore was deleted.'];
        }

        switch ($restore->status) {
            case RestoreStatus::AwaitingDecision:
                if (! $retry && $restore->error !== null && $restore->decision === null && $restore->decided_at !== null) {
                    return ['state' => 'failed', 'message' => "Swapping the restored copy in failed: {$restore->error}"];
                }

                ($this->decide)($restore, 'swap', $actorId);

                return ['state' => 'running', 'message' => 'Swapping the restored copy in.'];
            case RestoreStatus::Succeeded:
                $copy = DatabaseInstance::query()->find($restore->restored_instance_id);

                // Its history starts again here (a base of its own); shipping was off on the empty placeholder.
                if ($copy !== null && ! $copy->pitr_enabled && $copy->pitr_storage_provider_id !== null) {
                    ($this->configurePitr)($copy, ['enabled' => true], $actorId);
                }

                return ['state' => 'succeeded', 'message' => 'Restored to '.$restore->target_time?->toIso8601ZuluString().' (the latest point); the empty placeholder is retired, delete it once you checked.'];
            case RestoreStatus::Failed:
            case RestoreStatus::Discarded:
                return ['state' => 'failed', 'message' => $restore->error ?: 'The point-in-time restore failed.'];
            default:
                return ['state' => 'running', 'message' => 'Replaying the shipped log onto the newest base.'];
        }
    }

    public function restoreLatest(string $instanceId, ?string $actorId = null): array
    {
        $instance = $this->instance($instanceId);
        $points = $this->points($instance->databases()->pluck('id')->all());
        $restores = [];
        $skipped = [];

        foreach ($instance->databases()->get() as $database) {
            $point = $points[$database->id] ?? null;

            if ($point === null || $point->backupId === null) {
                $skipped[] = "{$database->name}: no restorable backup (it starts empty)";

                continue;
            }

            if ($point->customerHeld) {
                $skipped[] = "{$database->name}: its backups use your own key; restore the latest one on the Databases page with your age identity";

                continue;
            }

            $backup = Backup::query()->with('storageProvider')->findOrFail($point->backupId);
            $restores[] = ($this->restore)($backup, $instance, $database->name, $actorId)->id;
        }

        return ['restores' => $restores, 'skipped' => $skipped];
    }

    public function progress(string $instanceId, array $restoreIds = []): array
    {
        $instance = DatabaseInstance::query()->find($instanceId);

        if ($instance === null) {
            return ['state' => 'failed', 'message' => 'The database was deleted.'];
        }

        if ($instance->status === InstanceStatus::Failed) {
            return ['state' => 'failed', 'message' => $instance->status_message ?: 'The container could not be created.'];
        }

        if ($restoreIds === []) {
            $databases = $instance->databases()->get();
            $failed = $databases->first(fn (Database $database) => $database->status === ResourceStatus::Failed);

            return match (true) {
                $failed !== null => ['state' => 'failed', 'message' => "Creating {$failed->name} failed: {$failed->status_message}"],
                $instance->status === InstanceStatus::Active && $databases->every(fn (Database $database) => $database->status === ResourceStatus::Active) => ['state' => 'ready', 'message' => null],
                default => ['state' => 'pending', 'message' => $instance->status_message],
            };
        }

        /** @var Collection<int, Restore> $restores */
        $restores = Restore::query()->whereIn('id', $restoreIds)->get();
        $failed = $restores->first(fn (Restore $restore) => $restore->status === RestoreStatus::Failed);

        return match (true) {
            $failed !== null => ['state' => 'failed', 'message' => "Restoring {$failed->database_name} failed: {$failed->error}"],
            $restores->count() === count($restoreIds) && $restores->every(fn (Restore $restore) => $restore->status === RestoreStatus::Succeeded) => ['state' => 'succeeded', 'message' => null],
            default => ['state' => 'running', 'message' => null],
        };
    }

    public function backUp(string $instanceId, string $storageProviderId, ?string $actorId = null): array
    {
        $instance = $this->instance($instanceId);
        $provider = StorageProvider::query()->where('organization_id', $instance->organization_id)->find($storageProviderId)
            ?? throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);

        return $instance->databases()->get()->map(fn (Database $database) => ($this->backup)($database, $provider, 'manual', null, $actorId)->id)->values()->all();
    }

    public function backupProgress(array $backupIds): array
    {
        /** @var Collection<int, Backup> $backups */
        $backups = Backup::query()->whereIn('id', $backupIds)->get();
        $failed = $backups->first(fn (Backup $backup) => in_array($backup->status, [BackupStatus::Failed, BackupStatus::Pruned], true));

        return match (true) {
            $failed !== null => ['state' => 'failed', 'message' => "Backing up {$failed->database_name} failed: ".($failed->error ?: $failed->status->value)],
            $backups->count() === count($backupIds) && $backups->every(fn (Backup $backup) => $backup->status === BackupStatus::Succeeded) => ['state' => 'succeeded', 'message' => null],
            default => ['state' => 'running', 'message' => 'Backing up.'],
        };
    }

    public function restoreBackups(string $instanceId, array $backupIds, ?string $actorId = null): array
    {
        $instance = $this->instance($instanceId);
        $backups = Backup::query()->with('storageProvider')->where('organization_id', $instance->organization_id)->whereIn('id', $backupIds)->get()->keyBy('id');
        $restores = [];
        $skipped = [];

        foreach ($backupIds as $backupId) {
            /** @var ?Backup $backup */
            $backup = $backups->get($backupId);

            if ($backup === null || ! $backup->isRestorable() || $backup->isCustomerHeld()) {
                $skipped[] = ($backup?->database_name ?? $backupId).': its backup from this move is gone or can\'t be restored here';

                continue;
            }

            $restores[] = ($this->restore)($backup, $instance, $backup->database_name, $actorId)->id;
        }

        return ['restores' => $restores, 'skipped' => $skipped];
    }

    public function removeLeftover(string $instanceId, string $serverId): bool
    {
        // HandleCommandOutcome finds the (moved, active) instance by the key and leaves it alone.
        return $this->commands->tryDispatch($serverId, 'db.instance.delete', ['id' => $instanceId], (int) config('databases.timeouts.ddl', 300), "db.instance.delete:{$instanceId}:moved") !== null;
    }

    private function instance(string $instanceId): DatabaseInstance
    {
        return DatabaseInstance::query()->find($instanceId) ?? throw ValidationException::withMessages(['database_instance_id' => 'Unknown database.']);
    }

    private static function time(?Carbon $at): ?DateTimeImmutable
    {
        return $at?->toDateTimeImmutable();
    }
}
