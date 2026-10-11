<?php

namespace Falak\Config\Infrastructure;

use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;

final class EloquentConfigSources implements ConfigSources
{
    public function __construct(private readonly ProjectDirectory $projects) {}

    public function enabledForProject(string $projectId): bool
    {
        return ConfigSource::enabledFor($projectId) !== null;
    }

    public function managesSite(string $siteId): bool
    {
        $service = $this->projects->projectOf(ServiceKind::Site, $siteId);

        return $service !== null && $this->enabledForProject($service->projectId);
    }
}
