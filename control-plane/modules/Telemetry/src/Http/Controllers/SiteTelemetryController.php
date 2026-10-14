<?php

namespace Falak\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\ServerMetricQueries;
use Falak\Telemetry\Application\Queries\SiteMetricQueries;
use Falak\Telemetry\Contracts\AccessLogs;
use Falak\Telemetry\Contracts\Data\AccessLogEntry;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON behind the service panel's Metrics and Logs tabs for one site (docs/UI_DESIGN.md §5.1).
 */
final class SiteTelemetryController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
    ) {}

    /** GET /telemetry/sites/{siteId}: the site, its servers and which backends are configured. */
    public function context(Request $request, string $siteId, TelemetryLinks $links): JsonResponse
    {
        $site = $this->site($request, $siteId);

        return response()->json([
            'site' => ['id' => $site->id, 'name' => $site->name],
            'servers' => $this->serversOf($site),
            'levels' => LogController::LEVELS,
            'ranges' => array_keys(ServerMetricQueries::RANGES),
            'configured' => [
                'logs' => (string) config('telemetry.loki.url') !== '',
                'traces' => (string) config('telemetry.tempo.url') !== '',
                'metrics' => (string) config('telemetry.metrics.query_url') !== '',
            ],
            'links' => [
                'logs' => $links->logs(['site_id' => $site->id]),
                'traces' => $links->traceSearch(['site_id' => $site->id]),
                'grafana' => $links->grafanaDashboard($site->organizationId, 'falak-laravel', ['site' => $site->id]),
            ],
        ]);
    }

    /**
     * GET /telemetry/sites/{siteId}/metrics/data?range=&server=: the site's workload (CPU, memory, disk, network,
     * processes, per service) against its limits and its servers, the servers' utilisation, and its traffic. Traffic
     * comes from span metrics when the site sends spans (`traffic_source: app`), else from its edge access log
     * (`edge`). `server` narrows everything to one of the site's servers. `shared_user`: a native site that is not
     * isolated runs as the unix user every such site shares, so the agent cannot measure it per user (only a cgroup
     * slice, when it has limits, measures it); without workload series the panel then says why.
     */
    public function metrics(Request $request, string $siteId, MetricsBackend $metrics, LogMetrics $logMetrics): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $input = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(ServerMetricQueries::RANGES))],
            'server' => ['nullable', 'string', 'max:26'],
        ]);
        $range = $input['range'] ?? '1h';
        [$seconds, $step] = ServerMetricQueries::RANGES[$range];
        $serverIds = array_map('strtolower', $site->serverIds());
        $server = isset($input['server']) ? strtolower($input['server']) : null;

        if ($server !== null && ! in_array($server, $serverIds, true)) {
            throw ValidationException::withMessages(['server' => 'The site does not run on this server.']);
        }

        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        $charts = [];
        $errors = [];
        $queries = SiteMetricQueries::for($site->id, $server !== null ? [$server] : $serverIds, $site->runtime->usesDocker(), $step);

        try {
            foreach ($queries as $key => $promql) {
                try {
                    $charts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $metrics->queryRange($promql, $start, $end, $step));
                } catch (TelemetryQueryFailed $e) {
                    $charts[$key] = [];
                    $errors[$key] = $e->getMessage();
                }
            }
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $source = 'app';

        // No spans in the range: the edge access log has every site's traffic.
        if (! self::hasPoints($charts['requests'] ?? []) && (string) config('telemetry.loki.url') !== '') {
            try {
                $edgeCharts = [];
                $edgeErrors = [];

                foreach (SiteMetricQueries::edge($site->organizationId, $site->slug, $server, $step, LokiAccessLogCounts::PROBE_AGENT) as $key => $logql) {
                    try {
                        $edgeCharts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $logMetrics->queryRange($logql, $start, $end, $step));
                    } catch (TelemetryQueryFailed $e) {
                        $edgeCharts[$key] = [];
                        $edgeErrors[$key] = $e->getMessage();
                    }
                }

                $charts = [...$charts, ...$edgeCharts];
                $errors = [...array_diff_key($errors, $edgeCharts), ...$edgeErrors];
                $source = 'edge';
            } catch (TelemetryUnavailable $e) {
                // Loki down: the (empty) span charts stay, the rest of the tab still renders.
                $errors['requests'] ??= $e->getMessage();
            }
        }

        return response()->json([
            'range' => $range,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
            'server' => $server,
            'servers' => $this->serversOf($site),
            'runtime' => $site->runtime->value,
            'containers' => $site->runtime->usesDocker(),
            'shared_user' => ! $site->runtime->usesDocker() && ! $site->isolated,
            'traffic_source' => $source,
            'charts' => $charts,
            'errors' => (object) $errors,
        ]);
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
     * GET /telemetry/sites/{siteId}/access-logs/data?release=&deployment=&since=&before=&status=&method=&path=&limit=
     * — the site's edge access log (Network Logs of a deployment panel), newest first; `cursor` pages to older requests.
     */
    public function accessLogs(Request $request, string $siteId, AccessLogs $logs): JsonResponse
    {
        $site = $this->site($request, $siteId);
        $input = $request->validate([
            'release' => ['nullable', 'string', 'max:26'],
            'deployment' => ['nullable', 'string', 'max:26'],
            'server' => ['nullable', 'string', 'max:26'],
            'since' => ['nullable', 'date'],
            'before' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'status' => ['nullable', 'string', 'regex:/^([1-5]\d\d|[1-5]xx)$/i'],
            'method' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,16}$/'],
            'path' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        if ((string) config('telemetry.loki.url') === '') {
            return response()->json(['configured' => false, 'entries' => [], 'cursor' => null]);
        }

        $limit = (int) ($input['limit'] ?? 500);
        $end = isset($input['before'])
            ? CarbonImmutable::createFromTimestamp(intdiv((int) $input['before'], 1_000_000_000))->setMicrosecond(intdiv((int) $input['before'] % 1_000_000_000, 1000))
            : CarbonImmutable::now();
        // Loki bounds a query's range; a deployment's requests older than a week are not listed.
        $start = isset($input['since']) ? CarbonImmutable::parse($input['since'])->max($end->subDays(7)) : $end->subDay();
        $names = collect($this->serversOf($site))->pluck('name', 'id');

        try {
            $entries = $start->lessThan($end) ? $logs->forSite($site->organizationId, $site->id, $start, $end, [
                'release_id' => $input['release'] ?? null,
                'deployment_id' => $input['deployment'] ?? null,
                'server_id' => $input['server'] ?? null,
                'status' => $input['status'] ?? null,
                'method' => $input['method'] ?? null,
                'path' => $input['path'] ?? null,
            ], $limit) : [];
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'configured' => true,
            'entries' => array_map(fn (AccessLogEntry $e) => [...$e->toArray(), 'server' => $e->serverId !== null ? ($names[$e->serverId] ?? null) : null], $entries),
            'cursor' => count($entries) >= $limit ? $entries[array_key_last($entries)]->timestampNs : null,
        ]);
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
}
