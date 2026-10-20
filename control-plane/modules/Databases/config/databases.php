<?php

$versions = [
    'postgresql' => ['17', '18', '16', '15'],
    'mysql' => ['8.4', '8.0'],
    'mariadb' => ['11.4', '10.11'],
    'redis' => ['8', '7.4'],
    'valkey' => ['8.1'],
];

// Database images run pinned by digest, never a tag as pulled: the digests of the images CI published and signed
// (db-image-digests.json, {"postgresql": {"17": "sha256:…"}}, written by tools/db-image-digests.sh in release.yml before the
// control-plane image is built; docs/DB_IMAGES.md "Trust"), overridable per major with FALAK_DB_IMAGE_DIGEST_<ENGINE>_<MAJOR>
// (dots as underscores). A major without a digest can't be created or upgraded to.
$manifest = (string) env('FALAK_DB_IMAGE_DIGESTS', __DIR__.'/db-image-digests.json');
$manifest = str_starts_with($manifest, '/') ? $manifest : base_path($manifest);
$digests = is_file($manifest) ? (array) json_decode((string) file_get_contents($manifest), true) : [];

foreach ($versions as $engine => $majors) {
    foreach ($majors as $major) {
        $digest = env('FALAK_DB_IMAGE_DIGEST_'.strtoupper($engine).'_'.str_replace('.', '_', $major));

        if (is_string($digest) && $digest !== '') {
            $digests[$engine][$major] = $digest;
        }
    }
}

