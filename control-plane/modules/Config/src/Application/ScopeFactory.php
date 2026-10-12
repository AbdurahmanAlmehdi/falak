<?php

namespace Falak\Config\Application;

use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Builds the {@see ConfigScope} of an environment: its canvas services by unit key, the servers attached to the
 * project (**[decision]** a server is attached to a project when it hosts one of the project's sites, in any
 * environment, or one of its databases: what the project "already has"), and the environment's defaults from the
 * resolved file.
 */
final class ScopeFactory
{
    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly DatabaseDirectory $databases,
    ) {}

    /**
     * @param  ?array<string, mixed>  $resolved
     */
    public function make(EnvironmentData $environment, ?ConfigSource $source, ?array $resolved = null, ?string $commit = null, ?string $actorId = null, bool $allowDestroy = false, bool $drift = false): ConfigScope
    {
        $services = $this->services($environment->id);

        $servers = $this->attachedServers($environment->projectId, $environment->isPreview);
        $env = $resolved['environments'][$environment->slug] ?? [];
        $defaults = [];

        foreach ((array) ($env['servers'] ?? []) as $name) {
            if (isset($servers[(string) $name])) {
                $defaults[] = $servers[(string) $name];
            }
        }

        return new ConfigScope(
            organizationId: $environment->organizationId,
            projectId: $environment->projectId,
            environmentId: $environment->id,
            environment: $environment->slug,
            production: $environment->isProduction,
            commit: $commit,
            services: $services,
            servers: $servers,
            defaultServers: $defaults,
            connectionId: $source?->source_connection_id,
            repository: $source?->repository,
            branch: isset($env['branch']) ? (string) $env['branch'] : null,
            actorId: $actorId,
            allowDestroy: $allowDestroy,
            credentials: array_values(array_map('strval', (array) ($source?->allowed_credentials ?? []))),
            drift: $drift,
        );
    }

    /** The same scope with today's canvas services (after phase A created units). */
    public function refresh(ConfigScope $scope): ConfigScope
    {
        return new ConfigScope($scope->organizationId, $scope->projectId, $scope->environmentId, $scope->environment, $scope->production,
            $scope->commit, $this->services($scope->environmentId), $scope->servers, $scope->defaultServers, $scope->connectionId, $scope->repository,
            $scope->branch, $scope->actorId, $scope->allowDestroy, $scope->credentials, $scope->preview, $scope->drift);
    }

    /**
     * Unit key => canvas service. **[decision]** A unit the file manages is mapped by the site (or database container)
     * recorded in config_ownership (resource id), not by the service's name, so renaming the service in the dashboard
     * doesn't turn the next push into a delete and a create; other units map by service name. A database container is
     * the canvas service of one of its databases.
     *
     * @return array<string, array{kind: string, ref_id: string}>
     */
    private function services(string $environmentId): array
    {
        $services = [];
        $byRef = [];

        foreach ($this->projects->servicesIn($environmentId) as $service) {
            $services[$service->name] = ['kind' => $service->kind->value, 'ref_id' => $service->refId];
            $byRef[$service->refId] = $service;
        }

        $byInstance = [];

        foreach ($this->databases->findMany(array_values(array_map(fn ($s) => $s->refId, array_filter($byRef, fn ($s) => $s->kind === ServiceKind::Database)))) as $database) {
            if ($database->instanceId !== null) {
                $byInstance[$database->instanceId] ??= $byRef[$database->id] ?? null;
            }
        }

        foreach (ConfigOwnership::query()->where('environment_id', $environmentId)->whereIn('resource_type', ['site', 'database_instance'])->whereNotNull('unit')
            ->distinct()->get(['resource_type', 'resource_id', 'unit']) as $owned) {
            $service = $owned->resource_type === 'site' ? ($byRef[$owned->resource_id] ?? null) : ($byInstance[$owned->resource_id] ?? null);

            if ($service === null) {
                continue;
            }

            if (isset($services[$service->name]) && $services[$service->name]['ref_id'] === $service->refId && $service->name !== $owned->unit) {
                unset($services[$service->name]);
            }

            $services[(string) $owned->unit] = ['kind' => $service->kind->value, 'ref_id' => $service->refId];
        }

        return $services;
    }

    /**
     * **[decision]** Servers of preview environments (a fork's pull request may pick them) are attached only for a
     * preview's own scope: a production file never names them (e.g. as a backup drill server). A database counts when
     * its container belongs to the project (a database placed here from another project's container doesn't attach
     * that project's server).
     *
     * @return array<string, string> name => id
     */
    public function attachedServers(string $projectId, bool $withPreviews = false): array
    {
        $ids = [];
        $environments = array_values(array_filter($this->projects->environments($projectId), fn (EnvironmentData $e) => $withPreviews || ! $e->isPreview));
        $own = array_map(fn (EnvironmentData $e) => $e->id, $environments);

        foreach ($environments as $environment) {
            foreach ($this->projects->servicesIn($environment->id) as $service) {
                if ($service->kind === ServiceKind::Database) {
                    // [decision] Phase 3: a server running one of the project's databases is attached too.
                    if (($database = $this->databases->find($service->refId)) !== null && in_array($database->environmentId, $own, true)) {
                        $ids[$database->serverId] = true;
                    }

                    continue;
                }

                foreach ($this->sites->targets($service->refId) as $target) {
                    $ids[$target->serverId] = true;
                }
            }
        }

        $out = [];

        foreach (array_keys($ids) as $id) {
            $server = $this->servers->find((string) $id);

            if ($server !== null) {
                $out[$server->name] = $server->id;
            }
        }

        ksort($out);

        return $out;
    }
}
