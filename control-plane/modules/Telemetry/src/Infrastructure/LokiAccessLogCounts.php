<?php

namespace Falak\Telemetry\Infrastructure;

use DateTimeInterface;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\LogQueryBuilder;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Falak\Telemetry\Contracts\Data\RequestCounts;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\PromQl;

/**
 * Counts access records with Loki instant metric queries (`sum(count_over_time(… [range]))` at $to), on the same
 * stream selector and filters as the access log itself.
 */
final class LokiAccessLogCounts implements AccessLogCounts
{
    /** User-Agent of the control plane's health checks (Deployments' SiteHealthProbe). */
    public const PROBE_AGENT = 'Falak-HealthCheck/';

    public function __construct(private readonly SiteDirectory $sites) {}

    public function forRelease(string $organizationId, string $siteId, string $releaseId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
    {
        return $this->counts($organizationId, $siteId, ['release_id' => $releaseId], $from, $to);
    }

    public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
    {
        return $this->counts($organizationId, $siteId, [], $from, $to);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function counts(string $organizationId, string $siteId, array $filters, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
    {
        $site = $this->sites->find(strtolower($siteId));

        if ($site === null || $site->organizationId !== strtolower($organizationId)) {
            return new RequestCounts(0, 0);
        }

        $range = max(1, $to->getTimestamp() - $from->getTimestamp());
        $http = self::client();
        $count = function (array $filters) use ($http, $organizationId, $site, $range, $to): int {
            // The control plane's own health checks (deploy steps, the watch after a release goes live) are not traffic.
            $selector = LogQueryBuilder::access($organizationId, $site->slug, $filters).' | user_agent_original!~'.PromQl::quote(self::PROBE_AGENT.'.*');
            $logql = "sum(count_over_time({$selector} [{$range}s]))";
            $body = $http->ensureSuccessful($http->get('/loki/api/v1/query', ['query' => $logql, 'time' => LokiLogsQuery::nanos($to)]))->json();

            if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
                throw new TelemetryQueryFailed('Loki', is_array($body) ? (string) ($body['error'] ?? 'unexpected response') : 'invalid JSON');
            }

            // An empty vector: no matching records.
            return (int) round((float) ($body['data']['result'][0]['value'][1] ?? 0));
        };

        return new RequestCounts($count($filters), $count([...$filters, 'status' => '5xx']));
    }

    private static function client(): HttpClient
    {
        $tenant = config('telemetry.loki.tenant');

        return new HttpClient('Loki', config('telemetry.loki.url'), $tenant ? ['X-Scope-OrgID' => (string) $tenant] : []);
    }
}
