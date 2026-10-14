<?php

namespace Falak\Telemetry\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;
use InvalidArgumentException;

/**
 * Queries behind the HTTP tab and the traffic evaluations (docs/plans/network/http.md). Charts read the agent's
 * per-site counters (`falak_net_http_*`, PromQL); drill-down tables and decisions read the access log (LogQL), evaluated
 * once over the whole range. Sites are selected by organization and slug (`service_name`), like their access log, so
 * the requests a load balancer routes count too.
 */
final class HttpQueries
{
    /** Drill-down tables (`table` of the top endpoint). */
    public const TABLES = ['paths', 'slow', 'clients', 'agents', 'status', 'countries', 'threats'];

    /**
     * Metric selector of one site or (slug null) every site of the organization, optionally on one server.
     */
    public static function metricSelector(string $organizationId, ?string $siteSlug, ?string $serverId = null): string
    {
        $m = [PromQl::label('falak_org_id', strtoupper($organizationId))];

        if ($siteSlug !== null) {
            $m[] = PromQl::label('service_name', $siteSlug);
        }

        if ($serverId !== null) {
            $m[] = PromQl::label('falak_server_id', strtoupper($serverId));
        }

        return implode(',', $m);
    }

    /**
     * PromQL charts from the agent's aggregates. Rate windows are max(5m, step), as on the Metrics tab.
     *
     * @return array<string, string>
     */
    public static function charts(string $selector, int $step): array
    {
        $w = max(300, $step).'s';

        return [
            'requests' => "sum by (http_response_status_class) (rate(falak_net_http_requests_total{{$selector}}[{$w}]))",
            'bytes' => "sum by (network_io_direction) (rate(falak_net_http_io_bytes_total{{$selector}}[{$w}]))",
            'p95' => "histogram_quantile(0.95, sum by (le) (rate(falak_net_http_server_duration_milliseconds_bucket{{$selector}}[{$w}])))",
            'protocols' => "sum by (network_protocol_version) (rate(falak_net_http_protocol_total{{$selector}}[{$w}]))",
            'tls' => "sum by (tls_protocol_version) (rate(falak_net_http_tls_total{{$selector}}[{$w}]))",
            'threats' => "sum by (falak_threat_category) (rate(falak_net_http_threats_total{{$selector}}[{$w}]))",
        ];
    }

    /**
     * Per-site requests / s on a server's edge (the server card).
     */
    public static function perSite(string $selector, int $step): string
    {
        return 'sum by (service_name) (rate(falak_net_http_requests_total{'.$selector.'}['.max(300, $step).'s]))';
    }

    /**
     * The same charts from the access log, for sites whose agents send no aggregates (before v0.17). Protocols, TLS
     * and threats need the new record fields and stay empty.
     *
     * @return array<string, string>
     */
    public static function logCharts(string $selector, int $step): array
    {
        $step = max(1, $step);
        $window = max(60, $step);

        return [
            'requests' => "sum by (http_response_status_class) (label_replace(sum by (http_response_status_code) (count_over_time({$selector} [{$step}s])), \"http_response_status_class\", \"\${1}xx\", \"http_response_status_code\", \"(\\\\d).*\")) / {$step}",
            'bytes' => "sum(sum_over_time({$selector} | unwrap http_response_body_size | __error__=\"\" [{$step}s])) / {$step}",
            'p95' => "quantile_over_time(0.95, {$selector} | unwrap http_server_duration_ms | __error__=\"\" [{$window}s]) by ()",
        ];
    }

    /**
     * One drill-down table over $rangeSeconds (an instant LogQL query at the range's end).
     */
    public static function table(string $table, string $selector, int $rangeSeconds, int $limit = 20): string
    {
        $r = max(60, $rangeSeconds).'s';

        return match ($table) {
            'paths' => "topk({$limit}, sum by (url_route) (count_over_time({$selector} | url_route!=\"\" [{$r}])))",
            'slow' => "topk({$limit}, quantile_over_time(0.95, {$selector} | url_route!=\"\" | unwrap http_server_duration_ms | __error__=\"\" [{$r}]) by (url_route))",
            'clients', 'countries' => "topk({$limit}, sum by (client_address) (count_over_time({$selector} | client_address!=\"\" [{$r}])))",
            'agents' => "topk({$limit}, sum by (user_agent_original) (count_over_time({$selector} [{$r}])))",
            'status' => "sum by (http_response_status_code) (count_over_time({$selector} [{$r}]))",
            'threats' => "topk({$limit}, sum by (client_address, falak_threat_rule) (count_over_time({$selector} | falak_threat_rule!=\"\" [{$r}])))",
            default => throw new InvalidArgumentException("Unknown table [{$table}]."),
        };
    }

