<?php

namespace Falak\Telemetry\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;

/**
 * Queries behind a site's Metrics tab (docs/plans/OBSERVABILITY.md, "The Metrics tab").
 *
 * - Traffic: request rate, 5xx rate and p95 latency from Tempo span metrics (`falak_site_id`), matching the
 *   falak-laravel-site dashboard; sites that send no spans get the same three from their edge access logs in Loki
 *   ({@see self::edge()}).
 * - Workload: the agent's `falak_workload_*` series of the site (its containers, its cgroup slice or the processes of
 *   its unix user), against its limits and the capacity of its servers. "Rest of the server" is the host's usage minus
 *   the site's, so the two stack to the server's real usage. Usage and capacity are summed over the selected servers;
 *   limits are per server (a limit applies on each server, it does not add up).
 * - Host: CPU / memory utilisation (%) of each server.
 */
final class SiteMetricQueries
{
    /** Shortest rate window of counters (cores used, bytes / s, throttled share), in seconds. */
    private const MIN_WINDOW = 300;

    /**
     * Rate windows are max(5m, step): a window shorter than the step would skip what happens between two points
     * (at 7d, a 5m window samples 5 minutes of every 30).
     *
     * @param  list<string>  $serverIds  the servers to read (all the site's servers, or the one picked)
     * @param  bool  $containers  the site runs in containers (network is per container; native sites get the server's)
     * @param  int  $step  range step in seconds (OOM kills are counted per step)
     * @return array<string, string> chart key => PromQL
     */
    public static function for(string $siteId, array $serverIds, bool $containers = false, int $step = 30): array
    {
        $step = max(1, $step);
        $w = max(self::MIN_WINDOW, $step).'s';
        $site = PromQl::label('falak_site_id', strtoupper($siteId));
        $request = $site.',falak_event_type="request"';
        $queries = [
            'requests' => "sum(rate(traces_spanmetrics_calls_total{{$request}}[{$w}]))",
            'errors' => "sum(rate(traces_spanmetrics_calls_total{{$request},http_response_status_code=~\"5..\"}[{$w}]))",
            'p95' => "histogram_quantile(0.95, sum by (le) (rate(traces_spanmetrics_latency_bucket{{$request}}[{$w}]))) * 1000",
        ];

        if ($serverIds === []) {
            return $queries;
        }

        $pattern = implode('|', array_map(fn (string $id) => preg_quote(strtoupper($id), '/'), $serverIds));
        $servers = 'falak_server_id=~'.PromQl::quote($pattern);
        $workload = "{$site},{$servers}";
        // OOM kills per step, over windows that do not overlap: the panel adds the points up into the range's total.
        $oomWindow = $step;

        $siteCores = "sum(rate(falak_workload_cpu_time_seconds_total{{$workload}}[{$w}]))";
        $siteUsed = "sum(falak_workload_memory_usage_bytes{{$workload},falak_memory_type=\"used\"})";
        // Busy host CPU time: everything but idle, iowait (a waiting CPU is free to run something else) and steal (time
        // the hypervisor gave to another guest: not this server's work).
        $hostCores = "sum(rate(system_cpu_time_seconds_total{{$servers},cpu_mode!~\"idle|iowait|steal\"}[{$w}]))";
        $hostUsed = "sum(system_memory_usage_bytes{{$servers},system_memory_state=\"used\"})";
        $service = 'falak_server_id, falak_compose_service, falak_workload_name';

        return $queries + [
            // Host utilisation (%) per server.
            'cpu' => "avg by (falak_server_id) (system_cpu_utilization_ratio{{$servers}}) * 100",
            'memory' => "avg by (falak_server_id) (system_memory_utilization_ratio{{$servers}}) * 100",

            // CPU, in cores.
            'site_cpu' => $siteCores,
            'site_cpu_rest' => "clamp_min({$hostCores} - ({$siteCores} or vector(0)), 0)",
            'site_cpu_limit' => "sum by (falak_server_id) (falak_workload_cpu_limit{{$workload}})",
            'server_cores' => "sum(system_cpu_logical_count{{$servers}})",
            'site_cpu_throttled' => "sum(rate(falak_workload_cpu_throttled_periods_total{{$workload}}[{$w}])) / sum(rate(falak_workload_cpu_periods_total{{$workload}}[{$w}])) * 100",

            // Memory, in bytes ("used" is anon + kernel; the page cache is reclaimable and shown apart, not stacked: the
            // host's "used" (total - available) leaves the cache out too, so used + rest = the server's used memory).
            'site_memory' => "sum by (falak_memory_type) (falak_workload_memory_usage_bytes{{$workload}})",
            'site_memory_rest' => "clamp_min({$hostUsed} - ({$siteUsed} or vector(0)), 0)",
            'site_memory_limit' => "sum by (falak_server_id) (falak_workload_memory_limit_bytes{{$workload}})",
            'server_memory' => "sum(system_memory_limit_bytes{{$servers}})",
            'site_oom_kills' => "sum(increase(falak_workload_memory_oom_kills_total{{$workload}}[{$oomWindow}s]))",

            // I/O, in bytes / s. Native sites have no network of their own (not attributable without eBPF).
            'site_disk_io' => "sum by (disk_io_direction) (rate(falak_workload_disk_io_bytes_total{{$workload}}[{$w}]))",
            ...($containers
                ? ['site_network' => "sum by (network_io_direction) (rate(falak_workload_network_io_bytes_total{{$workload}}[{$w}]))"]
                : ['server_network' => "sum by (network_io_direction) (rate(system_network_io_bytes_total{{$servers},network_interface_name!=\"lo\"}[{$w}]))"]),

            // Processes.
            'site_pids' => "sum(falak_workload_pids{{$workload}})",
            'site_pids_limit' => "sum by (falak_server_id) (falak_workload_pids_limit{{$workload}})",

            // One row per compose service / container (per server).
            'services_cpu' => "sum by ({$service}) (rate(falak_workload_cpu_time_seconds_total{{$workload}}[{$w}]))",
            'services_cpu_limit' => "sum by ({$service}) (falak_workload_cpu_limit{{$workload}})",
            'services_memory' => "sum by ({$service}) (falak_workload_memory_usage_bytes{{$workload},falak_memory_type=\"used\"})",
            'services_memory_limit' => "sum by ({$service}) (falak_workload_memory_limit_bytes{{$workload}})",
        ];
    }

