<?php

namespace Falak\Network\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;

/**
 * PromQL behind the Network tabs (docs/plans/network/connections.md, "Metrics"). Every query is scoped by the servers
 * (falak_server_id, uppercase as the relay stamps it) and, for a site or a database instance, by its workload.
 */
final class ConnectionQueries
{
    /** range => [seconds, step], as the Metrics tabs. */
    public const RANGES = [
        '1h' => [3600, 30],
        '6h' => [21600, 120],
        '24h' => [86400, 300],
        '7d' => [604800, 1800],
    ];

    private const MIN_WINDOW = 300;

    /**
     * @return array<string, string> chart key => PromQL
     */
    public static function server(string $serverId, int $step): array
    {
        $s = PromQl::label('falak_server_id', strtoupper($serverId));
        $w = max(self::MIN_WINDOW, $step).'s';

        return [
            'states' => "sum by (falak_net_state) (falak_net_connections{{$s}})",
            'directions' => "sum by (falak_net_direction) (falak_net_connections{{$s},falak_net_state=\"established\"})",
            'long_lived' => "sum(falak_net_connections_long_lived{{$s}})",
            'conntrack' => "sum(falak_net_conntrack_entries{{$s}})",
            'conntrack_limit' => "sum(falak_net_conntrack_limit{{$s}})",
            'probes' => "max by (falak_net_probe_target) (falak_net_probe_connect_time_seconds{{$s}}) * 1000",
            'handshakes' => "max by (falak_net_wireguard_peer) (falak_net_wireguard_handshake_age_seconds{{$s}})",
            'wireguard_io' => "sum by (network_io_direction) (rate(falak_net_wireguard_io_bytes_total{{$s}}[{$w}]))",
        ];
    }

    /**
     * A site's (or one compose service's) or a database instance's connections.
     *
     * @param  array<string, string>  $workload  label => value (falak_site_id, falak_db_instance_id, falak_compose_service)
     * @param  list<string>  $serverIds
     * @return array<string, string>
     */
    public static function workload(array $workload, array $serverIds, int $step): array
    {
        $w = max(self::MIN_WINDOW, $step).'s';
        $matchers = array_map(fn (string $label, string $value) => PromQl::label($label, $value), array_keys($workload), $workload);

        if ($serverIds !== []) {
            $pattern = implode('|', array_map(fn (string $id) => preg_quote(strtoupper($id), '/'), $serverIds));
            $matchers[] = 'falak_server_id=~'.PromQl::quote($pattern);
        }

        $sel = implode(',', $matchers);

        return [
            'states' => "sum by (falak_net_state) (falak_net_connections{{$sel}})",
            'directions' => "sum by (falak_net_direction) (falak_net_connections{{$sel},falak_net_state=\"established\"})",
            'long_lived' => "sum(falak_net_connections_long_lived{{$sel}})",
            'peers_out' => "topk(10, sum by (falak_net_peer) (falak_net_peer_connections{{$sel},falak_net_direction=\"outbound\"}))",
            'peers_in' => "topk(10, sum by (falak_net_peer) (falak_net_peer_connections{{$sel},falak_net_direction=\"inbound\"}))",
            'peer_bytes' => "topk(10, sum by (falak_net_peer) (rate(falak_net_peer_io_bytes_total{{$sel}}[{$w}])))",
        ];
    }
}
