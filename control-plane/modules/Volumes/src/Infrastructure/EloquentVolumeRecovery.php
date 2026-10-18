<?php

namespace Falak\Volumes\Infrastructure;

use DateTimeImmutable;
use Falak\Volumes\Application\Actions\DeleteVolume;
use Falak\Volumes\Application\Actions\MoveVolume;
use Falak\Volumes\Application\Actions\RestoreVolumeBackup;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\Data\VolumeRecoveryPoint;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Contracts\VolumeRecovery;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class EloquentVolumeRecovery implements VolumeRecovery
{
    public function __construct(
        private readonly RestoreVolumeBackup $restore,
        private readonly MoveVolume $move,
        private readonly DeleteVolume $delete,
    ) {}

    public function volumesOn(string $serverId): array
    {
        return $this->points($this->portable()->where('server_id', $serverId)->get());
    }

    public function forSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $volumeIds = Attachment::query()->where('attachable_type', '!=', AttachableType::Database)->whereIn('attachable_id', $siteIds)->pluck('volume_id')->unique()->all();

        return $this->points($this->portable()->whereIn('id', $volumeIds)->get());
    }

    public function restoreOnto(string $volumeId, string $targetServerId, ?string $actorId = null): string
    {
        $volume = Volume::query()->find($volumeId) ?? throw ValidationException::withMessages(['volume' => 'Unknown volume.']);
        $backup = $this->latest($volume->id);

        if ($backup === null) {
            throw ValidationException::withMessages(['volume' => "{$volume->name} has no restorable backup."]);
        }

        if ($backup->isCustomerHeld()) {
            throw ValidationException::withMessages(['volume' => "{$volume->name}'s backups use your own key: restore the latest one on the Volumes page with your age identity."]);
        }

        return ($this->restore)($backup, $targetServerId, $volume->name, swap: true, actorId: $actorId, relocate: true)->id;
    }

    public function progress(string $operationId): array
    {
        $operation = Operation::query()->find($operationId);

        return match ($operation?->status) {
            null => ['state' => 'failed', 'message' => 'The restore was deleted.'],
            OperationStatus::Succeeded => ['state' => 'succeeded', 'message' => null],
            OperationStatus::Failed => ['state' => 'failed', 'message' => $operation->error],
            default => ['state' => 'running', 'message' => $operation->step !== null ? "Step: {$operation->step}" : null],
        };
    }

    public function move(string $volumeId, string $targetServerId, string $storageProviderId, ?string $actorId = null): string
    {
        $volume = Volume::query()->with('attachments')->find($volumeId) ?? throw ValidationException::withMessages(['volume' => 'Unknown volume.']);

        return ($this->move)($volume, $targetServerId, $storageProviderId, $actorId, compose: true)->id;
    }

    public function finishMove(string $operationId, ?string $actorId = null): ?string
    {
        $operation = Operation::query()->find($operationId);
        $source = $operation?->meta('compose') === true ? Volume::query()->find($operation->meta('source_id')) : null;

        if ($source === null) {
            return null;
        }

        // Its stack no longer runs there: the attachments are stale. An external volume only leaves Falak (DeleteVolume).
        $source->attachments()->delete();
        $source->forceFill(['protected' => false])->save();
        ($this->delete)($source, $actorId, (int) config('volumes.delete_wait_s', 120), background: true);

        return $source->external() ? "{$source->name} is external: Docker keeps it on the old server." : "{$source->name} deleted on the old server.";
    }

    /** Docker and sized volumes that are not a database container's data. @return Builder<Volume> */
    private function portable(): Builder
    {
        return Volume::query()->with('attachments')->whereIn('kind', [VolumeKind::Docker, VolumeKind::Sized])
            ->whereIn('status', [VolumeStatus::Active, VolumeStatus::Pending])
            ->whereNull('labels->db-instance')
            ->whereDoesntHave('attachments', fn ($q) => $q->where('attachable_type', AttachableType::Database))
            ->orderBy('name');
    }

    /**
     * @param  Collection<int, Volume>  $volumes
     * @return list<VolumeRecoveryPoint>
     */
    private function points(Collection $volumes): array
    {
        $ids = $volumes->modelKeys();
        $scheduled = BackupSchedule::query()->whereIn('volume_id', $ids)->where('enabled', true)->pluck('volume_id')->flip();
        $backups = VolumeBackup::query()->whereIn('volume_id', $ids)->where('status', BackupStatus::Succeeded)->orderByDesc('finished_at')->get()->groupBy('volume_id');

        return $volumes->map(function (Volume $volume) use ($scheduled, $backups) {
            /** @var \Illuminate\Support\Collection<int, VolumeBackup> $own */
            $own = $backups->get($volume->id, collect());
            $latest = $own->first(fn (VolumeBackup $backup) => $backup->restorable() && in_array($backup->trigger, ['manual', 'scheduled'], true));
            $drilled = $own->max(fn (VolumeBackup $backup) => $backup->verified_at?->getTimestamp());

            return new VolumeRecoveryPoint(
                $volume->id,
                $volume->organization_id,
                $volume->server_id,
                $volume->name,
                $volume->kind->value,
                $volume->attachments->map(fn (Attachment $attachment) => $attachment->siteId())->filter()->unique()->values()->all(),
                $latest?->id,
                ($latest?->finished_at ?? $latest?->created_at)?->toDateTimeImmutable(),
                (bool) $latest?->isCustomerHeld(),
                $drilled !== null ? (new DateTimeImmutable)->setTimestamp((int) $drilled) : null,
                $scheduled->has($volume->id),
            );
        })->values()->all();
    }

    private function latest(string $volumeId): ?VolumeBackup
    {
        return VolumeBackup::query()->where('volume_id', $volumeId)->where('status', BackupStatus::Succeeded)->whereIn('trigger', ['manual', 'scheduled'])
            ->orderByDesc('finished_at')->get()->first(fn (VolumeBackup $backup) => $backup->restorable());
    }
}
