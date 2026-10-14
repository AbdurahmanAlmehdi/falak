<?php

namespace Falak\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\PerformanceFindings;
use Falak\Insights\Contracts\SecurityFindings;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Http\RecordBandwidth;
use Falak\Telemetry\Application\Queries\HttpQueries;
use Falak\Telemetry\Application\Queries\LogQueryBuilder;
use Falak\Telemetry\Application\Queries\ServerMetricQueries;
use Falak\Telemetry\Contracts\Data\AccessLogEntry;
use Falak\Telemetry\Contracts\Data\LogLine;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Contracts\LogsQuery;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Contracts\PromQl;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Falak\Telemetry\Infrastructure\Geo\GeoDatabases;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON behind a site's HTTP tab and a server's edge traffic card (docs/plans/network/http.md).
 */
final class HttpTrafficController extends Controller
{
    private const REQUEST_ID = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * GET /telemetry/sites/{siteId}/http/data?range=&server=: charts from the agent's aggregates (`source: agent`), else
     * requests / bytes / p95 from the access log (`source: edge-logs`: agents before v0.17).
     */
    public function data(Request $request, string $siteId, MetricsBackend $metrics, LogMetrics $logs, PerformanceFindings $performance): JsonResponse
    {
        // Both contracts may share one implementation: the dispatcher would inject only the first.
        $security = app(SecurityFindings::class);
        $site = $this->site($request, $siteId);
        [$range, $seconds, $step, $server] = $this->range($request, $site);
        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        [$charts, $errors] = $this->series($metrics, HttpQueries::charts(HttpQueries::metricSelector($site->organizationId, $site->slug, $server), $step), $start, $end, $step);
        $source = 'agent';

        if (! self::hasPoints($charts['requests'] ?? []) && (string) config('telemetry.loki.url') !== '') {
            $selector = $this->accessSelector($site, $server);
            [$logCharts, $logErrors] = $this->series($logs, HttpQueries::logCharts($selector, $step), $start, $end, $step);
            $charts = [...$charts, ...$logCharts];
            $errors = [...array_diff_key($errors, $logCharts), ...$logErrors];
            $source = 'edge-logs';
        }

        return response()->json([
            'range' => $range,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
            'server' => $server,
            'servers' => $this->serversOf($site),
            'source' => $source,
            'charts' => $charts,
            'errors' => (object) $errors,
            'issues' => [
                'slow' => array_map(self::issue(...), $performance->openSlowEndpoints($site->organizationId, $site->id, 10)),
                'security' => array_map(self::issue(...), $security->openScanners($site->organizationId, $site->id, 10)),
            ],
            'can_manage' => $this->access->can($request->user(), $site->organizationId, 'sites.manage'),
            'logs_configured' => (string) config('telemetry.loki.url') !== '',
        ]);
    }

    /**
     * GET /telemetry/sites/{siteId}/http/top?table=&range=&server=: one drill-down table over the range (LogQL).
     */
    public function top(Request $request, string $siteId, LogMetrics $logs, GeoDatabases $geo): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $table = $request->validate(['table' => ['required', Rule::in(HttpQueries::TABLES)]])['table'];
        [$range, $seconds, , $server] = $this->range($request, $site);

        if ((string) config('telemetry.loki.url') === '') {
            return response()->json(['configured' => false, 'table' => $table, 'rows' => [], 'geo' => $geo->status()]);
        }

        $selector = $this->accessSelector($site, $server);
        $at = CarbonImmutable::now();
        $instant = fn (string $logql) => $logs->queryRange($logql, $at, $at, 60);

