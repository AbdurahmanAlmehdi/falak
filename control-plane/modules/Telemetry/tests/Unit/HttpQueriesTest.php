<?php

use Falak\Telemetry\Application\Queries\HttpQueries;

/**
 * The Prometheus name VictoriaMetrics gives an OTLP metric with -opentelemetry.usePrometheusNaming=true: dots become
 * underscores, curly-brace units are dropped, known units are spelled out (By → bytes, s → seconds, ms → milliseconds),
 * gauges with unit "1" get _ratio, counters _total; histograms are read through their _bucket / _sum / _count series.
 */
function prometheus_name(string $name, string $unit, string $type): string
{
    $out = str_replace('.', '_', $name);
    $unit = preg_replace('/\{[^}]*\}/', '', $unit);
    $suffix = ['By' => 'bytes', 's' => 'seconds', 'ms' => 'milliseconds'][$unit] ?? ($unit === '1' && $type === 'gauge' ? 'ratio' : $unit);

    if ($suffix !== '' && ! str_ends_with($out, '_'.$suffix)) {
        $out .= '_'.$suffix;
    }

    return $type === 'counter' ? $out.'_total' : $out;
}

it('queries the names the agent\'s falak.net.http.* metrics get in VictoriaMetrics', function () {
    // As emitted by agent/internal/httpstats (name, unit, type); the Go test TestAggregatorMetricNames pins the same list.
    $metrics = [
        ['falak.net.http.requests', '{request}', 'counter'],
        ['falak.net.http.io', 'By', 'counter'],
        ['falak.net.http.server.duration', 'ms', 'histogram'],
        ['falak.net.http.protocol', '{request}', 'counter'],
        ['falak.net.http.tls', '{request}', 'counter'],
        ['falak.net.http.threats', '{request}', 'counter'],
    ];
    $known = [];

    foreach ($metrics as [$name, $unit, $type]) {
        $base = prometheus_name($name, $unit, $type);
        $known = [...$known, ...($type === 'histogram' ? ["{$base}_bucket", "{$base}_sum", "{$base}_count"] : [$base])];
    }

    expect($known)->toContain('falak_net_http_protocol_total', 'falak_net_http_tls_total', 'falak_net_http_threats_total',
        'falak_net_http_io_bytes_total', 'falak_net_http_server_duration_milliseconds_bucket');

    $sel = HttpQueries::metricSelector('01jorg0000000000000000000a', 'shop');
    $promql = implode("\n", [...HttpQueries::charts($sel, 30), HttpQueries::perSite($sel, 30), ...HttpQueries::bandwidthHour('01jorg0000000000000000000a')]);
    preg_match_all('/falak_net_http_[a-z_]+/', $promql, $m);
    $used = array_values(array_unique($m[0]));

    expect($used)->not->toBeEmpty();

    foreach ($used as $name) {
        expect($known)->toContain($name);
    }
});

it('bounds the organization-wide evaluation queries', function () {
    $sel = HttpQueries::organizationAccess('01jorg0000000000000000000a', 'Falak-HealthCheck/');
    $window = HttpQueries::window($sel, 100);

    expect($window['not_found'])->toEndWith('>= 100')
        ->and($window['threat_clients'])->toStartWith('topk by (service_name, falak_threat_rule) (5,');

    $slow = HttpQueries::slowRoutes($sel, 800, 20, 1000);
    expect($slow['p95'])->toStartWith('topk(1000, quantile_over_time(0.95,')->toEndWith(' >= 800)')
        ->and($slow['count'])->toStartWith('topk(1000, sum by (service_name, url_route)')->toEndWith(' >= 20)');
});
