<?php

namespace Falak\Projects\Infrastructure;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;

final class EloquentProjectDirectory implements ProjectDirectory
{
    public function find(string $projectId): ?ProjectData
    {
        return Project::query()->find(strtolower($projectId))?->toData();
    }

    public function forOrganization(string $organizationId): array
    {
        return Project::query()->where('organization_id', $organizationId)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (Project $project) => $project->toData())->values()->all();
    }

    public function environment(string $environmentId): ?EnvironmentData
    {
        return Environment::query()->find(strtolower($environmentId))?->toData();
    }

    public function environments(string $projectId): array
    {
        return Environment::query()->where('project_id', strtolower($projectId))->orderByDesc('is_production')->orderBy('name')->get()
            ->map(fn (Environment $environment) => $environment->toData())->values()->all();
    }

    public function defaultEnvironment(string $organizationId): ?EnvironmentData
    {
        $project = Project::query()->where('organization_id', $organizationId)->where('is_default', true)->with('environments')->orderBy('created_at')->first();

        return $project?->production()?->toData();
    }

    public function projectOf(ServiceKind|string $kind, string $refId): ?ServiceData
    {
        return $this->service($kind, $refId)?->toData();
    }

    public function findService(string $serviceId): ?ServiceData
    {
        return Service::query()->find(strtolower($serviceId))?->toData();
    }

    public function servicesIn(string $environmentId): array
    {
        return Service::query()->where('environment_id', strtolower($environmentId))->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Service $service) => $service->toData())->values()->all();
    }

    public function servicesInProject(string $projectId): array
    {
        return Service::query()->where('project_id', strtolower($projectId))->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Service $service) => $service->toData())->values()->all();
    }

    public function serviceUrl(ServiceKind|string $kind, string $refId, ?string $tab = null): ?string
    {
        $service = $this->service($kind, $refId);

        if ($service === null) {
            return null;
        }

        $environment = $service->environment;
        $url = "/projects/{$service->project_id}/{$environment->slug}/service/{$service->kind->value}/{$service->ref_id}";

        return $tab !== null && $tab !== '' ? $url.'/'.rawurlencode($tab) : $url;
    }

    private function service(ServiceKind|string $kind, string $refId): ?Service
    {
        $kind = $kind instanceof ServiceKind ? $kind : ServiceKind::tryFrom($kind);

        return $kind === null ? null : Service::query()->with('environment')->where('kind', $kind)->where('ref_id', strtolower($refId))->first();
    }
}
