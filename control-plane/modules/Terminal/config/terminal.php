<?php

return [
    // Seconds without keystrokes/output before the agent (and the sweeper, as a fallback) closes a session.
    'idle_timeout' => (int) env('FALAK_TERMINAL_IDLE_TIMEOUT', 900),

    // Hard cap on a session's lifetime; used as the terminal.open command timeout (Fleet allows at most 3600s).
    'max_duration' => (int) env('FALAK_TERMINAL_MAX_DURATION', 3600),

    // Unix user the shell runs as unless another is chosen.
    'default_user' => env('FALAK_TERMINAL_DEFAULT_USER', 'root'),

    'term' => 'xterm-256color',
    'shell' => '/bin/bash',

    // Max base64 characters per broadcast message (Reverb's default max message size is 10 KB).
    'broadcast_chunk_bytes' => 6000,

    // Max decoded bytes accepted per input request.
    'input_max_bytes' => 16384,

    // Frames returned per catch-up request.
    'frames_page_size' => 500,

    // The terminal relay (docs/plans/CONSOLE.md): browsers and `falak console` exchange bytes with the agent over a WebSocket
    // instead of Reverb + POSTs. Tokens are signed with a key derived from APP_KEY, which the relay shares.
    'relay' => [
        'enabled' => (bool) env('FALAK_TERMINAL_RELAY', false),
        // Base URL clients dial (…/sessions/<id>?ticket=…); null = APP_URL with ws(s):// plus /terminal/v1.
        'url' => env('FALAK_TERMINAL_RELAY_URL'),
        // Where the control plane reaches the relay's internal API (dropping clients whose access ended).
        'internal_url' => env('FALAK_TERMINAL_RELAY_INTERNAL_URL', 'http://relay:8080'),
        // Seconds a client ticket is valid for connecting (single use).
        'ticket_ttl' => 60,
        // Agent tokens outlive the session's maximum duration by this many seconds.
        'agent_token_grace' => 300,
    ],

    // Packet captures (docs/plans/network/capture.md): encrypted chunks on this disk, downloadable this long.
    'captures' => [
        'disk' => env('FALAK_CAPTURES_DISK', 'local'),
        'ttl_hours' => 24,
        // Per organization: captures in progress at once, and started per hour.
        'max_active' => 3,
        'max_per_hour' => 10,
    ],
];