return [
    // Every managed database is a container of a Falak database image (docs/DB_IMAGES.md): ghcr.io/falak-sh/
    // falak-<engine>:<major>, always run by the pinned digest above (the agent refuses anything else). The first version
    // of each engine is the default of a new instance; a new digest for a major is a minor upgrade.
    'registry' => rtrim((string) env('FALAK_DB_IMAGE_REGISTRY', 'ghcr.io/falak-sh'), '/'),
    'tag_suffix' => (string) env('FALAK_DB_IMAGE_TAG_SUFFIX', ''),
    'versions' => $versions,
    'digests' => $digests,

    // Memory limit of a new instance (bytes), and the smallest one accepted. The engine's config is tuned to it.
    'memory' => [
        'default' => ['postgresql' => 512 * 1024 ** 2, 'mysql' => 512 * 1024 ** 2, 'mariadb' => 512 * 1024 ** 2, 'redis' => 128 * 1024 ** 2, 'valkey' => 128 * 1024 ** 2],
        'min' => ['postgresql' => 256 * 1024 ** 2, 'mysql' => 384 * 1024 ** 2, 'mariadb' => 256 * 1024 ** 2, 'redis' => 32 * 1024 ** 2, 'valkey' => 32 * 1024 ** 2],
        'max' => 256 * 1024 ** 3,
    ],

    // Size of a new instance's data volume (a sized volume: a hard limit, grown online from Volumes).
    'disk' => [
        'default' => ['postgresql' => 10 * 1024 ** 3, 'mysql' => 10 * 1024 ** 3, 'mariadb' => 10 * 1024 ** 3, 'redis' => 2 * 1024 ** 3, 'valkey' => 2 * 1024 ** 3],
        'min' => 1024 ** 3,
        'max' => 16 * 1024 ** 4,
    ],

    // Host ports Falak publishes instances on (127.0.0.1, plus private addresses for other servers); one per instance
    // and server.
    'host_ports' => [20000, 29999],

    // TLS certificates of instances (issued by the Falak CA).
    'tls_days' => 397,
    // Certificates are renewed (and the engine restarted with them) this many days before they expire.
    'tls_renew_days' => 30,

    // Redis / Valkey password rotations: the previous password stays valid this many hours, while apps are redeployed
    // with the new one, then the agent drops it.
    'password_overlap_hours' => (int) env('FALAK_DB_PASSWORD_OVERLAP_HOURS', 24),

    // A major upgrade's old instance: its container is removed this many hours after the upgrade, once the new one is
    // healthy. Its data volume stays until someone deletes it.
    'retire_hours' => 24,

    // Restoring lost password files (heartbeat `databases[].secrets_missing`, after a reboot): at most once per
    // instance in this many seconds.
    'secrets_restore_throttle' => 300,

    // Open connections above this share of the server's limit (falak-db stats, every heartbeat) for this long alert
    // (databases.connections_high).
    'connections_alert_percent' => 80,
    'connections_alert_seconds' => 300,

    // What the agent's `falak-db observe` reports once a minute (db.observed, docs/plans/OBSERVABILITY.md phase 3).
    'observability' => [
        // Top query stats and daily size samples are kept this long.
        'retention_days' => 30,
        // databases.slow_queries_spike: at least this many slow queries a minute, held for alert_seconds.
        'slow_spike_per_minute' => 30,
        // databases.replication_lag: a replica this far behind, held for alert_seconds.
        'replication_lag_seconds' => 60,
        // databases.memory_high: Redis / Valkey used_memory against maxmemory, or the container against its limit.
        'memory_percent' => 90,
        'alert_seconds' => 300,
        // Authentication failures in one minute that make an Issue ("repeated"); every other engine error does at once.
        'auth_failed_min' => 5,
        // A slow query shape becomes an Issue once its hour has this many calls (Redis / Valkey commands: _kv).
        'issue_min_calls' => (int) env('FALAK_DB_ISSUE_MIN_CALLS', 5),
        'issue_min_calls_kv' => (int) env('FALAK_DB_ISSUE_MIN_CALLS_KV', 20),
        // An instance whose last report is older than this counts as not reporting (an older image or agent).
        'stale_seconds' => 600,
    ],

    // Providers whose servers of one account and region share a private network by default, used when no Falak private
    // network connects an instance's server with a consumer's: DigitalOcean (each region's default VPC) and Lightsail.
    // Not Hetzner, Vultr, Linode: their private networks are opt-in and can differ per server, so a private IPv4 says
    // nothing about who shares it. Servers must be created by Falak with the same provider credential, in the same
    // region.
    'provider_private_networks' => ['digitalocean', 'lightsail'],
    // Custom servers: their private IPv4s are taken as one network (never in production — they may be NATed or in
    // different networks). The sim's fleet network uses it.
    'custom_private_network' => (bool) env('FALAK_DB_CUSTOM_PRIVATE_NETWORK', false),

    'password_length' => 32,

    // Agent command timeouts (seconds).
    'timeouts' => [
        'ddl' => 300,
        // db.instance.create pulls the image and waits for the first start (up to 300 s on the agent).
        'instance' => 1800,
        'backup' => 3600,
        'restore' => 3600,
        'upgrade' => 4 * 3600,
        // A restore drill: pull, download, restore and check (the agent removes the container whatever happens).
        'drill' => 2 * 3600,
        // A physical base backup of a whole instance, and a point-in-time restore (download, unpack, replay).
        'pitr_base' => 4 * 3600,
        'pitr_restore' => 6 * 3600,
    ],

    // Restore drills (docs/BACKUPS.md): the throwaway instance's memory limit (the instance's own when smaller, the
    // engine's minimum when larger), and how far the restored row counts may be from those at backup time.
    'drills' => [
        'memory_bytes' => (int) env('FALAK_DRILL_MEMORY_BYTES', 512 * 1024 ** 2),
        'cpus' => (float) env('FALAK_DRILL_CPUS', 1),
        'tolerance_percent' => (float) env('FALAK_DRILL_TOLERANCE_PERCENT', 10),
    ],

    // Point-in-time recovery (docs/BACKUPS.md): recovery points kept and a new base backup every so many days (per
    // instance; these are the defaults), the alerts (oldest unshipped segment older than lag_alert_seconds while the
    // instance is up; the spool above spool_alert_percent of its volume), and the lifetime of the agent's upload URLs.
    'pitr' => [
        'window_days' => 7,
        'base_interval_days' => 7,
        'lag_alert_seconds' => 300,
        'spool_alert_percent' => 20,
        'upload_url_ttl' => 3600,
        // A failed base backup is tried again after this many minutes.
        'base_retry_minutes' => 60,
        // Segments handed out but never reported shipped, per instance: beyond, pitr.upload_urls refuses.
        'max_pending' => 2000,
        // pitr.shipped reads back objects up to this size to compare their SHA-256 (larger ones: the size only).
        'verify_max_bytes' => 64 * 1024 ** 2,
        // pitr.gap reports per instance and hour.
        'gaps_per_hour' => 10,
    ],

    // Presigned URL lifetimes (seconds). The upload URL must outlive queueing + the dump itself.
    'upload_url_ttl' => (int) env('FALAK_BACKUP_UPLOAD_URL_TTL', 12 * 3600),
    'download_url_ttl' => (int) env('FALAK_BACKUP_DOWNLOAD_URL_TTL', 6 * 3600),
    // "Download" links of the panel: a short-lived presigned GET, opened right away by the browser.
    'download_link_ttl' => (int) env('FALAK_BACKUP_DOWNLOAD_LINK_TTL', 300),

    // Control-plane → object storage requests (verification, pruning).
    'storage_timeout' => 30,

    // Allow storage endpoints on private / loopback / link-local addresses (self-hosted MinIO on a LAN).
    // Off by default: otherwise any storage admin could make the control plane probe internal services.
    'allow_private_endpoints' => (bool) env('FALAK_STORAGE_ALLOW_PRIVATE_ENDPOINTS', false),
];
