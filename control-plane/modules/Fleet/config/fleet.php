<?php

return [
    /*
     * Internal CA. The CA certificate (never the key) is written to <ca_path>/ca.pem so the edge
     * (Caddy/FrankenPHP) can verify agent client certificates.
     */
    'ca_path' => env('FALAK_CA_PATH', storage_path('falak/ca')),
    'ca_validity_years' => 10,
    'cert_validity_days' => 90,

    /*
     * mTLS is terminated at the edge, which forwards the client-certificate fingerprint. The header is
     * only trusted when the TCP peer (REMOTE_ADDR) is one of these proxies (comma-separated CIDRs).
     */
    'fingerprint_header' => 'X-Falak-Client-Cert-Fingerprint',
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('FALAK_AGENT_TRUSTED_PROXIES', '127.0.0.1/32,::1/128'))))),

    // Agent requests (POST /agent/v1/requests/{type}) per agent and type and minute.
    'agent_requests_per_minute' => 120,

    // Agent uploads (POST /agent/v1/uploads/{type}/{id}): the largest chunk and chunks per agent and minute.
    'upload_chunk_bytes' => 8 * 1024 * 1024,
    'agent_uploads_per_minute' => 300,

    // Public URLs handed to agents. Defaults derive from APP_URL.
    'panel_url' => env('FALAK_PANEL_URL'),
    'api_url' => env('FALAK_AGENT_API_URL'),
    'otlp_endpoint' => env('FALAK_OTLP_ENDPOINT', 'http://localhost:4318'),

    'install_token_ttl_minutes' => (int) env('FALAK_INSTALL_TOKEN_TTL', 60 * 24),

    'agent' => [
        // Where installers download falak-agent from. "{arch}" is replaced with amd64|arm64.
        // Defaults to this control plane serving binaries from `binaries_path`.
        'download_url' => env('FALAK_AGENT_DOWNLOAD_URL'),
        'binaries_path' => env('FALAK_AGENT_BINARIES_PATH', storage_path('falak/agent')),
        // Optional pinned checksums when downloading from an external URL: ['amd64' => '<sha256>', ...]
        'checksums' => array_filter([
            'amd64' => env('FALAK_AGENT_SHA256_AMD64'),
            'arm64' => env('FALAK_AGENT_SHA256_ARM64'),
        ]),
        // Version of the shipped build, when binaries_path has no falak-agent-linux-<arch>.version sidecar.
        'version' => env('FALAK_AGENT_VERSION', env('FALAK_VERSION')),
        'upgrade' => [
            // "Upgrade all agents" upgrades this many servers at a time and stops at the first failure.
            'batch_size' => max(1, (int) env('FALAK_AGENT_UPGRADE_BATCH_SIZE', 2)),
            // An upgrade fails when the agent has not reported the new build this long after it was sent.
            'timeout_seconds' => max(60, (int) env('FALAK_AGENT_UPGRADE_TIMEOUT', 600)),
        ],
    ],

    // Heartbeats arrive every 15s; an agent is offline after this many seconds of silence.
    'offline_after_seconds' => (int) env('FALAK_AGENT_OFFLINE_AFTER', 60),

    // Long-poll wake-up: "redis" (BLPOP) or "database" (polls fleet_commands; works everywhere).
    'wake_driver' => env('FALAK_AGENT_WAKE_DRIVER', 'database'),
    'wake_redis_connection' => env('FALAK_AGENT_WAKE_REDIS', 'default'),
    'database_poll_interval_ms' => 500,
    'long_poll_max_seconds' => 60,

    'commands' => [
        // Payload secrets of settled commands (backup keys, an age identity) are forgotten by the sweep after this long
        // when their module did not do it, and those of commands stuck this long past their timeout.
        'forget_secrets_after_minutes' => 10,
        // Lease: a delivered command the agent has not reported as started/running/finished after this long was
        // lost (e.g. the agent restarted). Redeliverable types (x-falak-redeliverable in the command schema) are
        // queued again, others fail. Agent restarts are also detected sooner through the agent session id.
        'lease_seconds' => max(10, (int) env('FALAK_AGENT_COMMAND_LEASE', 90)),
        'max_attempts' => 5,
        // Queued commands the agent never picks up expire after this long.
        'queue_ttl_seconds' => 3600,
        // Extra time beyond timeout_s before a running command is marked timed out.
        'grace_seconds' => 60,
        'max_event_batch_bytes' => 8 * 1024 * 1024,
    ],

    'metrics_retention_hours' => 24,

    // JSON Schemas shared with the agent (contracts/agent-protocol).
    'schemas_path' => env('FALAK_AGENT_SCHEMAS_PATH', base_path('../contracts/agent-protocol')),
];
