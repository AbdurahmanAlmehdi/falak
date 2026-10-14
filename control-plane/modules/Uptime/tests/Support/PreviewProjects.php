<?php

namespace Falak\Uptime\Tests\Support;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;

/** The real directory, except that the listed sites sit in a preview environment. */
final class PreviewProjects implements ProjectDirectory
{
    /**
     * @param  list<string>  $previewSites
     */
    public function __construct(private readonly ProjectDirectory $inner, public array $previewSites = []) {}

    public function find(string $projectId): ?ProjectData
    {
        return $this->inner->find($projectId);
    }

    public function forOrganization(string $organizationId): array
    {
        return $this->inner->forOrganization($organizationId);
    }

    public function environment(string $environmentId): ?EnvironmentData
    {
        return $environmentId === 'preview-env'
            ? new EnvironmentData('preview-env', 'org', 'project', 'pr-1', 'pr-1', false, null, isPreview: true)
            : $this->inner->environment($environmentId);
    }

    public function environments(string $projectId): array
    {
        return $this->inner->environments($projectId);
    }

    public function defaultEnvironment(string $organizationId): ?EnvironmentData
    {
        return $this->inner->defaultEnvironment($organizationId);
    }

    public function projectOf(ServiceKind|string $kind, string $refId): ?ServiceData
    {
        return in_array($refId, $this->previewSites, true)
            ? new ServiceData('svc', 'org', 'project', 'preview-env', ServiceKind::Site, $refId, 'web', 0, 0)
            : $this->inner->projectOf($kind, $refId);
    }

    public function findService(string $serviceId): ?ServiceData
    {
        return $this->inner->findService($serviceId);
    }

    public function servicesIn(string $environmentId): array
    {
        return $this->inner->servicesIn($environmentId);
    }

    public function servicesInProject(string $projectId): array
    {
        return $this->inner->servicesInProject($projectId);
    }

    public function serviceUrl(ServiceKind|string $kind, string $refId, ?string $tab = null): ?string
    {
        return $this->inner->serviceUrl($kind, $refId, $tab);
    }
}
