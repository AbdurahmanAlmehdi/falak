<?php

namespace Falak\Network\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;

/**
 * PromQL behind the TCP & DNS sections of the Network tabs (docs/plans/network/ebpf.md, "Queries and endpoints"). Every
 * query is scoped by the servers (falak_server_id, uppercase as the relay stamps it) and, for a site or a database
 * instance, by its workload labels.
 */
final class EbpfQueries
{
    private const MIN_WINDOW = 300;

    /**
     * @param  array<string, string>  $workload  label => value (falak_site_id, falak_db_instance_id, falak_compose_service); [] = the whole server
     * @param  list<string>  $serverIds
     * @return array<string, string> chart key => PromQL
     */
    public static function charts(array $workload, array $serverIds, int $step, bool $server = false): array
    {
        $sel = self::selector($workload, $serverIds);
        $w = max(self::MIN_WINDOW, $step).'s';
        $quantile = fn (string $q, string $metric) => "histogram_quantile({$q}, sum by (le) (rate({$metric}_bucket{{$sel}}[{$w}])))";

        return [
            'dns_queries' => "sum(rate(falak_net_dns_queries_total{{$sel}}[{$w}]))",
            'dns_failures' => "sum by (falak_net_dns_error) (rate(falak_net_dns_failures_total{{$sel}}[{$w}]))",
            'dns_p50' => $quantile('0.5', 'falak_net_dns_duration_seconds'),
            'dns_p95' => $quantile('0.95', 'falak_net_dns_duration_seconds'),
            'retransmits' => "topk(5, sum by (falak_net_peer) (rate(falak_net_tcp_retransmits_total{{$sel}}[{$w}])))",
            'resets' => "sum by (falak_net_reset_direction) (rate(falak_net_tcp_resets_total{{$sel}}[{$w}]))",
            'rtt_p50' => $quantile('0.5', 'falak_net_tcp_rtt_seconds'),
            'rtt_p95' => $quantile('0.95', 'falak_net_tcp_rtt_seconds'),
            'connect_p95' => $quantile('0.95', 'falak_net_tcp_connect_duration_seconds'),
            'connect_failed' => "sum(rate(falak_net_tcp_connects_total{{$sel},falak_net_outcome=\"failed\"}[{$w}]))",
            'runq_p50' => $quantile('0.5', 'falak_workload_runqueue_delay_seconds'),
            'runq_p95' => $quantile('0.95', 'falak_workload_runqueue_delay_seconds'),
            'runq_p99' => $quantile('0.99', 'falak_workload_runqueue_delay_seconds'),
            ...($server ? ['overhead' => "sum by (falak_ebpf_component) (rate(falak_ebpf_cpu_time_seconds_total{{$sel}}[{$w}]))"] : []),
        ];
    }

    /**
     * The most queried names over the range, with their failures (an instant query at the range's end).
     *
     * @param  array<string, string>  $workload
     * @param  list<string>  $serverIds
     * @return array{names: string}
     */
    public static function topNames(array $workload, array $serverIds, int $rangeSeconds): array
    {
        $sel = self::selector($workload, $serverIds);

        return [
            'names' => "topk(10, sum by (falak_net_dns_name) (increase(falak_net_dns_name_queries_total{{$sel},falak_net_dns_name!=\"other\"}[{$rangeSeconds}s])))",
        ];
    }

    /**
     * Alerts (docs/plans/network/ebpf.md, "Alerts"): per server and workload over the last 5 minutes, every server.
     *
     * @return array{dns_failures: string, dns_queries: string, retransmits: string}
     */
    public static function alerts(): array
    {
        $by = 'falak_server_id, service_name, falak_workload_name';

        return [
            'dns_failures' => "sum by ({$by}) (increase(falak_net_dns_failures_total{falak_net_dns_error!=\"nxdomain\"}[5m]))",
            'dns_queries' => "sum by ({$by}) (increase(falak_net_dns_queries_total[5m]))",
            'retransmits' => "sum by ({$by}) (increase(falak_net_tcp_retransmits_total[5m]))",
        ];
    }

    /**
     * @param  array<string, string>  $workload
     * @param  list<string>  $serverIds
     */
    private static function selector(array $workload, array $serverIds): string
    {
        $matchers = array_map(fn (string $label, string $value) => PromQl::label($label, $value), array_keys($workload), $workload);

        if ($serverIds !== []) {
            $pattern = implode('|', array_map(fn (string $id) => preg_quote(strtoupper($id), '/'), $serverIds));
            $matchers[] = 'falak_server_id=~'.PromQl::quote($pattern);
        }

        return implode(',', $matchers);
    }
}