    /**
     * Requests / s, 5xx / s and p95 latency (ms) from the site's edge access log, for sites that send no spans. Same
     * stream selector as the access log itself ({@see LogQueryBuilder::access()}: by slug, so load balancers in front of
     * the site count too); the control plane's own health checks are left out. The access records carry OpenTelemetry
     * HTTP attributes as structured metadata (`http_response_status_code`, `http_server_duration_ms`): the line itself
     * is plain text ("GET /cart 503 4.0ms"), so nothing is parsed.
     *
     * @param  int  $step  range step in seconds: requests are counted per step (no request is missed between steps)
     * @return array{requests: string, errors: string, p95: string} chart key => LogQL
     */
    public static function edge(string $organizationId, string $siteSlug, ?string $serverId, int $step, string $probeAgent): array
    {
        $selector = LogQueryBuilder::access($organizationId, $siteSlug, ['server_id' => $serverId])
            .' | user_agent_original!~'.PromQl::quote($probeAgent.'.*');
        $step = max(1, $step);
        $window = max(60, $step);

        return [
            'requests' => "sum(count_over_time({$selector} [{$step}s])) / {$step}",
            // A status that is not a number (a record without one) is a filter error: dropped, not failing the query.
            'errors' => "sum(count_over_time({$selector} | http_response_status_code >= 500 | __error__=\"\" [{$step}s])) / {$step}",
            'p95' => "quantile_over_time(0.95, {$selector} | unwrap http_server_duration_ms | __error__=\"\" [{$window}s]) by ()",
        ];
    }
}
