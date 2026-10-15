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
        // Latency probes connect to this port of a peer's private address: nothing listens on it, the peer's kernel
        // answers with a RST (the round trip) and no service logs a connection.
        'probe_port' => (int) env('FALAK_NETWORK_PROBE_PORT', 1),
    ],

    // eBPF (docs/plans/network/ebpf.md): alert thresholds over a 5-minute window, per server and workload.
    'ebpf' => [
        // DNS failures other than NXDOMAIN (SERVFAIL, REFUSED, timeouts...), and their share of the queries.
        'dns_failures_min' => 60,
        'dns_failure_ratio' => 0.2,
        // TCP segments retransmitted (~2 per second).
        'retransmits_min' => 600,
        'alert_seconds' => 300,
    ],

    // Blocking and auto-ban (docs/plans/network/protection.md).
    'protection' => [
        // Never blocked, on top of the resolved panel and agent API hosts (comma-separated IPs / CIDRs).
        'control_plane_addresses' => array_values(array_filter(array_map('trim', explode(',', (string) env('FALAK_CONTROL_PLANE_IPS', ''))))),
        'changes_per_minute' => 30,
        'max_blocks' => 5000,
        // A brute-force Issue occurrence: at least this many failed SSH logins in one report (a minute).
        'ssh_issue_min_failures' => 20,
        // never_block's size: the agent accepts 5,000.
        'never_block_max' => 5000,
        // Addresses members used the panel from in this many days are never blocked (sessions and audited actions).
        'member_address_days' => 30,
        // Expired blocks are kept this long (listed as history in the audit log only).
        'retention_days' => 7,
    ],
];
