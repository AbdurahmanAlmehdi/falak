<?php

return [
    // The release this panel runs: the image's FALAK_VERSION, else /opt/falak/VERSION (written at image build).
    'version' => env('FALAK_VERSION'),
    'version_file' => env('FALAK_VERSION_FILE', '/opt/falak/VERSION'),

    // Managed multi-tenant install (strangers sign up): verified emails, no operator-only escape hatches.
    'managed' => (bool) env('FALAK_MANAGED', false),

    // Release check: GitHub's latest (non-draft, non-pre-release) release of this repository, every 6 h.
    'update_check' => (bool) env('FALAK_UPDATE_CHECK', true),
    'repository' => env('FALAK_REPO', 'falak-sh/falak'),

    /*
    | The host channel (falak-ctl ≥ v0.23): the panel writes request.json here (rw mount), falak-host.service on the
    | host runs the allow-listed action and writes status.json and <id>.log back. No directory: the feature is off.
    */
    'host_channel_dir' => env('FALAK_HOST_CHANNEL_DIR', '/falak/host'),

    // A request.json the host has not picked up after this long is stale: a new request may replace it.
    'stale_after_minutes' => (int) env('FALAK_HOST_REQUEST_STALE_MINUTES', 15),
];
