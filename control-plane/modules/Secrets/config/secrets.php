<?php

return [
    // The secret access log is kept apart from the audit log, and longer: who or what read which version, and why.
    'access_log_retention_days' => (int) env('FALAK_SECRETS_ACCESS_LOG_RETENTION_DAYS', 730),

    // Revealing a value needs the password (and a 2FA code, when enabled) confirmed within this many seconds.
    'reveal_confirm_seconds' => (int) env('FALAK_SECRETS_REVEAL_CONFIRM_SECONDS', 300),

    // More than `count` reveals by one user (dashboard or API token) within `minutes` alert (secrets.unusual_reveals).
    'reveal_alert' => [
        'count' => (int) env('FALAK_SECRETS_REVEAL_ALERT_COUNT', 20),
        'minutes' => 10,
    ],

    'providers' => [
        // Lets organizations mark a provider "allow private network" (a self-hosted Vault / Infisical on the LAN).
        // Off by default: the control plane's own network (database, cache, internal services) stays out of reach.
        // Checked when a provider is saved and before every request. Ignored on a managed install (controlplane.managed).
        'allow_private_network' => (bool) env('FALAK_SECRETS_PROVIDERS_ALLOW_PRIVATE', false),

        // A provider that keeps failing: its last good value is used for at most this long, then deploys fail.
        'max_stale_seconds' => (int) env('FALAK_SECRETS_PROVIDERS_MAX_STALE_SECONDS', 86400),

        // Watched secrets polled at once per organization (one organization can't hold every queue worker).
        'poll_concurrency_per_organization' => (int) env('FALAK_SECRETS_POLL_CONCURRENCY_PER_ORGANIZATION', 3),

        // AWS providers may use the control plane's own EC2 instance profile (IMDSv2). Off by default: every
        // organization of the instance would read with the control plane's role.
        'allow_instance_profile' => (bool) env('FALAK_SECRETS_PROVIDERS_ALLOW_INSTANCE_PROFILE', false),

        'retry_delay_ms' => (int) env('FALAK_SECRETS_PROVIDERS_RETRY_DELAY_MS', 200),
    ],
];
