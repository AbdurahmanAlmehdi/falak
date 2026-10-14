<?php

namespace Falak\Databases\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Insights\Contracts\DatabaseIssues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One instance's part of a `db.observed` report (docs/plans/OBSERVABILITY.md phase 3): the report is kept on the
 * instance for the UI and the recommendations; statements go to the top query shapes (hourly stats); the databases'
 * total size to a daily sample (the disk forecast); slow shapes and engine errors become database Issues; slow-query
 * spikes, replication lag, deadlocks and memory pressure alert.
 */
final class RecordObservation
{
    public const ALERT_SLOW = 'databases.slow_queries_spike';

    public const ALERT_LAG = 'databases.replication_lag';

    public const ALERT_DEADLOCKS = 'databases.deadlocks';

    public const ALERT_MEMORY = 'databases.memory_high';

    /** What the report's detail sections keep (the latest one wins until the next detail call). */
    private const DETAIL = ['tables', 'indexes', 'databases'];

    public function __construct(
        private readonly DatabaseIssues $issues,
        private readonly AlertConditions $conditions,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $report  one item of db.observed `instances` (schema-validated)
     */
    public function __invoke(DatabaseInstance $instance, array $report): void
    {
        $at = CarbonImmutable::parse((string) $report['at'])->utc();

        // A clock far off (or a replayed report) never moves the snapshot backwards or into the future.
        if ($at->isAfter(now()->addMinutes(5))) {
            $at = CarbonImmutable::now();
        }

        $this->snapshot($instance, $report, $at);
        $hourly = $this->statements($instance, (array) ($report['statements'] ?? []), $at);
        $this->sizeSample($instance, $report, $at);

        try {
            $this->raiseIssues($instance, $report, $at, $hourly);
        } catch (Throwable $e) {
            // Issues are a view on the report: their failure must not lose the rest of it.
            Log::warning('Database issues could not be recorded.', ['instance_id' => $instance->id, 'error' => $e->getMessage()]);
        }

        $this->alerts($instance, $report);
    }

    /** The slow-query threshold of the instance, in ms (0: slow logging off). */
    public static function slowThreshold(DatabaseInstance $instance, array $report = []): float
    {
        $reported = $report['settings']['slow_query_ms'] ?? null;

        if (is_numeric($reported) && $reported > 0) {
            return (float) $reported;
        }

        $setting = $instance->settings['slow_query_ms'] ?? null;

        if (is_numeric($setting)) {
            return (float) $setting;
        }

        return $instance->engine->isKeyValue() ? 10.0 : 1000.0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function snapshot(DatabaseInstance $instance, array $report, CarbonImmutable $at): void
    {
        if ($instance->observed_at !== null && $at->lessThan($instance->observed_at)) {
            return;
        }

        $previous = (array) ($instance->observed ?? []);
        $snapshot = array_intersect_key($report, array_flip(['version', 'query_insights', 'gauges', 'deltas', 'labels', 'settings', 'memory', 'errors', 'window_s']));

        // Detail sections come every fifth report: the last ones stay until new ones arrive.
        foreach (self::DETAIL as $section) {
            if (array_key_exists($section, $report)) {
                $snapshot[$section] = $report[$section];
                $snapshot['detail_at'] = $at->toIso8601String();
            } elseif (array_key_exists($section, $previous)) {
                $snapshot[$section] = $previous[$section];
                $snapshot['detail_at'] = $previous['detail_at'] ?? null;
            }
        }

        // Statements are the deltas of one window and live in the stats tables; the instance keeps only those that ran
        // without an index (MySQL / MariaDB digests), for the recommendations.
        if (array_key_exists('statements', $report)) {
            $snapshot['no_index_statements'] = array_values(array_map(
                fn (array $s) => ['query' => mb_substr((string) $s['query'], 0, 300), 'no_index' => (int) $s['no_index'], 'calls' => (int) $s['calls']],
                array_slice(array_filter((array) $report['statements'], fn ($s) => is_array($s) && (int) ($s['no_index'] ?? 0) > 0), 0, 10),
            ));
        } elseif (isset($previous['no_index_statements'])) {
            $snapshot['no_index_statements'] = $previous['no_index_statements'];
        }

        $instance->forceFill(['observed' => $snapshot, 'observed_at' => $at])->save();
    }

    /**
     * Adds the window's statements to their shapes and to the shapes' hour, in two upserts (shapes, then stats) instead
     * of locking rows per statement. Returns the hour's totals per fingerprint after the upsert, for the Issues.
     *
     * @param  list<array<string, mixed>>  $statements
     * @return array<string, array{calls: int, total_ms: float}>
     */
    private function statements(DatabaseInstance $instance, array $statements, CarbonImmutable $at): array
    {
        $hour = $at->startOfHour();
        $byFingerprint = [];

        foreach ($statements as $statement) {
            if (! is_array($statement) || (int) ($statement['calls'] ?? 0) <= 0) {
                continue;
            }

            $fingerprint = (string) $statement['fingerprint'];
            $calls = (int) $statement['calls'];
            $total = max(0.0, (float) $statement['total_ms']);
            $item = $byFingerprint[$fingerprint] ?? [
                'query' => mb_substr((string) $statement['query'], 0, 2000),
                'database' => isset($statement['database']) ? mb_substr((string) $statement['database'], 0, 128) : null,
                'source' => (string) $statement['source'],
                'calls' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'rows' => 0, 'no_index' => 0,
            ];
            $item['calls'] += $calls;
            $item['total_ms'] += $total;
            $item['max_ms'] = max($item['max_ms'], max(0.0, (float) ($statement['max_ms'] ?? ($total / $calls))));
            $item['rows'] += max(0, (int) ($statement['rows'] ?? 0));
            $item['no_index'] += max(0, (int) ($statement['no_index'] ?? 0));
            $byFingerprint[$fingerprint] = $item;
        }

        if ($byFingerprint === []) {
            return [];
        }

        $fingerprints = array_map('strval', array_keys($byFingerprint));
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'max' : 'greatest';

        DB::transaction(function () use ($instance, $byFingerprint, $fingerprints, $at, $hour, $greatest) {
            // Shapes: new ones are inserted, known ones get their latest text and source (first_seen_at stays).
            DB::table('databases_query_shapes')->upsert(
                array_map(fn (string $fingerprint) => [
                    'organization_id' => $instance->organization_id,
                    'database_instance_id' => $instance->id,
                    'fingerprint' => $fingerprint,
                    'database' => $byFingerprint[$fingerprint]['database'],
                    'query' => $byFingerprint[$fingerprint]['query'],
                    'source' => $byFingerprint[$fingerprint]['source'],
                    'first_seen_at' => $at,
                    'last_seen_at' => $at,
                ], $fingerprints),
                ['database_instance_id', 'fingerprint'],
                ['database', 'query', 'source', 'last_seen_at'],
            );

            $ids = DB::table('databases_query_shapes')->where('database_instance_id', $instance->id)
                ->whereIn('fingerprint', $fingerprints)->pluck('id', 'fingerprint');

            // Stats: one row per shape and hour, added to.
            $t = 'databases_query_stats';
            DB::table($t)->upsert(
                collect($fingerprints)->filter(fn (string $f) => isset($ids[$f]))->map(fn (string $f) => [
                    'shape_id' => (int) $ids[$f], 'database_instance_id' => $instance->id, 'hour' => $hour,
                    'calls' => $byFingerprint[$f]['calls'], 'total_ms' => $byFingerprint[$f]['total_ms'], 'max_ms' => $byFingerprint[$f]['max_ms'],
                    'rows' => $byFingerprint[$f]['rows'], 'no_index' => $byFingerprint[$f]['no_index'],
                ])->values()->all(),
                ['shape_id', 'hour'],
                [
                    'calls' => DB::raw("{$t}.calls + excluded.calls"),
                    'total_ms' => DB::raw("{$t}.total_ms + excluded.total_ms"),
                    'max_ms' => DB::raw("{$greatest}({$t}.max_ms, excluded.max_ms)"),
                    'rows' => DB::raw("{$t}.rows + excluded.rows"),
                    'no_index' => DB::raw("{$t}.no_index + excluded.no_index"),
                ],
            );
        });

        return DB::table('databases_query_stats as s')->join('databases_query_shapes as q', 'q.id', '=', 's.shape_id')
            ->where('q.database_instance_id', $instance->id)->whereIn('q.fingerprint', $fingerprints)->where('s.hour', $hour)
            ->get(['q.fingerprint', 's.calls', 's.total_ms'])
            ->mapWithKeys(fn ($row) => [(string) $row->fingerprint => ['calls' => (int) $row->calls, 'total_ms' => (float) $row->total_ms]])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function sizeSample(DatabaseInstance $instance, array $report, CarbonImmutable $at): void
    {
        if (! isset($report['databases']) || $instance->engine->isKeyValue()) {
            return;
        }

        $bytes = array_sum(array_map(fn ($d) => is_array($d) ? max(0, (int) ($d['size_bytes'] ?? 0)) : 0, (array) $report['databases']));

        DB::table('databases_size_samples')->upsert(
            [['database_instance_id' => $instance->id, 'day' => $at->toDateString(), 'size_bytes' => $bytes]],
            ['database_instance_id', 'day'],
            ['size_bytes'],
        );
    }

    /**
     * A slow shape becomes an Issue occurrence only once it is a pattern, not a one-off: its hour (the stats row after
     * this report) has at least databases.observability.issue_min_calls calls (Redis / Valkey: issue_min_calls_kv)
     * and, for engine statistics, an hourly mean at or above the threshold. Slow-log shapes are slow by definition.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, array{calls: int, total_ms: float}>  $hourly
     */
    private function raiseIssues(DatabaseInstance $instance, array $report, CarbonImmutable $at, array $hourly = []): void
    {
        $threshold = self::slowThreshold($instance, $report);
        $minCalls = $instance->engine->isKeyValue()
            ? (int) config('databases.observability.issue_min_calls_kv', 20)
            : (int) config('databases.observability.issue_min_calls', 5);

        foreach ((array) ($report['statements'] ?? []) as $statement) {
            $calls = (int) ($statement['calls'] ?? 0);

            if ($calls <= 0) {
                continue;
            }

            $mean = (float) $statement['total_ms'] / $calls;
            $hour = $hourly[(string) $statement['fingerprint']] ?? ['calls' => $calls, 'total_ms' => (float) $statement['total_ms']];
            $hourMean = $hour['calls'] > 0 ? $hour['total_ms'] / $hour['calls'] : 0.0;
            $slow = $statement['source'] === 'slowlog' || ($threshold > 0 && $hourMean >= $threshold);

            if (! $slow || $hour['calls'] < $minCalls) {
                continue;
            }

            $query = (string) $statement['query'];
            $this->issues->record(
                $instance->organization_id,
                $instance->server_id,
                $instance->id,
                (string) $statement['fingerprint'],
                'Slow query: '.Str::limit(preg_replace('/\s+/', ' ', $query) ?? $query, 120),
                $instance->name.(isset($statement['database']) && $statement['database'] !== '' ? " / {$statement['database']}" : ''),
                $calls,
                $at,
                [
                    'query' => $query,
                    'calls' => $calls,
                    'mean_ms' => round($mean, 2),
                    'max_ms' => isset($statement['max_ms']) ? round((float) $statement['max_ms'], 2) : null,
                    'at' => $at->toIso8601String(),
                ],
                ['engine' => $instance->engine->value, 'database' => $statement['database'] ?? null, 'source' => $statement['source'], 'threshold_ms' => $threshold],
            );
        }

        foreach ((array) ($report['errors'] ?? []) as $error) {
            $kind = (string) ($error['kind'] ?? '');
            $count = (int) ($error['count'] ?? 0);

            if ($kind === '' || $count < ($kind === 'auth_failed' ? (int) config('databases.observability.auth_failed_min', 5) : 1)) {
                continue;
            }

            $this->issues->record(
                $instance->organization_id,
                $instance->server_id,
                $instance->id,
                "error:{$kind}",
                self::errorTitle($kind, $instance->name),
                $instance->name,
                $count,
                $at,
                array_filter(['message' => isset($error['message']) ? mb_substr((string) $error['message'], 0, 300) : null, 'count' => $count, 'at' => $at->toIso8601String()], fn ($v) => $v !== null),
                ['engine' => $instance->engine->value, 'error' => $kind],
            );
        }
    }

    public static function errorTitle(string $kind, string $name): string
    {
        return match ($kind) {
            'deadlock' => "Deadlocks on {$name}",
            'too_many_connections' => "Connections refused on {$name}: too many connections",
            'auth_failed' => "Repeated authentication failures on {$name}",
            'oom' => "{$name} ran out of memory",
            'replication' => "Replication broken on {$name}",
            'disk_full' => "{$name} ran out of disk space",
            'crash' => "{$name} crashed",
            default => "Engine error on {$name}: {$kind}",
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function alerts(DatabaseInstance $instance, array $report): void
    {
        $org = $instance->organization_id;
        $url = "/databases/instances/{$instance->id}";
        $for = (int) config('databases.observability.alert_seconds', 300);
        $deltas = (array) ($report['deltas'] ?? []);
        $gauges = (array) ($report['gauges'] ?? []);
        $window = max(1, (int) ($report['window_s'] ?? 60));
        $context = ['instance_id' => $instance->id];

        // The first report after an agent start has no deltas: nothing is known yet, nothing changes.
        if ((int) ($report['window_s'] ?? 0) > 0 && array_key_exists('slow_queries', $deltas)) {
            $perMinute = (float) $deltas['slow_queries'] * 60 / $window;
            $limit = (int) config('databases.observability.slow_spike_per_minute', 30);

            $this->conditions->observe($org, "databases.slow:{$instance->id}", $perMinute >= $limit, fn () => new AlertData(
                $org, self::ALERT_SLOW, Severity::Warning,
                "{$instance->name} on {$instance->server_name}: ".round($perMinute).' slow queries a minute',
                "At least {$limit} queries a minute take longer than the slow-query threshold. The database's Insights tab lists the query shapes; an index or a rewritten query usually fixes them.",
                $url, context: [...$context, 'per_minute' => round($perMinute, 1)], action: 'Review slow queries',
            ), forSeconds: $for);
        }

        $lag = $gauges['replication_lag_s'] ?? null;
        $lagLimit = (int) config('databases.observability.replication_lag_seconds', 60);
        $this->conditions->observe($org, "databases.lag:{$instance->id}", is_numeric($lag) && $lag >= $lagLimit, fn () => new AlertData(
            $org, self::ALERT_LAG, Severity::Warning,
            "{$instance->name} on {$instance->server_name} replicates ".(int) $lag.' s behind',
            "The replica is at least {$lagLimit} s behind its primary: reads from it are stale, and a failover would lose that much.",
            $url, context: [...$context, 'lag_s' => is_numeric($lag) ? (float) $lag : null], action: 'Check replication',
        ), forSeconds: $for);

        // Deadlocks are events, not a state: one alert per instance and hour at most (its dedup key).
        $deadlocks = (int) ($deltas['deadlocks'] ?? 0);

        if ($deadlocks > 0) {
            $this->alerts->raise(new AlertData(
                $org, self::ALERT_DEADLOCKS, Severity::Warning,
                "{$deadlocks} deadlock".($deadlocks === 1 ? '' : 's')." on {$instance->name}",
                'Transactions waited on each other and one was rolled back. Take locks in the same order everywhere and keep transactions short.',
                $url, "databases.deadlocks:{$instance->id}:".now()->format('YmdH'), context: [...$context, 'deadlocks' => $deadlocks], action: 'Review database issues',
            ));
        }

        $percent = self::memoryPercent($report);
        $memoryLimit = (int) config('databases.observability.memory_percent', 90);
        $this->conditions->observe($org, "databases.memory:{$instance->id}", $percent === null ? false : $percent >= $memoryLimit, fn () => new AlertData(
            $org, self::ALERT_MEMORY, Severity::Warning,
            "{$instance->name} on {$instance->server_name} uses ".round((float) $percent).'% of its memory',
            $instance->engine->isKeyValue()
                ? 'used_memory is close to maxmemory with noeviction: writes are refused once it is reached. Raise the memory or set an eviction policy.'
                : 'The container is close to its memory limit: the kernel kills the engine when it is reached. Raise the instance\'s memory.',
            $url, context: [...$context, 'percent' => $percent], action: 'Tune instance settings',
        ), forSeconds: $for);
    }

    /**
     * Memory used against the limit (%): Redis / Valkey used_memory / maxmemory with `noeviction` (writes are refused
     * at maxmemory; with an eviction policy reaching it is the normal state), else the container's cgroup. The agent
     * reports the cgroup's usage without the reclaimable page cache (inactive_file): an engine that reads a lot fills
     * the cache up to the limit and that is no pressure.
     *
     * @param  array<string, mixed>  $report
     */
    public static function memoryPercent(array $report): ?float
    {
        $gauges = (array) ($report['gauges'] ?? []);
        $policy = ((array) ($report['labels'] ?? []))['maxmemory_policy'] ?? null;

        if ((float) ($gauges['maxmemory'] ?? 0) > 0 && isset($gauges['used_memory']) && $policy === 'noeviction') {
            return round((float) $gauges['used_memory'] * 100 / (float) $gauges['maxmemory'], 1);
        }

        $memory = (array) ($report['memory'] ?? []);

        if ((int) ($memory['limit_bytes'] ?? 0) > 0 && isset($memory['used_bytes'])) {
            return round((int) $memory['used_bytes'] * 100 / (int) $memory['limit_bytes'], 1);
        }

        return null;
    }
}
