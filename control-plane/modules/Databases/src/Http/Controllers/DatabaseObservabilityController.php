<?php

namespace Falak\Databases\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Databases\Application\Actions\EnableQueryInsights;
use Falak\Databases\Application\Actions\RecordObservation;
use Falak\Databases\Application\DatabaseMetricQueries;
use Falak\Databases\Application\QueryInsights;
use Falak\Databases\Application\Recommendations;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\DatabaseIssues;
use Falak\Kernel\Http\Controller;
use Falak\Telemetry\Contracts\Data\LogLine;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogsQuery;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Contracts\PromQl;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A database instance's Metrics, Logs and Insights tabs (docs/plans/OBSERVABILITY.md phase 3), as JSON. Metrics and
 * logs come from VictoriaMetrics and Loki (they also need telemetry.view, as a site's tabs do); insights from the
 * agent's db.observed reports.
 */
final class DatabaseObservabilityController extends Controller
{
    public const METRIC_RANGES = [
        '1h' => [3600, 30],
        '6h' => [21600, 120],
        '24h' => [86400, 300],
        '7d' => [604800, 1800],
    ];

    public const INSIGHT_RANGES = ['24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function __construct(private readonly OrganizationAccess $access) {}

    /** GET /databases/instances/{instance}/metrics/data?range=1h|6h|24h|7d */
    public function metrics(Request $request, DatabaseInstance $instance, MetricsBackend $metrics): JsonResponse
    {
        $this->authorizeTelemetry($request, $instance);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::METRIC_RANGES))]])['range'] ?? '1h';
        [$seconds, $step] = self::METRIC_RANGES[$range];
        $end = CarbonImmutable::now();
        $start = $end->subSeconds($seconds);
        $charts = [];
        $errors = [];

        try {
            foreach (DatabaseMetricQueries::for($instance->id, $instance->server_id, $instance->engine, $step) as $key => $promql) {
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

        return response()->json([
            'range' => $range,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
            'engine' => $instance->engine->value,
            'memory_bytes' => $instance->memory_bytes,
            'cpus' => $instance->cpus,
            'observed_at' => $instance->observed_at?->toIso8601String(),
            'charts' => $charts,
            'errors' => (object) $errors,
        ]);
    }

    /** GET /databases/instances/{instance}/logs/data?kind=all|slow|engine&search=&range=1h|6h|24h|7d */
    public function logs(Request $request, DatabaseInstance $instance, LogsQuery $logs): JsonResponse
    {
        $this->authorizeTelemetry($request, $instance);
        $input = $request->validate([
            'kind' => ['nullable', Rule::in(['all', 'slow', 'engine'])],
            'search' => ['nullable', 'string', 'max:500'],
            'range' => ['nullable', Rule::in(array_keys(self::METRIC_RANGES))],
            'limit' => ['nullable', 'integer', 'min:10', 'max:1000'],
        ]);
        [$seconds] = self::METRIC_RANGES[$input['range'] ?? '1h'];
        $end = CarbonImmutable::now();

        try {
            $lines = $logs->queryRange(self::logQuery($instance, $input['kind'] ?? 'all', $input['search'] ?? null), $end->subSeconds($seconds), $end, (int) ($input['limit'] ?? 200));
        } catch (TelemetryUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TelemetryQueryFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'lines' => array_map(fn (LogLine $line) => [
                ...$line->toArray(),
                'kind' => ($line->labels['falak_log_kind'] ?? '') === 'db.slow' ? 'slow' : 'engine',
                'severity' => $line->metadata['severity_text'] ?? $line->labels['detected_level'] ?? $line->metadata['detected_level'] ?? null,
                'duration_ms' => isset($line->metadata['falak_db_duration_ms']) ? (float) $line->metadata['falak_db_duration_ms'] : null,
                'database' => $line->metadata['db_namespace'] ?? null,
            ], $lines),
        ]);
    }

    /**
     * An instance's log stream: its container's (the engine's stderr, kind db.engine) and the agent's slow-query records
     * (kind db.slow), both under service_name falak-db-<id>. Engine records of agents before phase 3 carry no kind.
     */
    public static function logQuery(DatabaseInstance $instance, string $kind, ?string $search): string
    {
        $matchers = [PromQl::label('falak_org_id', strtoupper($instance->organization_id)), PromQl::label('service_name', $instance->container())];

        if ($kind === 'slow') {
            $matchers[] = PromQl::label('falak_log_kind', 'db.slow');
        } elseif ($kind === 'engine') {
            $matchers[] = 'falak_log_kind!='.PromQl::quote('db.slow');
        }

        $query = '{'.implode(', ', $matchers).'}';

        if ($search !== null && $search !== '') {
            $query .= ' |= '.PromQl::quote($search);
        }

        return $query;
    }

    /** GET /databases/instances/{instance}/insights?range=24h|7d|30d */
    public function insights(Request $request, DatabaseInstance $instance, DatabaseIssues $issues, AgentDirectory $agents, ServiceVolumes $volumes, QueryInsights $queryInsights): JsonResponse
    {
        $this->authorize('view', $instance);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::INSIGHT_RANGES))]])['range'] ?? '24h';
        $report = (array) ($instance->observed ?? []);
        $stale = $instance->observed_at === null || $instance->observed_at->lt(now()->subSeconds((int) config('databases.observability.stale_seconds', 600)));
        $agent = $agents->forServer($instance->server_id);
        $sizes = DB::table('databases_size_samples')->where('database_instance_id', $instance->id)
            ->where('day', '>=', now()->subDays(14)->toDateString())->orderBy('day')->pluck('size_bytes', 'day')
            ->map(fn ($bytes) => (int) $bytes)->all();
        $volume = $instance->volume_id !== null ? $volumes->find($instance->volume_id) : null;

        return response()->json([
            'range' => $range,
            'engine' => $instance->engine->value,
            'observed_at' => $instance->observed_at?->toIso8601String(),
            // Why there is nothing: the server's agent predates phase 3, or the instance's image does (it never
            // answers `falak-db observe`: a minor upgrade gets the new helper).
            'reporting' => match (true) {
                ! $stale => 'ok',
                $agent === null || ! $agent->supports('db.observability') => 'agent_outdated',
                $instance->isRunning() => 'image_outdated',
                default => 'stopped',
            },
            'query_insights' => self::queryInsightsState($instance, $report, $queryInsights),
            'slow_query_ms' => RecordObservation::slowThreshold($instance, $report),
            'gauges' => (object) ($report['gauges'] ?? []),
            'labels' => (object) ($report['labels'] ?? []),
            'settings' => (object) ($report['settings'] ?? []),
            'databases' => array_values((array) ($report['databases'] ?? [])),
            'issues' => array_map(fn (IssueData $issue) => [
                'id' => $issue->id,
                'title' => $issue->title,
                'culprit' => $issue->culprit,
                'status' => $issue->status->value,
                'priority' => $issue->priority->value,
                'occurrences' => $issue->occurrences,
                'first_seen_at' => $issue->firstSeenAt->format(DATE_ATOM),
                'last_seen_at' => $issue->lastSeenAt->format(DATE_ATOM),
                'url' => $issue->url,
            ], $issues->open($instance->organization_id, $instance->id, 50)),
            'top_queries' => self::topQueries($instance, self::INSIGHT_RANGES[$range]),
            'recommendations' => Recommendations::for($instance->engine, $report, $sizes, $volume?->sizeLimitBytes, (int) $instance->memory_bytes),
        ]);
    }

    /**
     * Query shapes by total time over the last $seconds.
     *
     * @return list<array<string, mixed>>
     */
    public static function topQueries(DatabaseInstance $instance, int $seconds, int $limit = 50): array
    {
        $since = now()->subSeconds($seconds)->startOfHour();

        return DB::table('databases_query_stats as s')
            ->join('databases_query_shapes as q', 'q.id', '=', 's.shape_id')
            ->where('s.database_instance_id', $instance->id)
            ->where('s.hour', '>=', $since)
            ->groupBy('q.id', 'q.fingerprint', 'q.query', 'q.database', 'q.source', 'q.last_seen_at')
            ->selectRaw('q.id, q.fingerprint, q.query, q.database, q.source, q.last_seen_at, sum(s.calls) as calls, sum(s.total_ms) as total_ms, max(s.max_ms) as max_ms, sum(s.rows) as rows_total, sum(s.no_index) as no_index')
            ->orderByDesc('total_ms')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'fingerprint' => $row->fingerprint,
                'query' => $row->query,
                'database' => $row->database,
                'source' => $row->source,
                'calls' => (int) $row->calls,
                'total_ms' => round((float) $row->total_ms, 2),
                'mean_ms' => (int) $row->calls > 0 ? round((float) $row->total_ms / (int) $row->calls, 2) : null,
                'max_ms' => round((float) $row->max_ms, 2),
                'rows' => (int) $row->rows_total,
                'no_index' => (int) $row->no_index,
                'last_seen_at' => CarbonImmutable::parse((string) $row->last_seen_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The query insights card: on (the report says so), enabled but not applied yet (waiting for the restart the
     * enable sent, or for the next one: `pending`), or off with whether it can be turned on and, if not, why. A failed
     * dispatch shows as `error` (the instance's status message), so the card never waits forever.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function queryInsightsState(DatabaseInstance $instance, array $report, QueryInsights $queryInsights): array
    {
        $status = $report['query_insights']['status'] ?? null;
        $refusal = in_array($instance->engine, [Engine::PostgreSql, Engine::MariaDb], true) ? $queryInsights->refusal($instance) : null;
        $applied = $status === 'on';

        return [
            'enabled' => $instance->query_insights,
            'status' => $status,
            'reason' => $report['query_insights']['reason'] ?? null,
            // Enabled, not applied yet: the restart is on its way (or the user restarts later).
            'pending' => $instance->query_insights && ! $applied && in_array($instance->engine, [Engine::PostgreSql, Engine::MariaDb], true),
            // Turning it on (or applying a waiting flag) restarts the database: offered where it would work.
            'can_enable' => in_array($instance->engine, [Engine::PostgreSql, Engine::MariaDb], true) && ! $applied && $refusal === null,
            'blocked' => $applied ? null : $refusal,
            'error' => $instance->query_insights && ! $applied && is_string($instance->status_message) && $instance->status_message !== '' ? $instance->status_message : null,
        ];
    }

    /** POST /databases/instances/{instance}/query-insights: turn it on (restarts the database). */
    public function enableQueryInsights(Request $request, DatabaseInstance $instance, EnableQueryInsights $enable): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $instance);
        $enable($instance, $request->user()?->getAuthIdentifier());

        return $request->wantsJson() ? response()->json(['query_insights' => true]) : back();
    }

    private function authorizeTelemetry(Request $request, DatabaseInstance $instance): void
    {
        $this->authorize('view', $instance);

        if (! $this->access->can($request->user(), $instance->organization_id, 'telemetry.view')) {
            abort(403, 'Viewing metrics and logs needs the telemetry.view permission.');
        }
    }
}
