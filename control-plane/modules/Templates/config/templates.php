<?php

return [
    // Curated catalog: templates/<slug>/{template.yaml, compose.yaml, icon.svg?} (docs/COMPOSE_TEMPLATES.md §2).
    // The production image bakes it into /opt/falak/templates (FALAK_TEMPLATES_PATH).
    'catalog_path' => env('FALAK_TEMPLATES_PATH', base_path('../templates')),

    // Published catalog (falak-sh/templates releases: catalog.json + catalog.tar.gz), fetched hourly by templates:sync
    // into synced_path; served when newer than the baked copy. Empty: only the baked catalog.
    'remote_url' => rtrim((string) env('FALAK_TEMPLATES_URL', 'https://github.com/falak-sh/templates/releases/latest/download'), '/'),
    'synced_path' => env('FALAK_TEMPLATES_SYNCED_PATH', storage_path('app/templates')),
    'max_archive_bytes' => 20 * 1024 * 1024,
    'max_extracted_bytes' => 100 * 1024 * 1024,

    // Parsed catalog cache; the key includes the files' modification times, so edits are picked up.
    'cache_ttl' => (int) env('FALAK_TEMPLATES_CACHE_TTL', 3600),

    // Custom (organization) templates.
    'max_bytes' => 256 * 1024,          // template.yaml + compose.yaml, each
    'max_custom_per_organization' => 200,

    // Import from URL: https only, public addresses only (SSRF guard), no redirects to anywhere else.
    'fetch' => [
        'timeout' => 10,
        'max_redirects' => 3,
    ],
];
