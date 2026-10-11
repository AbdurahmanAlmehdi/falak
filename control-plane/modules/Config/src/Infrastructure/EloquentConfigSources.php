<?php

namespace Falak\Config\Infrastructure;

use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteDirectory;

final class EloquentConfigSources implements ConfigSources
{
    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
    ) {}

    public function enabledForProject(string $projectId): bool
    {
        return ConfigSource::enabledFor($projectId) !== null;
    }

    /**
     * **[decision]** Config deploys a site on push only when the site is a unit of the file last applied to its
     * environment, deploys from the source's repository, and that file binds the site's branch to the environment.
     * Every other site of the project keeps push to deploy.
     */
    public function managesSite(string $siteId): bool
    {
        $service = $this->projects->projectOf(ServiceKind::Site, $siteId);
        $source = $service !== null ? ConfigSource::enabledFor($service->projectId) : null;
        $site = $this->sites->find($siteId);

        if ($service === null || $source === null || $site === null || strcasecmp((string) $site->repository, $source->repository) !== 0
            || $site->sourceConnectionId !== $source->source_connection_id) {
            return false;
        }

        $unit = ConfigOwnership::query()->where('resource_type', 'site')->where('resource_id', $siteId)->where('environment_id', $service->environmentId)->value('unit');
        $applied = ConfigRun::query()->where('environment_id', $service->environmentId)->where('status', RunStatus::Applied)->whereNotNull('resolved')
            ->latest('finished_at')->orderByDesc('id')->first();

        if ($unit === null || $applied === null || ! isset($applied->resolved['units'][$unit])) {
            return false;
        }

        $environment = $this->projects->environment($service->environmentId);
        $branch = $environment !== null ? ($applied->resolved['environments'][$environment->slug]['branch'] ?? null) : null;

        return $branch !== null && $branch === $site->branch;
    }
}
