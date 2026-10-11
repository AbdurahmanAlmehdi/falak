<?php

namespace Falak\Config\Http\Controllers;

use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Policies\ConfigPolicy;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Projects and config runs of the current organization; anything else is a 404.
 */
trait ResolvesProjects
{
    protected function project(?Authenticatable $user, string $id, string $permission = ConfigPolicy::VIEW): ProjectData
    {
        $organizationId = app(CurrentOrganization::class)->requireId();
        $access = app(OrganizationAccess::class);
        $access->authorize($user, $organizationId, ConfigPolicy::VIEW);
        $project = app(ProjectDirectory::class)->find(strtolower($id));

        abort_if($project === null || $project->organizationId !== $organizationId, 404, 'Project not found.');
        $access->authorize($user, $organizationId, $permission);

        return $project;
    }

    protected function run(?Authenticatable $user, string $id, string $permission = ConfigPolicy::VIEW): ConfigRun
    {
        $organizationId = app(CurrentOrganization::class)->requireId();
        $access = app(OrganizationAccess::class);
        $access->authorize($user, $organizationId, ConfigPolicy::VIEW);
        $run = ConfigRun::query()->where('organization_id', $organizationId)->findOrFail(strtolower($id));
        $access->authorize($user, $organizationId, $permission);

        return $run;
    }

    /** An environment of the project by slug (or id); a 422 on `environment` otherwise. */
    protected function environment(ProjectData $project, ?string $slug): EnvironmentData
    {
        foreach (app(ProjectDirectory::class)->environments($project->id) as $environment) {
            if (! $environment->isPreview && ($environment->slug === $slug || $environment->id === strtolower((string) $slug))) {
                return $environment;
            }
        }

        throw ValidationException::withMessages(['environment' => "The project has no environment \"{$slug}\"."]);
    }

    protected function can(?Authenticatable $user, string $organizationId, string $permission): bool
    {
        return app(OrganizationAccess::class)->can($user, $organizationId, $permission);
    }
}
