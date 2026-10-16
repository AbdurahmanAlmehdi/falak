<?php

// Sandboxes (docs/plans/SANDBOXES.md "Quotas"): per sandbox and per organization. Defaults are sized for a small VPS.
return [
    'max_running' => (int) env('FALAK_SANDBOX_MAX_RUNNING', 5),
    'default_ttl' => (int) env('FALAK_SANDBOX_DEFAULT_TTL', 3600),
    'max_ttl' => (int) env('FALAK_SANDBOX_MAX_TTL', 86400),
    'max_cpus' => (float) env('FALAK_SANDBOX_MAX_CPUS', 4),
    'max_memory_mb' => (int) env('FALAK_SANDBOX_MAX_MEMORY_MB', 8192),
    'max_disk_mb' => (int) env('FALAK_SANDBOX_MAX_DISK_MB', 51200),
    'max_total_memory_mb' => (int) env('FALAK_SANDBOX_MAX_TOTAL_MEMORY_MB', 8192),
    // The disk quotas of an organization's live sandboxes, in all (each is a cap its sandbox may fill).
    'max_total_disk_mb' => (int) env('FALAK_SANDBOX_MAX_TOTAL_DISK_MB', 102400),
    // Snapshots (v0.21) an organization keeps (creating or ready), and the disk they take in all.
    'max_snapshots' => (int) env('FALAK_SANDBOX_MAX_SNAPSHOTS', 20),
    'max_snapshot_storage_mb' => (int) env('FALAK_SANDBOX_MAX_SNAPSHOT_STORAGE_MB', 51200),

    // Defaults of POST /sandboxes.
    'defaults' => [
        'image' => 'ubuntu:24.04',
        'cpus' => 1,
        'memory_mb' => 1024,
        'disk_mb' => 10240,
        'network' => 'internet',
    ],

    'pids' => 1024,

    // A sandbox still `creating` after this many seconds failed (the sweeper marks it).
    'create_timeout' => 300,

    // Files over the API (sandbox.files.*): v0.20 caps reads and writes at 5 MB.
    'max_file_bytes' => 5 * 1024 * 1024,
];
