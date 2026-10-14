<?php

return [
    // Default address space for new private (WireGuard) networks.
    'private_cidr' => env('FALAK_PRIVATE_NETWORK_CIDR', '10.90.0.0/24'),

    // Default WireGuard listen port for new private networks.
    'wireguard_port' => (int) env('FALAK_WIREGUARD_PORT', 51820),

    'persistent_keepalive' => 25,

    // Always accepted by the agent to avoid lock-out (net.firewall.apply ssh_port).
    'ssh_port' => 22,

    'command_timeout' => 120,

    // net.wireguard.apply: the first apply on a server installs wireguard-tools (apt-get update + install, each may
    // wait up to 5 min for the apt lock).
    'wireguard_apply_timeout' => 900,

    // Connections (docs/plans/network/connections.md): the agent's net.observed reports, the exposed-ports audit, alerts.
    'connections' => [
        'conntrack_percent' => 85,
        'handshake_stale_seconds' => 300,
        'latency_factor' => 3,
        'latency_min_ms' => 50,
        'alert_seconds' => 300,
        // A report older than this is shown as stale.
        'stale_seconds' => 300,
        'rdns_ttl_hours' => 24,
        'rdns_per_report' => 20,
        'long_lived_after_s' => 60,
    ],
];