        try {
            $result = match ($table) {
                'paths' => ['rows' => self::rows($instant(HttpQueries::table('paths', $selector, $seconds)), 'url_route', 'route')],
                'agents' => ['rows' => self::rows($instant(HttpQueries::table('agents', $selector, $seconds)), 'user_agent_original', 'user_agent')],
                'status' => ['rows' => self::rows($instant(HttpQueries::table('status', $selector, $seconds)), 'http_response_status_code', 'status')],
                'slow' => ['rows' => $this->slow($instant, $selector, $seconds)],
                'clients' => ['rows' => $this->withGeo($geo, self::rows($instant(HttpQueries::table('clients', $selector, $seconds)), 'client_address', 'ip'))],
                'countries' => $this->countries($instant, $geo, $selector, $seconds),
                'threats' => $this->threats($instant, $geo, $selector, $seconds),
            };
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['configured' => true, 'table' => $table, 'range' => $range, ...$result, 'geo' => $geo->status()]);
    }

    /** GET /telemetry/sites/{siteId}/http/bandwidth: the last 12 months and the alert threshold. */
    public function bandwidth(Request $request, string $siteId): JsonResponse
    {
        $site = $this->site($request, $siteId);

        return response()->json([
            'months' => RecordBandwidth::months($site->organizationId, $site->slug),
            'threshold_bytes' => HttpSettings::for($site->organizationId, $site->id)->bandwidth_alert_bytes,
        ]);
    }

    /** GET /telemetry/sites/{siteId}/http/settings */
    public function settings(Request $request, string $siteId): JsonResponse
    {
        $site = $this->site($request, $siteId);

        return response()->json(HttpSettings::for($site->organizationId, $site->id)->toResponse());
    }

    /** PUT /telemetry/sites/{siteId}/http/settings (sites.manage): null resets a value to the default. */
    public function updateSettings(Request $request, string $siteId, AuditLog $audit): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $this->access->authorize($request->user(), $site->organizationId, 'sites.manage');
        $input = $request->validate([
            'slow_ms' => ['nullable', 'integer', 'min:100', 'max:60000'],
            'slow_min_requests' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'bandwidth_alert_bytes' => ['nullable', 'integer', 'min:1048576', 'max:'.PHP_INT_MAX],
        ]);
        $settings = HttpSettings::for($site->organizationId, $site->id);
        $settings->forceFill([
            'slow_ms' => $input['slow_ms'] ?? null,
            'slow_min_requests' => $input['slow_min_requests'] ?? null,
            'bandwidth_alert_bytes' => $input['bandwidth_alert_bytes'] ?? null,
        ])->save();
        $audit->record('telemetry.http_settings_updated', 'site', $site->id, $input, $site->organizationId);

        return response()->json($settings->toResponse());
    }

    /**
     * GET /telemetry/sites/{siteId}/requests/{requestId}?at=: the access line(s) of a request id, the app's records that
     * mention it (±15 minutes of the request) and the trace to open.
     */
    public function request(Request $request, string $siteId, string $requestId, LogsQuery $logs): JsonResponse
    {
        $site = $this->site($request, $siteId);
        abort_unless(preg_match(self::REQUEST_ID, $requestId) === 1, 404);
        $at = $request->validate(['at' => ['nullable', 'date']])['at'] ?? null;

        if ((string) config('telemetry.loki.url') === '') {
            return response()->json(['configured' => false, 'access' => [], 'app' => [], 'trace_id' => null]);
        }

        $end = $at !== null ? CarbonImmutable::parse($at)->addMinutes(15) : CarbonImmutable::now();
        $start = $at !== null ? CarbonImmutable::parse($at)->subMinutes(15) : $end->subDay();

        try {
            $access = array_map(fn (LogLine $l) => AccessLogEntry::fromLogLine($l), $logs->queryRange(
                LogQueryBuilder::access($site->organizationId, $site->slug, []).' | http_request_id='.PromQl::quote($requestId), $start, $end, 10));

            if ($at === null && $access !== []) {
                $when = CarbonImmutable::instance($access[0]->at());
                [$start, $end] = [$when->subMinutes(15), $when->addMinutes(15)];
            }

            $app = $logs->queryRange(LogQueryBuilder::build($site->organizationId, ['site_id' => $site->id, 'kind' => 'app', 'search' => $requestId]), $start, $end, 200);
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $traceId = null;

        foreach ([...array_map(fn (AccessLogEntry $e) => $e->traceId, $access), ...array_map(fn (LogLine $l) => $l->traceId(), $app)] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $traceId = $candidate;
                break;
            }
        }

        return response()->json([
            'configured' => true,
            'request_id' => $requestId,
            'access' => array_map(fn (AccessLogEntry $e) => $e->toArray(), $access),
            'app' => array_map(fn (LogLine $l) => $l->toArray(), $app),
            'trace_id' => $traceId,
        ]);
    }

    /**
     * GET /telemetry/servers/{serverId}/http/data?range=: the edge of a server, every site it serves summed, and
     * requests / s per site.
     */
    public function server(Request $request, string $serverId, MetricsBackend $metrics): JsonResponse
    {
        $server = $this->findServer($request, $serverId);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(ServerMetricQueries::RANGES))]])['range'] ?? '1h';
        [$seconds, $step] = ServerMetricQueries::RANGES[$range];
        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        $selector = HttpQueries::metricSelector($server->organizationId, null, $server->id);
        [$charts, $errors] = $this->series($metrics, [...HttpQueries::charts($selector, $step), 'sites' => HttpQueries::perSite($selector, $step)], $start, $end, $step);

        return response()->json([
            'range' => $range,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
            'charts' => $charts,
            'errors' => (object) $errors,
        ]);
    }

    /**
     * @param  callable(string): list<MetricSeries>  $instant
     * @return list<array<string, mixed>>
     */
    private function slow(callable $instant, string $selector, int $seconds): array
    {
        $counts = [];

        foreach ($instant(HttpQueries::routeCounts($selector, $seconds)) as $s) {
            $counts[$s->labels['url_route'] ?? ''] = (int) $s->last();
        }

        $rows = [];

        foreach ($instant(HttpQueries::table('slow', $selector, $seconds, 50)) as $s) {
            $route = $s->labels['url_route'] ?? '';
            $requests = $counts[$route] ?? 0;

            if ($route !== '' && $requests >= 5 && $s->last() !== null) {
                $rows[] = ['route' => $route, 'p95_ms' => round((float) $s->last(), 1), 'requests' => $requests];
            }
        }

        usort($rows, fn (array $a, array $b) => $b['p95_ms'] <=> $a['p95_ms']);

        return array_slice($rows, 0, 20);
    }

    /**
     * @param  callable(string): list<MetricSeries>  $instant
     * @return array<string, mixed>
     */
    private function countries(callable $instant, GeoDatabases $geo, string $selector, int $seconds): array
    {
        $clients = self::rows($instant(HttpQueries::table('clients', $selector, $seconds, (int) config('telemetry.http.geo_top_ips', 500))), 'client_address', 'ip');
        $total = (int) array_sum(array_map(fn (MetricSeries $s) => (float) $s->last(), $instant("sum(count_over_time({$selector} [".max(60, $seconds).'s]))')));
        $looked = $geo->lookup(array_column($clients, 'ip'));
        $countries = $networks = [];
        $covered = 0;

        foreach ($clients as $row) {
            $g = $looked[$row['ip']] ?? null;

            if ($g === null || $g['country'] === null) {
                continue;
            }

            $covered += $row['requests'];
            $countries[$g['country']] ??= ['country' => $g['country'], 'country_name' => $g['country_name'], 'requests' => 0, 'clients' => 0];
            $countries[$g['country']]['requests'] += $row['requests'];
            $countries[$g['country']]['clients']++;

            if ($g['asn'] !== null) {
                $networks[$g['asn']] ??= ['asn' => $g['asn'], 'as_org' => $g['as_org'], 'requests' => 0, 'clients' => 0];
                $networks[$g['asn']]['requests'] += $row['requests'];
                $networks[$g['asn']]['clients']++;
            }
        }

        $sort = fn (array $rows) => array_slice(collect($rows)->sortByDesc('requests')->values()->all(), 0, 30);

        return [
            'rows' => $sort($countries),
            'networks' => $sort($networks),
            'coverage' => $total > 0 ? round($covered / $total, 3) : null,
        ];
    }

    /**
     * Offending clients: their threat hits per rule, and 404 storms.
     *
     * @param  callable(string): list<MetricSeries>  $instant
     * @return array<string, mixed>
     */
    private function threats(callable $instant, GeoDatabases $geo, string $selector, int $seconds): array
    {
        $byIp = [];

        foreach ($instant(HttpQueries::table('threats', $selector, $seconds, 100)) as $s) {
            $ip = $s->labels['client_address'] ?? '';
            $rule = $s->labels['falak_threat_rule'] ?? '';

            if ($ip === '' || $rule === '') {
                continue;
            }

            $byIp[$ip] ??= ['ip' => $ip, 'requests' => 0, 'rules' => [], 'not_found' => 0];
            $byIp[$ip]['rules'][$rule] = (int) $s->last();
            $byIp[$ip]['requests'] += (int) $s->last();
        }

        foreach ($instant(HttpQueries::notFoundByClient($selector, $seconds, 50)) as $s) {
            $ip = $s->labels['client_address'] ?? '';

            if ($ip !== '') {
                $byIp[$ip] ??= ['ip' => $ip, 'requests' => 0, 'rules' => [], 'not_found' => 0];
                $byIp[$ip]['not_found'] = (int) $s->last();
            }
        }

        $rows = collect($byIp)->sortByDesc(fn (array $r) => $r['requests'] + $r['not_found'])->take(50)->values()->all();

        return ['rows' => $this->withGeo($geo, $rows)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  with an `ip`
     * @return list<array<string, mixed>>
     */
    private function withGeo(GeoDatabases $geo, array $rows): array
    {
        $looked = $geo->lookup(array_column($rows, 'ip'));

        return array_map(fn (array $row) => [...$row, ...($looked[$row['ip']] ?? [])], $rows);
    }

    /**
     * @param  list<MetricSeries>  $series
     * @return list<array<string, mixed>>
     */
    private static function rows(array $series, string $label, string $key): array
    {
        $rows = [];

        foreach ($series as $s) {
            if (($value = $s->labels[$label] ?? null) !== null && $s->last() !== null) {
                $rows[] = [$key => $value, 'requests' => (int) $s->last()];
            }
        }

        usort($rows, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);

        return $rows;
    }

    /**
     * @param  MetricsBackend|LogMetrics  $backend
     * @param  array<string, string>  $queries
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, string>}
     */
    private function series(object $backend, array $queries, CarbonImmutable $start, CarbonImmutable $end, int $step): array
    {
        $charts = $errors = [];

        foreach ($queries as $key => $query) {
            try {
                $charts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $backend->queryRange($query, $start, $end, $step));
            } catch (TelemetryQueryFailed|TelemetryUnavailable $e) {
                $charts[$key] = [];
                $errors[$key] = $e->getMessage();
            }
        }

        return [$charts, $errors];
    }

    /**
     * @param  list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}>  $series
     */
    private static function hasPoints(array $series): bool
    {
        foreach ($series as $item) {
            foreach ($item['points'] as [, $value]) {
                if ($value !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function issue(IssueData $issue): array
    {
        return ['id' => $issue->id, 'title' => $issue->title, 'occurrences' => $issue->occurrences, 'last_seen_at' => $issue->lastSeenAt->format(DATE_ATOM), 'url' => $issue->url];
    }

    private function accessSelector(SiteData $site, ?string $server): string
    {
        return LogQueryBuilder::access($site->organizationId, $site->slug, ['server_id' => $server])
            .' | user_agent_original!~'.PromQl::quote(LokiAccessLogCounts::PROBE_AGENT.'.*');
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: ?string}
     */
    private function range(Request $request, SiteData $site): array
    {
        $input = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(ServerMetricQueries::RANGES))],
            'server' => ['nullable', 'string', 'max:26'],
        ]);
        $range = $input['range'] ?? '1h';
        [$seconds, $step] = ServerMetricQueries::RANGES[$range];
        $server = isset($input['server']) ? strtolower($input['server']) : null;

        if ($server !== null && ! in_array($server, array_map('strtolower', $site->serverIds()), true)) {
            throw ValidationException::withMessages(['server' => 'The site does not run on this server.']);
        }

        return [$range, $seconds, $step, $server];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function serversOf(SiteData $site): array
    {
        $servers = [];

        foreach ($site->serverIds() as $serverId) {
            $server = $this->servers->find($serverId);

            if ($server !== null && $server->organizationId === $site->organizationId) {
                $servers[] = ['id' => $server->id, 'name' => $server->name];
            }
        }

        return $servers;
    }

    private function site(Request $request, string $siteId): SiteData
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');
        $site = $this->sites->find(strtolower($siteId));
        abort_if($site === null || $site->organizationId !== $organizationId, 404);

        return $site;
    }

    private function findServer(Request $request, string $serverId): ServerData
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');
        $server = $this->servers->find(strtolower($serverId));
        abort_if($server === null || $server->organizationId !== $organizationId, 404);

        return $server;
    }
}