    /** Requests per route over $r, to leave rarely called routes out of the slowest table. */
    public static function routeCounts(string $selector, int $rangeSeconds): string
    {
        return 'sum by (url_route) (count_over_time('.$selector.' | url_route!="" ['.max(60, $rangeSeconds).'s]))';
    }

    /** 404s per client over $r (404 storms in the Threats table). */
    public static function notFoundByClient(string $selector, int $rangeSeconds, int $min): string
    {
        return 'sum by (client_address) (count_over_time('.$selector.' | http_response_status_code="404" ['.max(60, $rangeSeconds).'s])) >= '.$min;
    }

    /**
     * Organization-wide access selector (every site), health checks left out: the evaluations group by service_name.
     */
    public static function organizationAccess(string $organizationId, string $probeAgent): string
    {
        return '{'.PromQl::label('falak_org_id', strtoupper($organizationId)).', falak_log_kind="access"} | user_agent_original!~'.PromQl::quote($probeAgent.'.*');
    }

    /**
     * Slow endpoints: p95 and request count per site and route over the last hour. Both are bounded so a busy
     * organization never reaches Loki's series limit: p95 only from $minP95Ms (the lowest recovery bound of the
     * organization's sites, so a counted route missing from the answer is known to be fast), counts only from
     * $minCount, and at most $limit series each (an answer with $limit series may be cut: its missing routes are
     * unknown, not fast).
     *
     * @return array{p95: string, count: string}
     */
    public static function slowRoutes(string $organizationAccess, float $minP95Ms, int $minCount, int $limit): array
    {
        $min = rtrim(rtrim(number_format($minP95Ms, 3, '.', ''), '0'), '.');

        return [
            'p95' => "topk({$limit}, quantile_over_time(0.95, {$organizationAccess} | url_route!=\"\" | unwrap http_server_duration_ms | __error__=\"\" [1h]) by (service_name, url_route) >= {$min})",
            'count' => "topk({$limit}, sum by (service_name, url_route) (count_over_time({$organizationAccess} | url_route!=\"\" [1h])) >= {$minCount})",
        ];
    }

    /**
     * The 5-minute window of the evaluations: threat hits per site and rule, the top clients per site and rule, clients
     * with at least $storm 404s (404 storms), requests and 5xx per site. Series are bounded by the number of sites
     * (and rules), or by the storm threshold.
     *
     * @return array<string, string>
     */
    public static function window(string $organizationAccess, int $storm): array
    {
        return [
            'threats' => "sum by (service_name, falak_threat_rule) (count_over_time({$organizationAccess} | falak_threat_rule!=\"\" [5m]))",
            'threat_clients' => "topk by (service_name, falak_threat_rule) (5, sum by (service_name, falak_threat_rule, client_address) (count_over_time({$organizationAccess} | falak_threat_rule!=\"\" [5m])))",
            'not_found' => "sum by (service_name, client_address) (count_over_time({$organizationAccess} | http_response_status_code=\"404\" [5m])) >= {$storm}",
            'requests' => "sum by (service_name) (count_over_time({$organizationAccess} [5m]))",
            'errors' => "sum by (service_name) (count_over_time({$organizationAccess} | http_response_status_code >= 500 | __error__=\"\" [5m]))",
        ];
    }

    /**
     * Bytes and requests per site in the hour ending at the query's time, from the aggregates.
     *
     * @return array{bytes: string, requests: string}
     */
    public static function bandwidthHour(string $organizationId): array
    {
        $o = PromQl::label('falak_org_id', strtoupper($organizationId));

        return [
            'bytes' => "sum by (service_name, falak_server_id, network_io_direction) (increase(falak_net_http_io_bytes_total{{$o}}[1h]))",
            'requests' => "sum by (service_name, falak_server_id) (increase(falak_net_http_requests_total{{$o}}[1h]))",
        ];
    }

    /**
     * The same hour from the access log (sites without aggregates).
     *
     * @return array{bytes_out: string, bytes_in: string, requests: string}
     */
    public static function bandwidthHourFromLogs(string $organizationAccess): array
    {
        return [
            'bytes_out' => "sum by (service_name, falak_server_id) (sum_over_time({$organizationAccess} | unwrap http_response_body_size | __error__=\"\" [1h]))",
            'bytes_in' => "sum by (service_name, falak_server_id) (sum_over_time({$organizationAccess} | unwrap http_request_body_size | __error__=\"\" [1h]))",
            'requests' => "sum by (service_name, falak_server_id) (count_over_time({$organizationAccess} [1h]))",
        ];
    }
}
