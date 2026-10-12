<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\StorageProviderData;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;

/**
 * The organization's backup storage providers (Settings → Backup storage) for other modules' backups (Volumes): object
 * keys under a provider's prefix and presigned URLs, so servers upload and download without storage credentials.
 * Every call takes the organization, and a provider of another organization is unknown.
 */
interface BackupStorage
{
    /**
     * @return list<StorageProviderData> by name
     */
    public function providers(string $organizationId): array;

    public function find(string $organizationId, string $providerId): ?StorageProviderData;

    /**
     * Names of the providers a project's falak.yaml may name (`backups.storage`, `pitr.storage`, volume backups): those
     * the project's databases and volumes (any environment) already back up to, plus $allowed (the names an admin listed
     * on the project's config source). Never the rest of the organization's.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function namesForProject(string $organizationId, string $projectId, array $allowed = []): array;

    /**
     * An object key under the provider's prefix.
     *
     * @throws StorageUnavailable when the provider is unknown
     */
    public function key(string $organizationId, string $providerId, string ...$segments): string;

    /**
     * @throws StorageUnavailable when the provider is unknown
     */
    public function presignPut(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string;

    /**
     * @throws StorageUnavailable when the provider is unknown
     */
    public function presignGet(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string;

    /**
     * Delete an object (a signed DELETE from the control plane). A missing object counts as deleted.
     *
     * @throws StorageUnavailable when the provider is unknown or the request failed
     */
    public function delete(string $organizationId, string $providerId, string $key): void;
}
