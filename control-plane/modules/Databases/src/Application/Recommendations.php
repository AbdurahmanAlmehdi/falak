<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Enums\Engine;

/**
 * Recommendations for one instance (docs/plans/OBSERVABILITY.md phase 3, "Recommendations"), computed on read from
 * the agent's latest report and the daily size samples. Pure: no database, no clock but the samples' days.
 */
final class Recommendations
{
    private const MB = 1024 ** 2;

    /**
     * @param  array<string, mixed>  $report  DatabaseInstance::$observed
     * @param  array<string, int>  $sizes  day (Y-m-d) => bytes, oldest first
     * @param  ?int  $volumeBytes  the data volume's size, when known
     * @param  ?int  $memoryBytes  the instance's memory limit, when known (MariaDB's performance_schema needs 1 GiB)
     * @return list<array{key: string, severity: 'info'|'warning'|'critical', title: string, detail: string}>
     */
    public static function for(Engine $engine, array $report, array $sizes = [], ?int $volumeBytes = null, ?int $memoryBytes = null): array
    {
        $out = [];
        $gauges = (array) ($report['gauges'] ?? []);
        $deltas = (array) ($report['deltas'] ?? []);
        $settings = (array) ($report['settings'] ?? []);
        $labels = (array) ($report['labels'] ?? []);

        // Sequential scans of large tables (PostgreSQL).
        if ($engine === Engine::PostgreSql) {
            foreach ((array) ($report['tables'] ?? []) as $t) {
                $scans = (int) ($t['seq_scan'] ?? 0);
                $size = (int) ($t['size_bytes'] ?? 0);

                if ($scans > 1000 && $size > 100 * self::MB && (int) ($t['seq_tup_read'] ?? 0) / $scans > 10000 && $scans > (int) ($t['idx_scan'] ?? 0)) {
                    $name = self::table($t);
                    $out[] = [
                        'key' => "seq_scan:{$name}",
                        'severity' => 'warning',
                        'title' => "Add an index to {$name}",
                        'detail' => sprintf('%s rows read by %s sequential scans of this %s table (%s index scans). An index on the columns its queries filter by avoids reading the whole table.',
                            number_format((int) $t['seq_tup_read']), number_format($scans), self::bytes($size), number_format((int) ($t['idx_scan'] ?? 0))),
                    ];
                }
            }
        }

        // Unused indexes: statistics old enough to tell.
        $age = (int) ($settings['stats_age_s'] ?? $settings['uptime_s'] ?? 0);

        if (! $engine->isKeyValue() && $age >= 7 * 86400) {
            foreach ((array) ($report['indexes'] ?? []) as $i) {
                $size = (int) ($i['size_bytes'] ?? 0);

                if ((int) ($i['scans'] ?? 0) === 0 && ($size > 10 * self::MB || $engine !== Engine::PostgreSql)) {
                    $table = self::table($i, 'table');
                    $out[] = [
                        'key' => "unused_index:{$table}.{$i['name']}",
                        'severity' => 'info',
                        'title' => "Drop unused index {$i['name']} on {$table}",
                        'detail' => sprintf('Not used once in %d days%s. Every write still maintains it. Check replicas and rare jobs before dropping it.',
                            intdiv($age, 86400), $size > 0 ? ', '.self::bytes($size) : ''),
                    ];
                }
            }
        }

        // Statements without an index (MySQL / MariaDB digests).
        $noIndex = (array) ($report['no_index_statements'] ?? []);

        if ($noIndex !== []) {
            $out[] = [
                'key' => 'no_index',
                'severity' => 'warning',
                'title' => count($noIndex).' frequent '.(count($noIndex) === 1 ? 'query runs' : 'queries run').' without an index',
                'detail' => 'For example: '.mb_substr((string) $noIndex[0]['query'], 0, 200),
            ];
        }

        // Cache hit ratio.
        $hits = (float) ($deltas['cache_hits'] ?? 0);
        $misses = (float) ($deltas['cache_misses'] ?? 0);
        $minimum = $engine === Engine::PostgreSql ? 0.95 : 0.99;

        if (! $engine->isKeyValue() && $hits + $misses >= 10000 && $hits / ($hits + $misses) < $minimum) {
            $setting = $engine === Engine::PostgreSql ? 'shared_buffers' : 'innodb_buffer_pool_size';
            $current = (int) ($settings[$engine === Engine::PostgreSql ? 'shared_buffers_bytes' : 'innodb_buffer_pool_bytes'] ?? 0);
            $out[] = [
                'key' => 'cache_hit',
                'severity' => 'warning',
                'title' => 'Raise the memory: the cache hit ratio is '.round($hits * 100 / ($hits + $misses), 1).'%',
                'detail' => sprintf('Below %d%%, reads go to disk. %s%s is tuned to the instance\'s memory limit: raise the memory to grow it.',
                    (int) ($minimum * 100), $setting, $current > 0 ? ' ('.self::bytes($current).')' : ''),
            ];
        }

        // Redis / Valkey near maxmemory without eviction.
        $max = (float) ($gauges['maxmemory'] ?? 0);

        if ($engine->isKeyValue() && $max > 0 && (float) ($gauges['used_memory'] ?? 0) >= 0.9 * $max && ($labels['maxmemory_policy'] ?? 'noeviction') === 'noeviction') {
            $out[] = [
                'key' => 'maxmemory',
                'severity' => 'critical',
                'title' => 'Near maxmemory with noeviction: writes will be refused',
                'detail' => sprintf('%s of %s used. Raise the memory, or set an eviction policy (allkeys-lru for a cache).', self::bytes((int) $gauges['used_memory']), self::bytes((int) $max)),
            ];
        }

        // Connections near the limit.
        $maxConnections = (float) ($gauges['max_connections'] ?? 0);

        if ($maxConnections > 0 && (float) ($gauges['connections'] ?? 0) >= 0.8 * $maxConnections) {
            $out[] = [
                'key' => 'connections',
                'severity' => 'warning',
                'title' => sprintf('%d of %d connections in use', (int) $gauges['connections'], (int) $maxConnections),
                'detail' => 'New connections are refused at the limit. Look for leaks, put a pooler in front, or raise max_connections.',
            ];
        }

        // Disk forecast.
        if ($volumeBytes !== null && $volumeBytes > 0 && ($days = self::daysUntilFull($sizes, $volumeBytes)) !== null && $days <= 30) {
            $out[] = [
                'key' => 'disk_full',
                'severity' => $days <= 7 ? 'critical' : 'warning',
                'title' => $days <= 0 ? 'The data volume is full' : "The data volume fills up in about {$days} days",
                'detail' => sprintf('At the growth of the last %d days, the databases reach the volume\'s %s. Grow the volume or clean up.', count($sizes), self::bytes($volumeBytes)),
            ];
        }

        $insightsOff = in_array($engine, [Engine::PostgreSql, Engine::MariaDb], true) && (($report['query_insights']['status'] ?? null) === 'off');

        if ($insightsOff && $memoryBytes !== null && ! QueryInsights::engineAllows($engine, $memoryBytes)) {
            // MariaDB below 1 GiB: performance_schema would eat into the memory limit.
            $out[] = [
                'key' => 'query_insights_memory',
                'severity' => 'info',
                'title' => 'Top queries need 1 GiB of memory',
                'detail' => 'Below 1 GiB, MariaDB runs without performance_schema and top queries come from the slow log. Raise the memory to 1 GiB or more to enable query insights.',
            ];
        } elseif ($insightsOff) {
            $out[] = [
                'key' => 'query_insights',
                'severity' => 'info',
                'title' => 'Enable query insights',
                'detail' => $engine === Engine::PostgreSql
                    ? 'pg_stat_statements shows every query shape with its calls and time. It needs a restart.'
                    : 'performance_schema statement digests show every query shape with its calls and time. It needs a restart.',
            ];
        }

        usort($out, fn (array $a, array $b) => self::rank($b['severity']) <=> self::rank($a['severity']));

        return $out;
    }

