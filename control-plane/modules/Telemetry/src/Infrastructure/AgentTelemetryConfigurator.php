<?php

namespace Falak\Telemetry\Infrastructure;

use Falak\Deployments\Contracts\LiveReleases;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Telemetry\Application\Listeners\ReconfigureOnReleaseActivated;
use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\TelemetrySettings;
use Illuminate\Support\Str;

final class AgentTelemetryConfigurator implements TelemetryConfigurator
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly ServerSites $sites,
        private readonly LiveReleases $releases,
    ) {}

    public function reconfigure(string $serverId): ?string
    {
        $server = $this->servers->find($serverId);

        if (! $server) {
            return null;
        }

        $payload = TelemetryPayload::build(
            $server->organizationId,
            $server->id,
            TelemetrySettings::for($server->organizationId),
            $this->withLiveReleases($server->id, $this->sites->forServer($server->id)),
        );

        try {
            // One logical operation per call: the agent answers repeated keys from its result cache.
            return $this->agents->dispatch($server->id, 'telemetry.configure', $payload, 120, "telemetry.configure:{$server->id}:".Str::ulid())->id;
        } catch (AgentUnavailable) {
            return null;
        }
    }

    /**
     * The release each site runs on the server: the agent labels the site's records with its deployment and
     * release ids (Loki structured metadata falak_deployment_id / falak_release_id), so logs can be filtered per
     * deployment. Re-sent on every activation ({@see ReconfigureOnReleaseActivated}).
     *
     * @param  list<SiteTelemetryTarget>  $targets
     * @return list<SiteTelemetryTarget>
     */
    private function withLiveReleases(string $serverId, array $targets): array
    {
        $live = $this->releases->onServer($serverId);

        return array_map(function (SiteTelemetryTarget $target) use ($live) {
            $release = $live[strtolower($target->siteId)] ?? null;

            if ($release === null || $target->deploymentId !== null || $target->releaseId !== null) {
                return $target;
            }

            return new SiteTelemetryTarget($target->siteId, $target->slug, $target->environment, $release->deploymentId, $release->releaseId, $target->logSources, $target->unixUser, $target->runtime);
        }, $targets);
    }

    public function reconfigureOrganization(string $organizationId): int
    {
        $count = 0;

        foreach ($this->servers->forOrganization($organizationId, activeOnly: true) as $server) {
            if ($this->reconfigure($server->id) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
