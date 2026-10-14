<?php

// Uptime checks from the control plane (docs/plans/network/uptime.md).
return [
    // Queue of the check jobs: its own Horizon supervisor (config/horizon.php, FALAK_UPTIME_WORKERS) bounds concurrency.
    'queue' => env('FALAK_UPTIME_QUEUE', 'uptime'),

    // Monitors claimed per scheduler run (every 10 s).
    'dispatch_batch' => 500,

    // A failed attempt is confirmed by a second one after this delay; a check fails only when both fail.
    'retry_delay_ms' => (int) env('FALAK_UPTIME_RETRY_DELAY_MS', 1000),

    // Consecutive failed checks that open an incident, consecutive successes that close it.
    'confirm_after' => 2,
    'recover_after' => 2,

    // A failed check schedules the next one after at most this many seconds (outages are confirmed quickly).
    'retry_interval' => 30,

    'default_interval' => 60,
    'min_interval' => 30,
    'max_interval' => 600,
    // Seconds for a whole attempt (every redirect hop included); a check is at most two attempts and a TLS inspection,
    // which must stay below the queue's retry_after (90 s).
    'default_timeout' => 10,
    'max_timeout' => 20,
    'max_redirects' => 5,

    // Response bodies are read up to this many bytes (keyword match); the transfer stops there.
    'body_bytes' => 65536,

    // User-defined monitors per organization (automatic site monitors do not count).
    'max_monitors' => (int) env('FALAK_UPTIME_MAX_MONITORS', 50),

    // Default slow-response threshold (median of the last 5 successful checks, held 5 minutes).
    'slow_ms' => 3000,
    'slow_seconds' => 300,

    // User monitors alert when their certificate expires within this many days (site domains: Edge's own alert).
    'tls_expiry_days' => 14,
    // How often an https monitor's certificate chain is inspected.
    'tls_check_minutes' => 60,

    // SSRF guard: user-supplied targets never reach private, loopback, link-local or metadata addresses unless whoever
    // runs the control plane allows it. Organizations cannot change this.
    'allow_private_targets' => (bool) env('FALAK_UPTIME_ALLOW_PRIVATE_TARGETS', false),
    // Extra refused ranges (CIDR), e.g. the control plane's own public subnet.
    'blocked_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('FALAK_UPTIME_BLOCKED_CIDRS', ''))))),

    // CA bundle for chain checks (null: OpenSSL's default file, then the usual system paths).
    'ca_file' => env('FALAK_UPTIME_CA_FILE'),

    'flapping' => [
        'incidents' => 3,
        'window_minutes' => 60,
        'hold_minutes' => 15,
    ],

    'retention' => [
        'checks_days' => 7,
        'hourly_days' => 30,
        'daily_days' => 90,
        'incidents_days' => 90,
    ],

    'status_page' => [
        'cache_seconds' => 60,
        'rate_per_minute' => 60,
        'incident_days' => 14,
        'noindex' => true,
    ],
];
