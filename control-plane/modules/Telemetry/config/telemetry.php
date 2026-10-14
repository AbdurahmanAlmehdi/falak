<?php

return [
    /*
     * OTLP/HTTP ingress of the observability box (observability/gateway). Agents relay every signal
     * here; organizations may override the endpoint/token in Telemetry settings.
     */
    'otlp' => [
        'endpoint' => env('FALAK_OTLP_ENDPOINT') ?: config('fleet.otlp_endpoint', 'http://localhost:4318'),
        'token' => env('FALAK_OTLP_TOKEN'),
    ],

    'metrics' => [
        // victoriametrics | mimir
        'backend' => env('FALAK_METRICS_BACKEND', 'victoriametrics'),
        // http://victoriametrics:8428 or http://mimir:9009/prometheus (or the gateway :9090/prometheus)
        'query_url' => env('FALAK_METRICS_QUERY_URL'),
        // Mimir tenant (X-Scope-OrgID)
        'tenant' => env('FALAK_MIMIR_TENANT'),
        'token' => env('FALAK_METRICS_TOKEN'),
    ],

    'loki' => [
        'url' => env('FALAK_LOKI_URL'),
        'tenant' => env('FALAK_LOKI_TENANT'),
    ],

    'tempo' => [
        'url' => env('FALAK_TEMPO_URL'),
    ],

    'grafana' => [
        // Reachable from the control plane (API calls).
        'url' => env('FALAK_GRAFANA_URL'),
        // Reachable from browsers (deep links); defaults to url.
        'public_url' => env('FALAK_GRAFANA_PUBLIC_URL'),
        // Service account token (Admin / Editor with folders + datasources + annotations).
        'token' => env('FALAK_GRAFANA_TOKEN'),
        'dashboards_path' => env('FALAK_GRAFANA_DASHBOARDS_PATH', base_path('../observability/grafana/dashboards')),
        // Datasource URLs as Grafana (not the control plane) reaches them.
        'datasources' => [
            'metrics_url' => env('FALAK_GRAFANA_METRICS_URL', 'http://gateway:9090/prometheus'),
            'loki_url' => env('FALAK_GRAFANA_LOKI_URL', 'http://loki:3100'),
            'tempo_url' => env('FALAK_GRAFANA_TEMPO_URL', 'http://tempo:3200'),
        ],
    ],

    'http' => [
        'timeout' => (int) env('FALAK_TELEMETRY_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('FALAK_TELEMETRY_HTTP_CONNECT_TIMEOUT', 3),
    ],

    // telemetry.configure is sent this long after an agent enrolls (or right after provisioning).
    'configure_delay_seconds' => 30,

    // HTTP analytics from the edge access logs (docs/plans/network/http.md). Per-site settings override slow_ms,
    // slow_min_requests and the bandwidth alert.
    'http' => [
        'slow_ms' => (int) env('FALAK_HTTP_SLOW_MS', 1000),
        'slow_min_requests' => 20,
        'max_slow_routes' => 25,
        // Errors spike: in a 5-minute window, at least errors_min 5xx and errors_ratio of the requests (held 2 windows).
        'errors_min' => 20,
        'errors_ratio' => 0.10,
        'errors_hold_seconds' => 300,
        // Threats: hits of one rule on a site in a 5-minute window before a security Issue (background noise opens none).
        'security_min_hits' => 20,
        // 404 storm: 404s from one client IP in a 5-minute window.
        'storm_404' => 100,
        // Scanner surge alert: threat hits on a site in a 5-minute window.
        'scanner_surge' => 300,
        'bandwidth_retention_days' => 400,
        // Client IPs looked up for the countries / networks breakdown.
        'geo_top_ips' => 500,
    ],

    // DB-IP Lite databases (CC BY 4.0, https://db-ip.com): country and ASN of client IPs, downloaded monthly.
    'geoip' => [
        'enabled' => (bool) env('FALAK_GEOIP_ENABLED', true),
        'path' => env('FALAK_GEOIP_PATH', storage_path('app/geoip')),
        'country_url' => env('FALAK_GEOIP_COUNTRY_URL', 'https://download.db-ip.com/free/dbip-country-lite-{Y}-{m}.mmdb.gz'),
        'asn_url' => env('FALAK_GEOIP_ASN_URL', 'https://download.db-ip.com/free/dbip-asn-lite-{Y}-{m}.mmdb.gz'),
        // A file older than this is downloaded again (DB-IP publishes once a month).
        'max_age_days' => 32,
        'timeout' => 300,
    ],

    // Defaults for organizations without Telemetry settings.
    'defaults' => [
        'environment' => env('FALAK_TELEMETRY_ENVIRONMENT', 'production'),
        'traces_ratio' => (float) env('FALAK_TRACES_SAMPLE_RATIO', 1.0),
        'metrics_interval_s' => 15,
    ],
];
