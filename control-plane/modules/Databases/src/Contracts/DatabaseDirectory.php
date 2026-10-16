<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\Data\DatabaseInstanceData;

/**
 * Read-only database lookups for other modules (e.g. Sites showing a site's databases).
 */
interface DatabaseDirectory
{
    /** What downloading a database backup needs (Sandboxes' database copies need the same: they hand out the data). */
    public const BACKUP_DOWNLOAD_PERMISSION = 'databases.restore';

    public function find(string $databaseId): ?DatabaseData;

    /**
     * @param  list<string>  $databaseIds
     * @return array<string, DatabaseData> keyed by id (unknown ids are omitted)
     */
    public function findMany(array $databaseIds): array;

    /**
     * @return list<DatabaseData>
     */
    public function forOrganization(string $organizationId): array;

    /**
     * @return list<DatabaseData>
     */
    public function forServer(string $serverId): array;

    /**
     * Databases linked to a site (opaque Sites ULID).
     *
     * @return list<DatabaseData>
     */
    public function forSite(string $organizationId, string $siteId): array;

    /** A database container by id. */
    public function instance(string $instanceId): ?DatabaseInstanceData;

    /**
     * The organization's database containers (retired ones excluded), by name.
     *
     * @return list<DatabaseInstanceData>
     */
    public function instancesForOrganization(string $organizationId): array;
}
