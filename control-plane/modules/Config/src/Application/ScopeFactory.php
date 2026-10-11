<?php

namespace Falak\Config\Application;

use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Builds the {@see ConfigScope} of an environment: its canvas services by unit key, the servers attached to the
 * project (**[decision]** a server is attached to a project when it hosts one of the project's sites, in any
 * environment: what the project "already has"), and the environment's defaults from the resolved file.
 */
final class ScopeFactory
{
    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * @param  ?array<string, mixed>  $resolved
     */
    public function make(EnvironmentData $environment, ?ConfigSource $source, ?array $resolved = null, ?string $commit = null, ?string $actorId = null): ConfigScope
    {
        $services = [];

        foreach ($this->projects->servicesIn($environment->id) as $service) {
            $services[$service->name] = ['kind' => $service->kind->value, 'ref_id' => $service->refId];
        }

        $servers = $this->attachedServers($environment->projectId);
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
        );
    }

    /** The same scope with today's canvas services (after phase A created units). */
    public function refresh(ConfigScope $scope): ConfigScope
    {
        $services = [];

        foreach ($this->projects->servicesIn($scope->environmentId) as $service) {
            $services[$service->name] = ['kind' => $service->kind->value, 'ref_id' => $service->refId];
        }

        return new ConfigScope($scope->organizationId, $scope->projectId, $scope->environmentId, $scope->environment, $scope->production,
            $scope->commit, $services, $scope->servers, $scope->defaultServers, $scope->connectionId, $scope->repository, $scope->branch, $scope->actorId);
    }

    /**
     * @return array<string, string> name => id
     */
    public function attachedServers(string $projectId): array
    {
        $ids = [];

        foreach ($this->projects->environments($projectId) as $environment) {
            foreach ($this->projects->servicesIn($environment->id) as $service) {
                if ($service->kind !== ServiceKind::Site) {
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
