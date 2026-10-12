<?php

namespace Falak\Volumes\Contracts;

use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Volumes\Contracts\Data\VolumeData;
use Illuminate\Validation\ValidationException;

/**
 * Volumes of services, for the modules that own the services: Sites (shared paths, deletion), Deployments (compose
 * stacks going live), Projects (the canvas) and Databases (data volumes of database containers).
 */
interface ServiceVolumes
{
    /**
     * Volumes attached to a site or to any service of its compose stack.
     *
     * @return list<VolumeData>
     */
    public function forSite(string $siteId): array;

    /**
     * @param  list<string>  $siteIds
     * @return array<string, list<VolumeData>> keyed by site id (sites without volumes are omitted)
     */
    public function forSites(array $siteIds): array;

    /**
     * Make a classic site's shared paths exactly $paths: a shared_path volume attached to the site per path. Paths no
     * longer listed lose their volume (the files stay on the servers). No redeploy: the next deploy links them.
     *
     * @param  list<SharedPath>  $paths
     */
    public function syncSharedPaths(string $organizationId, string $siteId, array $paths, ?string $actorId = null): void;

    /**
     * A compose stack is going live on a server with this (rendered) file: its named volumes become docker volumes
     * attached to the services that mount them. Volumes the file no longer mounts keep their data but lose the
     * attachment.
     */
    public function composeDeployed(string $organizationId, string $siteId, string $serverId, string $project, string $yaml): void;

    /**
     * The site is being deleted: every attachment of it and its compose services goes, and the volumes in
     * $deleteVolumeIds (attached to it, never protected) are deleted on their servers once its containers are gone.
     *
     * @param  list<string>  $deleteVolumeIds
     *
     * @throws ValidationException when one of $deleteVolumeIds is protected or not the site's
     */
    public function releaseSite(string $siteId, array $deleteVolumeIds = [], ?string $actorId = null): void;

    public function find(string $volumeId): ?VolumeData;

    /**
     * Create a sized volume (an ext4 image with a hard limit) on a server, e.g. a database container's data.
     *
     * @param  array<string, string>  $labels
     *
     * @throws ValidationException
     */
    public function createSized(string $organizationId, string $serverId, string $name, int $sizeBytes, array $labels = [], bool $protected = false, ?string $actorId = null): VolumeData;

    /**
     * Attach a volume without redeploying anything (the caller deploys the service).
     *
     * @throws ValidationException
     */
    public function attach(string $volumeId, AttachableType $type, string $attachableId, string $mountPath, bool $readOnly = false, ?string $service = null): void;

    /**
     * A database container is gone or moved to another volume: every volume attached to the database ($databaseId, the
     * canvas service's database) loses that attachment. The data stays in the (now unattached) volume.
     */
    public function releaseDatabase(string $databaseId): void;

    /**
     * Delete a database container's volume once the container is gone (protection, which Databases sets, is lifted
     * first; remaining attachments go with it). Unknown ids are ignored.
     */
    public function deleteDatabaseVolume(string $volumeId, ?string $actorId = null): void;

    /**
     * Grow a sized volume online (a database container's data volume: falak.yaml's `disk`). Volumes never shrink.
     *
     * @throws ValidationException
     */
    public function resize(string $volumeId, int $sizeBytes, ?string $actorId = null): void;

    /**
     * Storage providers the backup schedules of these sites' volumes already use (a project's falak.yaml may name
     * those, and the ones an admin allowed).
     *
     * @param  list<string>  $siteIds
     * @return list<string> provider ids
     */
    public function backupStorageFor(array $siteIds): array;
}
