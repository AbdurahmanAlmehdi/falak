<?php

namespace Falak\Projects\Contracts;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\Data\ServiceData;

/**
 * Read-only lookups of projects, environments and the services placed in them.
 */
interface ProjectDirectory
{
    public function find(string $projectId): ?ProjectData;

    /**
     * @return list<ProjectData> default project first, then by name
     */
    public function forOrganization(string $organizationId): array;

    public function environment(string $environmentId): ?EnvironmentData;

    /**
     * @return list<EnvironmentData> production first, then by name
     */
    public function environments(string $projectId): array;

    /** The production environment of the organization's default project (null before it exists). */
    public function defaultEnvironment(string $organizationId): ?EnvironmentData;

    /** The service (with its project and environment) a site / database is placed in. */
    public function projectOf(ServiceKind|string $kind, string $refId): ?ServiceData;

    /** A service by its own id (not the site / database id). */
    public function findService(string $serviceId): ?ServiceData;

    /**
     * @return list<ServiceData>
     */
    public function servicesIn(string $environmentId): array;

    /**
     * Every canvas service of a project, all environments (one query).
     *
     * @return list<ServiceData>
     */
    public function servicesInProject(string $projectId): array;

    /**
     * Canvas URL of a service's panel: /projects/{project}/{environment}/service/{kind}/{refId}[/{tab}].
     * Null when the site / database is not placed in any environment.
     */
    public function serviceUrl(ServiceKind|string $kind, string $refId, ?string $tab = null): ?string;
}
