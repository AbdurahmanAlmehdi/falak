<?php

namespace Falak\Telemetry\Infrastructure;

use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Domain\Models\TelemetrySettings;

/**
 * Builds the telemetry.configure payload. Ids are sent as canonical upper-case ULIDs, so every
 * signal carries upper-case falak.* resource attributes (queries normalize the same way).
 * (contracts/agent-protocol/commands/telemetry.configure.schema.json).
 */
final class TelemetryPayload
{
    /**
     * @param  list<SiteTelemetryTarget>  $sites
     * @return array<string, mixed>
     */
    public static function build(string $organizationId, string $serverId, TelemetrySettings $settings, array $sites): array
    {
        $payload = [
            'resource' => [
                'org_id' => strtoupper($organizationId),
                'server_id' => strtoupper($serverId),
                'environment' => $settings->environmentName(),
            ],
            'sampling' => ['traces_ratio' => max(0.0, min(1.0, $settings->tracesRatio()))],
            'metrics' => ['enabled' => true, 'interval_s' => max(5, $settings->metricsInterval())],
            'insights' => ['enabled' => true],
        ];

        // No endpoint configured (an install without the observability stack sets FALAK_OTLP_ENDPOINT empty): the
        // agent keeps the one it got at enrollment. An empty string is not a URI, so the whole payload was refused.
        if (($endpoint = $settings->endpoint()) !== '') {
            $payload = ['endpoint' => $endpoint, ...$payload];
        }

        if ($token = $settings->token()) {
            $payload['headers'] = ['Authorization' => 'Bearer '.$token];
        }

        $siteEntries = [];
        $logSources = [];

        foreach ($sites as $site) {
            $siteEntries[] = array_filter([
                'slug' => $site->slug,
                'site_id' => strtoupper($site->siteId),
                'environment' => $site->environment,
                'deployment_id' => $site->deploymentId ? strtoupper($site->deploymentId) : null,
                'release_id' => $site->releaseId ? strtoupper($site->releaseId) : null,
                'unix_user' => $site->unixUser,
                // Only an isolated site's user is all its own; agents without telemetry.workloads.shared get unix_user
                // for isolated sites only (Fleet's PayloadCompatibility).
                'isolated' => $site->unixUser !== null && $site->isolated ? true : null,
                'runtime' => $site->runtime,
            ], fn ($v) => $v !== null && $v !== '');

            foreach ($site->logSources as $source) {
                $logSources[] = array_filter([
                    'path' => $source['path'],
                    'service' => $source['service'] ?? null,
                    'site' => $site->slug,
                    'format' => $source['format'] ?? null,
                    'kind' => $source['kind'] ?? null,
                    'multiline' => $source['multiline'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        if ($siteEntries !== []) {
            $payload['sites'] = $siteEntries;
        }

        if ($logSources !== []) {
            $payload['log_sources'] = $logSources;
        }

        return $payload;
    }
}
