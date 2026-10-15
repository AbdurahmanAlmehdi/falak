<?php

use Falak\Telemetry\Application\Queries\SiteMetricQueries;

const QUERIES_SITE = '01jsqte000000000000000000a';
const QUERIES_SERVER_A = '01jsrva000000000000000000a';
const QUERIES_SERVER_B = '01jsrvb000000000000000000b';

it('asks only for the traffic of a site on no server', function () {
    expect(array_keys(SiteMetricQueries::for(QUERIES_SITE, [])))->toBe(['requests', 'errors', 'p95']);
});

it('reads the site\'s workload against its limits and its servers', function () {
    $q = SiteMetricQueries::for(QUERIES_SITE, [QUERIES_SERVER_A, QUERIES_SERVER_B], step: 300);
    $workload = '{falak_site_id="01JSQTE000000000000000000A",falak_server_id=~"01JSRVA000000000000000000A|01JSRVB000000000000000000B"}';
    $servers = '{falak_server_id=~"01JSRVA000000000000000000A|01JSRVB000000000000000000B"';

    expect($q['site_cpu'])->toBe("sum(rate(falak_workload_cpu_time_seconds_total{$workload}[300s]))")
        // The rest of the server is the host's busy CPU minus the site's (zero when the site reports nothing yet).
        ->and($q['site_cpu_rest'])->toBe("clamp_min(sum(rate(system_cpu_time_seconds_total{$servers},cpu_mode!~\"idle|iowait|steal\"}[300s])) - (sum(rate(falak_workload_cpu_time_seconds_total{$workload}[300s])) or vector(0)), 0)")
        // Limits apply per server; capacity adds up.
        ->and($q['site_cpu_limit'])->toBe("sum by (falak_server_id) (falak_workload_cpu_limit{$workload})")
        ->and($q['server_cores'])->toBe("sum(system_cpu_logical_count{$servers}})")
        ->and($q['site_cpu_throttled'])->toBe("sum(rate(falak_workload_cpu_throttled_periods_total{$workload}[300s])) / sum(rate(falak_workload_cpu_periods_total{$workload}[300s])) * 100")
        ->and($q['site_memory'])->toBe("sum by (falak_memory_type) (falak_workload_memory_usage_bytes{$workload})")
        ->and($q['site_memory_rest'])->toContain("sum(system_memory_usage_bytes{$servers},system_memory_state=\"used\"})")
        ->and($q['server_memory'])->toBe("sum(system_memory_limit_bytes{$servers}})")
        ->and($q['site_oom_kills'])->toBe("sum(increase(falak_workload_memory_oom_kills_total{$workload}[300s]))")
        ->and($q['site_disk_io'])->toBe("sum by (disk_io_direction) (rate(falak_workload_disk_io_bytes_total{$workload}[300s]))")
        ->and($q['site_pids_limit'])->toBe("sum by (falak_server_id) (falak_workload_pids_limit{$workload})")
        ->and($q['services_memory'])->toBe('sum by (falak_server_id, service_name, falak_compose_service, falak_workload_name) (falak_workload_memory_usage_bytes'.substr($workload, 0, -1).',falak_memory_type="used"})');
});

it('widens rate windows to the step and counts OOM kills over windows that do not overlap', function () {
    $hour = SiteMetricQueries::for(QUERIES_SITE, [QUERIES_SERVER_A], step: 30);
    $week = SiteMetricQueries::for(QUERIES_SITE, [QUERIES_SERVER_A], step: 1800);

    // 1h (30 s steps): 5 minute rate windows; one OOM window per step, so adding the points up counts each kill once.
    expect($hour['site_cpu'])->toEndWith('[300s]))')
        ->and($hour['requests'])->toEndWith('[300s]))')
        ->and($hour['site_oom_kills'])->toEndWith('[30s]))')
        // 7d (30 minute steps): a 5 minute window would skip 25 minutes of every point.
        ->and($week['site_cpu'])->toEndWith('[1800s]))')
        ->and($week['site_disk_io'])->toEndWith('[1800s]))')
        ->and($week['p95'])->toContain('[1800s]')
        ->and($week['site_oom_kills'])->toEndWith('[1800s]))');

    foreach ([$hour, $week] as $queries) {
        foreach ($queries as $key => $query) {
            expect($query)->not->toContain('[5m]', $key);
        }
    }
});

it('shows a native site the server\'s network and a container site its own', function () {
    $native = SiteMetricQueries::for(QUERIES_SITE, [QUERIES_SERVER_A]);
    $containers = SiteMetricQueries::for(QUERIES_SITE, [QUERIES_SERVER_A], containers: true);

    expect($native)->toHaveKey('server_network')->not->toHaveKey('site_network')
        ->and($native['server_network'])->toBe('sum by (network_io_direction) (rate(system_network_io_bytes_total{falak_server_id=~"01JSRVA000000000000000000A",network_interface_name!="lo"}[300s]))')
        ->and($containers)->toHaveKey('site_network')->not->toHaveKey('server_network')
        ->and($containers['site_network'])->toBe('sum by (network_io_direction) (rate(falak_workload_network_io_bytes_total{falak_site_id="01JSQTE000000000000000000A",falak_server_id=~"01JSRVA000000000000000000A"}[300s]))');
});

it('counts edge requests per step and leaves health checks out', function () {
    $q = SiteMetricQueries::edge('01jorg0000000000000000000a', 'shop', null, 30, 'Falak-HealthCheck/');
    $selector = '{falak_org_id="01JORG0000000000000000000A", service_name="shop", falak_log_kind="access"} | user_agent_original!~"Falak-HealthCheck/.*"';

    expect($q)->toBe([
        'requests' => "sum(count_over_time({$selector} [30s])) / 30",
        // A record whose status is not a number is dropped, not an error of the whole query.
        'errors' => "sum(count_over_time({$selector} | http_response_status_code >= 500 | __error__=\"\" [30s])) / 30",
        // Quantiles need a minute of requests to mean anything.
        'p95' => "quantile_over_time(0.95, {$selector} | unwrap http_server_duration_ms | __error__=\"\" [60s]) by ()",
    ]);
});
