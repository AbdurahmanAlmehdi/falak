<?php

/*
 * Providers module configuration (merged under the `providers` key).
 */
return [
    'http' => [
        'timeout' => (int) env('FALAK_PROVIDERS_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('FALAK_PROVIDERS_HTTP_CONNECT_TIMEOUT', 10),
        // Retries apply to rate limits (429) on any call and to 5xx / connection errors on idempotent calls only.
        'retries' => (int) env('FALAK_PROVIDERS_HTTP_RETRIES', 3),
        'retry_sleep_ms' => (int) env('FALAK_PROVIDERS_HTTP_RETRY_SLEEP_MS', 500),
    ],

    'catalog_cache_ttl' => (int) env('FALAK_PROVIDERS_CATALOG_TTL', 3600),

    'endpoints' => [
        'hetzner' => env('FALAK_HETZNER_API_URL', 'https://api.hetzner.cloud/v1'),
        'digitalocean' => env('FALAK_DIGITALOCEAN_API_URL', 'https://api.digitalocean.com/v2'),
        'vultr' => env('FALAK_VULTR_API_URL', 'https://api.vultr.com/v2'),
        'linode' => env('FALAK_LINODE_API_URL', 'https://api.linode.com/v4'),
        // {region} is substituted per call; Lightsail is a regional JSON 1.1 API.
        'aws' => env('FALAK_LIGHTSAIL_API_URL', 'https://lightsail.{region}.amazonaws.com'),
        'contabo' => env('FALAK_CONTABO_API_URL', 'https://api.contabo.com/v1'),
    ],

    'contabo_auth_url' => env('FALAK_CONTABO_AUTH_URL', 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token'),
];
