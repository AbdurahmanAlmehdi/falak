<?php

return [
    // contracts/config (the v1 schema and the risky-change rules); the image copies it to /opt/falak/contracts/config.
    'contracts_path' => env('FALAK_CONFIG_CONTRACTS_PATH', base_path('../contracts/config')),

    // Paths a config source may read (the file at the root of the repository, or below it).
    'default_path' => 'falak.yaml',

    // Runs kept per project (older finished runs are pruned).
    'keep_runs' => (int) env('FALAK_CONFIG_KEEP_RUNS', 200),

    // Days a run waits for an approval before it is cancelled.
    'approval_days' => (int) env('FALAK_CONFIG_APPROVAL_DAYS', 7),

    // Minutes between drift checks of every environment that follows a file.
    'drift_minutes' => (int) env('FALAK_CONFIG_DRIFT_MINUTES', 30),
];
