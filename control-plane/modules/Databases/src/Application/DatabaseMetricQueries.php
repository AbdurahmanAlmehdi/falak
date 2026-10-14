<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Telemetry\Contracts\PromQl;

/**
 * Queries behind a database instance's Metrics tab (docs/plans/OBSERVABILITY.md phase 3): the engine's own `falak_db_*`
 * series from `falak-db observe`, and phase 1's container series (`falak_workload_*`) against the server's capacity
 * and the instance's limits. Instances are matched by `falak_db_instance_id`, the instance id in lowercase (server ids
 * are uppercase in VictoriaMetrics).
 */
final class DatabaseMetricQueries
{
    /** Shortest rate window of counters, in seconds (as a site's charts). */
    private const MIN_WINDOW = 300;

    /**
     * @param  int  $step  range step in seconds (deadlocks are counted per step)
     * @return array<string, string> chart key => PromQL
     */
    public static function for(string $instanceId, string $serverId, Engine $engine, int $step = 30): array
    {
        $step = max(1, $step);
        $w = max(self::MIN_WINDOW, $step).'s';
        $inst = PromQl::label('falak_db_instance_id', strtolower($instanceId));
        $server = PromQl::label('falak_server_id', strtoupper($serverId));
        $rate = fn (string $metric, string $by = '') => 'sum'.($by !== '' ? " by ({$by})" : '')."(rate({$metric}{{$inst}}[{$w}]))";

        $dbCores = "sum(rate(falak_workload_cpu_time_seconds_total{{$inst}}[{$w}]))";
        $dbUsed = "sum(falak_workload_memory_usage_bytes{{$inst},falak_memory_type=\"used\"})";
        $hostCores = "sum(rate(system_cpu_time_seconds_total{{$server},cpu_mode!~\"idle|iowait|steal\"}[{$w}]))";
        $hostUsed = "sum(system_memory_usage_bytes{{$server},system_memory_state=\"used\"})";

        $queries = [
            'up' => "max(falak_db_up{{$inst}})",
            'connections' => "sum by (falak_db_connection_state) (falak_db_connections{{$inst}})",
            'connections_max' => "max(falak_db_connections_max{{$inst}})",
            // Slow queries a minute.
            'slow_queries' => $rate('falak_db_slow_queries_total').' * 60',
            'cache_hit' => "sum(rate(falak_db_cache_requests_total{{$inst},falak_db_cache_result=\"hit\"}[{$w}])) / sum(rate(falak_db_cache_requests_total{{$inst}}[{$w}])) * 100",

            // The container (phase 1) against the server and the instance's limits.
            'cpu' => $dbCores,
            'cpu_rest' => "clamp_min({$hostCores} - ({$dbCores} or vector(0)), 0)",
            'cpu_limit' => "max(falak_workload_cpu_limit{{$inst}})",
            'server_cores' => "sum(system_cpu_logical_count{{$server}})",
            'memory' => "sum by (falak_memory_type) (falak_workload_memory_usage_bytes{{$inst}})",
            'memory_rest' => "clamp_min({$hostUsed} - ({$dbUsed} or vector(0)), 0)",
            'memory_limit' => "max(falak_workload_memory_limit_bytes{{$inst}})",
            'server_memory' => "sum(system_memory_limit_bytes{{$server}})",
            'oom_kills' => "sum(increase(falak_workload_memory_oom_kills_total{{$inst}}[{$step}s]))",
            'disk_io' => "sum by (disk_io_direction) (rate(falak_workload_disk_io_bytes_total{{$inst}}[{$w}]))",
        ];

        if ($engine->isKeyValue()) {
            return $queries + [
                'ops' => $rate('falak_db_queries_total'),
                'used_memory' => "max(falak_db_memory_usage_bytes{{$inst}})",
                'maxmemory' => "max by (falak_db_maxmemory_policy) (falak_db_memory_limit_bytes{{$inst}})",
                'evicted' => $rate('falak_db_keys_evicted_total'),
                'expired' => $rate('falak_db_keys_expired_total'),
                'keys' => "sum by (falak_db_name) (falak_db_keys{{$inst}})",
                'rejected' => $rate('falak_db_connections_aborted_total'),
                'network' => $rate('falak_db_network_io_bytes_total', 'network_io_direction'),
            ];
        }

        $sql = [
            'transactions' => $rate('falak_db_transactions_total', 'falak_db_transaction_outcome'),
            'rows' => $rate('falak_db_rows_total', 'falak_db_row_operation'),
            // Deadlocks per step, over windows that do not overlap (the panel adds the points up).
            'deadlocks' => "sum(increase(falak_db_deadlocks_total{{$inst}}[{$step}s]))",
            'replication_lag' => "max(falak_db_replication_lag_seconds{{$inst}})",
            'sizes' => "sum by (falak_db_name) (falak_db_size_bytes{{$inst}})",
            'table_sizes' => "topk(10, sum by (falak_db_name, falak_db_table) (falak_db_table_size_bytes{{$inst}}))",
        ];

        if ($engine === Engine::PostgreSql) {
            return $queries + $sql + [
                'locks_waiting' => "max(falak_db_locks_waiting{{$inst}})",
                'temp_io' => $rate('falak_db_temp_io_bytes_total'),
            ];
        }

        return $queries + $sql + [
            'queries' => $rate('falak_db_queries_total'),
            'operations' => $rate('falak_db_operations_total', 'falak_db_operation'),
            'lock_waits' => $rate('falak_db_lock_waits_total'),
            'lock_wait_time' => $rate('falak_db_lock_wait_time_seconds_total'),
            'aborted' => $rate('falak_db_connections_aborted_total'),
            'network' => $rate('falak_db_network_io_bytes_total', 'network_io_direction'),
        ];
    }
}
