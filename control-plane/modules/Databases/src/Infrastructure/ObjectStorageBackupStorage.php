<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Data\StorageProviderData;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStore;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Volumes\Contracts\ServiceVolumes;

final class ObjectStorageBackupStorage implements BackupStorage
{
    public function __construct(
        private readonly ObjectStores $stores,
        private readonly ProjectDirectory $projects,
    ) {}

    public function providers(string $organizationId): array
    {
        return StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (StorageProvider $provider) => self::data($provider))->values()->all();
    }

    public function find(string $organizationId, string $providerId): ?StorageProviderData
    {
        $provider = $this->provider($organizationId, $providerId);

        return $provider !== null ? self::data($provider) : null;
    }

    public function namesForProject(string $organizationId, string $projectId, array $allowed = []): array
    {
        $databaseIds = [];
        $siteIds = [];

        foreach ($this->projects->environments($projectId) as $environment) {
            foreach ($this->projects->servicesIn($environment->id) as $service) {
                if ($service->kind === ServiceKind::Database) {
                    $databaseIds[] = $service->refId;
                } else {
                    $siteIds[] = $service->refId;
                }
            }
        }

        $instanceIds = Database::query()->where('organization_id', $organizationId)->whereIn('id', $databaseIds ?: [''])
            ->pluck('database_instance_id')->unique()->values()->all();
        $ids = [
            ...BackupSchedule::query()->whereIn('database_instance_id', $instanceIds ?: [''])->pluck('storage_provider_id')->all(),
            ...DatabaseInstance::query()->whereIn('id', $instanceIds ?: [''])->whereNotNull('pitr_storage_provider_id')->pluck('pitr_storage_provider_id')->all(),
            // Resolved here: Volumes' actions depend on this contract.
            ...app(ServiceVolumes::class)->backupStorageFor($siteIds),
        ];

        return StorageProvider::query()->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->whereIn('id', array_values(array_filter($ids)) ?: [''])->orWhereIn('name', $allowed ?: ['']))
            ->orderBy('name')->pluck('name')->map(fn ($name) => (string) $name)->values()->all();
    }

    public function key(string $organizationId, string $providerId, string ...$segments): string
    {
        return $this->store($organizationId, $providerId)->key(...$segments);
    }

    public function presignPut(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string
    {
        return $this->store($organizationId, $providerId)->presignPut($key, $ttlSeconds ?? (int) config('databases.upload_url_ttl', 43200));
    }

    public function presignGet(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string
    {
        return $this->store($organizationId, $providerId)->presignGet($key, $ttlSeconds ?? (int) config('databases.download_url_ttl', 21600));
    }

    public function delete(string $organizationId, string $providerId, string $key): void
    {
        try {
            $this->store($organizationId, $providerId)->delete($key);
        } catch (StorageRequestFailed $e) {
            throw new StorageUnavailable($e->getMessage(), 0, $e);
        }
    }

    private function store(string $organizationId, string $providerId): ObjectStore
    {
        return $this->stores->for($this->provider($organizationId, $providerId) ?? throw StorageUnavailable::unknown($providerId));
    }

    private function provider(string $organizationId, string $providerId): ?StorageProvider
    {
        return StorageProvider::query()->where('organization_id', $organizationId)->find(strtolower($providerId));
    }

    private static function data(StorageProvider $provider): StorageProviderData
    {
        return new StorageProviderData($provider->id, $provider->organization_id, $provider->name, $provider->driver->value, $provider->bucket);
    }
}
