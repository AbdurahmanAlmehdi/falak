<?php

namespace Falak\Sites\Contracts;

use Falak\Sites\Contracts\Data\EnvironmentData;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SiteTargetData;

/**
 * Read-only site lookups for other modules (Edge, Builds, Deployments, Processes, Telemetry, Insights).
 */
interface SiteDirectory
{
    public function find(string $siteId): ?SiteData;

    /**
     * @return list<SiteData>
     */
    public function forOrganization(string $organizationId): array;

    /**
     * Sites with a target on the server.
     *
     * @return list<SiteData>
     */
    public function forServer(string $serverId): array;

    /**
     * Sites deploying from a repository branch (push-to-deploy lookups).
     *
     * @return list<SiteData>
     */
    public function forRepository(string $connectionId, string $repository, ?string $branch = null): array;

    /**
     * @return list<SiteTargetData>
     */
    public function targets(string $siteId): array;

    /**
     * Several sites at once (one query), by id; unknown ids are left out.
     *
     * @param  list<string>  $siteIds
     * @return array<string, SiteData>
     */
    public function findMany(array $siteIds): array;

    /**
     * Run $callback with find() answers remembered (a config plan reads the same sites again and again). Only for
     * code that writes no site meanwhile.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remembering(callable $callback): mixed;

    /** The target that runs migrations / the scheduler. */
    public function leader(string $siteId): ?SiteTargetData;

    /**
     * Environment for a release: the given version, or the latest when null. Null when the site has none.
     */
    public function environment(string $siteId, ?int $version = null): ?EnvironmentData;

    /**
     * Deploy script variables (FALAK_*) for a deployment; merged with exposed environment variables.
     *
     * @param  array<string, string>  $context  e.g. FALAK_COMMIT, FALAK_RELEASE_DIR, FALAK_DEPLOYMENT_ID supplied by Deployments
     * @return array<string, string>
     */
    public function deployVariables(string $siteId, string $serverId, array $context = []): array;
}
