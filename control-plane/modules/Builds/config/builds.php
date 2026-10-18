<?php

return [
    // Build timeout handed to falak-builder (job timeout_s) and enforced by the control plane watchdog.
    'timeout' => (int) env('FALAK_BUILD_TIMEOUT', 1800),
    // Extra time after timeout_s before the watchdog marks a running build timed out.
    'grace_seconds' => 120,
    // A running build whose builder (one that reports run ids) sent no heartbeat or event for this long is failed.
    'heartbeat_timeout_seconds' => (int) env('FALAK_BUILD_HEARTBEAT_TIMEOUT', 90),
    // Queued builds no builder picks up fail after this long.
    'queue_ttl_seconds' => (int) env('FALAK_BUILD_QUEUE_TTL', 3600),
    // Assigned builds that never report `started` are re-queued after this long (builder died).
    'assign_timeout_seconds' => 180,
    'max_attempts' => 3,
    // Long-poll cap for GET /api/internal/builds/next?wait=.
    'long_poll_max_seconds' => 25,
    'long_poll_interval_ms' => 1000,
    // A builder counts as online when it polled within this window.
    'builder_online_seconds' => 120,

    // Site environment variables with these prefixes are passed to builds (public front-end config), as are
    // the variables exposed to the deploy script (the user's opt-in for other build-time settings).
    'env_prefixes' => ['VITE_', 'NEXT_PUBLIC_', 'NUXT_PUBLIC_', 'PUBLIC_', 'REACT_APP_'],

    // The builder on the control-plane host (`falak-builder serve --token $FALAK_LOCAL_BUILDER_TOKEN`).
    // It serves every organization. Leave empty to only use `builder` servers.
    'local_builder' => [
        'token' => env('FALAK_LOCAL_BUILDER_TOKEN'),
        'name' => env('FALAK_LOCAL_BUILDER_NAME', 'control-plane'),
        // Build modes the host builder accepts; add `docker` only when it can reach a Docker daemon (BuildKit).
        'modes' => array_values(array_filter(array_map('trim', explode(',', (string) env('FALAK_LOCAL_BUILDER_MODES', 'native'))))),
    ],

    // Builder servers: falak-builder is installed by the agent when a `builder` server finishes provisioning.
    'builder_binary' => [
        // "{arch}" is replaced with amd64|arm64. Defaults to this control plane (/install/builder/linux-{arch}).
        'download_url' => env('FALAK_BUILDER_DOWNLOAD_URL'),
        'binaries_path' => env('FALAK_BUILDER_BINARIES_PATH', storage_path('falak/builder')),
    ],

    // Limits of falak-builder on Falak servers (falak-build.slice + the BuildKit caps falak-builder applies).
    'caps' => [
        // Share of the server's RAM builds may use: site servers (on-server builds) / dedicated builder servers.
        'memory_percent' => (int) env('FALAK_BUILD_MEMORY_PERCENT', 60),
        'dedicated_memory_percent' => (int) env('FALAK_BUILD_DEDICATED_MEMORY_PERCENT', 85),
        'tasks_max' => 4096,
        'io_weight' => 50,
        'cpu_weight' => 50,
        // Disk guard thresholds handed to falak-builder; null keeps its defaults.
        'min_free_gb' => env('FALAK_BUILD_MIN_FREE_GB'),
        'abort_free_gb' => env('FALAK_BUILD_ABORT_FREE_GB'),
    ],

    'artifacts' => [
        // local | s3
        'driver' => env('FALAK_ARTIFACTS_DRIVER', 'local'),
        // Presigned URL lifetimes.
        'upload_ttl' => 3600,
        'download_ttl' => 3600,
        'max_upload_bytes' => (int) env('FALAK_ARTIFACTS_MAX_BYTES', 4 * 1024 ** 3),
        'local' => [
            'root' => env('FALAK_ARTIFACTS_PATH', storage_path('falak/artifacts')),
            // Public https base URL agents and builders reach the control plane at (deploy.fetch requires https).
            'url' => env('FALAK_ARTIFACTS_URL', env('FALAK_PANEL_URL', env('APP_URL', 'http://localhost'))),
        ],
        's3' => [
            'endpoint' => env('FALAK_ARTIFACTS_S3_ENDPOINT'),
            'region' => env('FALAK_ARTIFACTS_S3_REGION', 'us-east-1'),
            'bucket' => env('FALAK_ARTIFACTS_S3_BUCKET'),
            'key' => env('FALAK_ARTIFACTS_S3_KEY'),
            'secret' => env('FALAK_ARTIFACTS_S3_SECRET'),
            'prefix' => env('FALAK_ARTIFACTS_S3_PREFIX', 'artifacts'),
            'path_style' => (bool) env('FALAK_ARTIFACTS_S3_PATH_STYLE', false),
        ],
        // Keep the newest N successful artifacts per site; older ones are deleted by the daily prune.
        'keep_per_site' => (int) env('FALAK_ARTIFACTS_KEEP', 10),
        'max_age_days' => (int) env('FALAK_ARTIFACTS_MAX_AGE_DAYS', 90),
    ],

    // Build log lines are deleted after this many days (the build rows stay as history).
    'log_retention_days' => 30,

    // Built-in registry for docker builds (distribution registry). Images: <url>/<namespace>/<site-slug>:<build-id>.
    'registry' => [
        'url' => env('FALAK_REGISTRY_URL', 'registry.falak.local'),
        'namespace' => env('FALAK_REGISTRY_NAMESPACE', 'falak'),
        'username' => env('FALAK_REGISTRY_USERNAME'),
        'password' => env('FALAK_REGISTRY_PASSWORD'),
        // Images of a deleted site's builds are deleted this many days after the build (falak:registry-prune).
        'deleted_site_grace_days' => (int) env('FALAK_REGISTRY_DELETED_SITE_GRACE_DAYS', 7),
    ],
];
