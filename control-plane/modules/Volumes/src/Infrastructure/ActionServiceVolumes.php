<?php

namespace Falak\Volumes\Infrastructure;

use Falak\Volumes\Application\Actions\CreateVolume;
use Falak\Volumes\Application\Actions\DeleteVolume;
use Falak\Volumes\Application\Actions\ReleaseSite;
use Falak\Volumes\Application\Actions\ResizeVolume;
use Falak\Volumes\Application\Actions\SyncComposeVolumes;
use Falak\Volumes\Application\Actions\SyncSharedPaths;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\Data\AttachmentData;
use Falak\Volumes\Contracts\Data\VolumeData;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

final class ActionServiceVolumes implements ServiceVolumes
{
    public function __construct(
        private readonly SyncSharedPaths $sharedPaths,
        private readonly SyncComposeVolumes $compose,
        private readonly ReleaseSite $release,
        private readonly CreateVolume $create,
        private readonly DeleteVolume $delete,
    ) {}

    public function forSite(string $siteId): array
    {
        return $this->forSites([$siteId])[strtolower($siteId)] ?? [];
    }

    public function forSites(array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_map('strtolower', $siteIds)));

        if ($siteIds === []) {
            return [];
        }

        $volumes = Volume::query()
            ->with('attachments')
            ->whereHas('attachments', fn ($q) => $q->whereIn('attachable_type', [AttachableType::Site, AttachableType::ComposeService])->whereIn('attachable_id', $siteIds))
            ->orderBy('name')
            ->get();

        $bySite = [];

        foreach ($volumes as $volume) {
            $data = self::data($volume);

            foreach ($volume->attachments->pluck('attachable_id')->unique() as $siteId) {
                if (in_array($siteId, $siteIds, true)) {
                    $bySite[$siteId][] = $data;
                }
            }
        }

        return $bySite;
    }

    public function syncSharedPaths(string $organizationId, string $siteId, array $paths, ?string $actorId = null): void
    {
        ($this->sharedPaths)($organizationId, strtolower($siteId), $paths, $actorId);
    }

    public function composeDeployed(string $organizationId, string $siteId, string $serverId, string $project, string $yaml): void
    {
        ($this->compose)($organizationId, strtolower($siteId), $serverId, $project, $yaml);
    }

    public function releaseSite(string $siteId, array $deleteVolumeIds = [], ?string $actorId = null): void
    {
        ($this->release)(strtolower($siteId), $deleteVolumeIds, $actorId);
    }

    public function createSized(string $organizationId, string $serverId, string $name, int $sizeBytes, array $labels = [], bool $protected = false, ?string $actorId = null): VolumeData
    {
        return self::data(($this->create)($organizationId, $serverId, $name, VolumeKind::Sized, $sizeBytes, null, $labels, $protected, $actorId));
    }

    public function attach(string $volumeId, AttachableType $type, string $attachableId, string $mountPath, bool $readOnly = false, ?string $service = null): void
    {
        $volume = Volume::query()->find(strtolower($volumeId)) ?? throw ValidationException::withMessages(['volume' => 'The volume does not exist.']);

        if ($volume->status === VolumeStatus::Deleting) {
            throw ValidationException::withMessages(['volume' => 'The volume is being deleted.']);
        }

        $volume->attachments()->firstOrCreate(
            ['attachable_type' => $type, 'attachable_id' => strtolower($attachableId), 'service' => $service, 'mount_path' => $mountPath],
            ['read_only' => $readOnly],
        );
    }

    public function find(string $volumeId): ?VolumeData
    {
        $volume = Volume::query()->with('attachments')->find(strtolower($volumeId));

        return $volume !== null ? self::data($volume) : null;
    }

    public function releaseDatabase(string $databaseId): void
    {
        Attachment::query()->where('attachable_type', AttachableType::Database)->where('attachable_id', strtolower($databaseId))->delete();
    }

    public function deleteDatabaseVolume(string $volumeId, ?string $actorId = null): void
    {
        $volume = Volume::query()->find(strtolower($volumeId));

        if ($volume === null) {
            return;
        }

        $volume->attachments()->where('attachable_type', AttachableType::Database)->delete();
        $volume->forceFill(['protected' => false])->save();
        try {
            // The container was just removed; volume.delete waits for anything still holding the mount.
            ($this->delete)($volume, $actorId, 120, background: true);
        } catch (ValidationException $e) {
            // Busy (a backup or move of it is running): it stays, unprotected, for the Volumes page.
            $volume->forceFill(['status_message' => 'Not deleted: '.collect($e->errors())->flatten()->first()])->save();
        }
    }

    public function resize(string $volumeId, int $sizeBytes, ?string $actorId = null): void
    {
        $volume = Volume::query()->find(strtolower($volumeId)) ?? throw ValidationException::withMessages(['volume' => 'The volume does not exist.']);

        app(ResizeVolume::class)($volume, $sizeBytes, $actorId);
    }

    public function backupStorageFor(array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_map('strtolower', $siteIds)));

        if ($siteIds === []) {
            return [];
        }

        return BackupSchedule::query()
            ->whereHas('volume.attachments', fn ($q) => $q->whereIn('attachable_type', [AttachableType::Site, AttachableType::ComposeService])->whereIn('attachable_id', $siteIds))
            ->whereNotNull('storage_provider_id')
            ->pluck('storage_provider_id')->unique()->map(fn ($id) => (string) $id)->values()->all();
    }

    public static function data(Volume $volume): VolumeData
    {
        return new VolumeData(
            $volume->id,
            $volume->organization_id,
            $volume->server_id,
            $volume->name,
            $volume->kind,
            $volume->docker_name,
            $volume->host_path,
            $volume->size_limit_bytes,
            $volume->used_bytes,
            $volume->protected,
            $volume->status->value,
            $volume->attachments->map(fn (Attachment $attachment) => new AttachmentData(
                $attachment->id,
                $attachment->volume_id,
                $attachment->attachable_type,
                $attachment->attachable_id,
                $attachment->service,
                $attachment->mount_path,
                $attachment->read_only,
            ))->values()->all(),
            $volume->kind === VolumeKind::SharedPath && $volume->sharedFile(),
        );
    }
}