    /**
     * Days until the samples' linear trend reaches $volumeBytes (0: already there), or null: not growing, or fewer than
     * three samples.
     *
     * @param  array<string, int>  $sizes  day => bytes
     */
    public static function daysUntilFull(array $sizes, int $volumeBytes): ?int
    {
        if (count($sizes) < 3) {
            return null;
        }

        $first = null;
        $points = [];

        foreach ($sizes as $day => $bytes) {
            $t = intdiv((int) strtotime((string) $day), 86400);
            $first ??= $t;
            $points[] = [(float) ($t - $first), (float) $bytes];
        }

        $n = count($points);
        $mx = array_sum(array_column($points, 0)) / $n;
        $my = array_sum(array_column($points, 1)) / $n;
        $den = 0.0;
        $num = 0.0;

        foreach ($points as [$x, $y]) {
            $num += ($x - $mx) * ($y - $my);
            $den += ($x - $mx) ** 2;
        }

        $last = end($points);

        if ($last[1] >= $volumeBytes) {
            return 0;
        }

        if ($den <= 0 || ($slope = $num / $den) <= 0) {
            return null;
        }

        return (int) ceil(($volumeBytes - $last[1]) / $slope);
    }

    private static function rank(string $severity): int
    {
        return match ($severity) {
            'critical' => 2,
            'warning' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function table(array $row, string $key = 'name'): string
    {
        $schema = (string) ($row['schema'] ?? '');
        $name = (string) ($row[$key] ?? '');

        return ($row['database'] ?? '') !== '' ? "{$row['database']}.".($schema !== '' && $schema !== 'public' ? "{$schema}." : '').$name : $name;
    }

    private static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $bytes >= 10 || $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
