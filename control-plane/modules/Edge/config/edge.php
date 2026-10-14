<?php

return [
    // ACME account e-mail / directory passed to every edge.caddy.apply (Caddy defaults when empty).
    'acme_email' => env('FALAK_ACME_EMAIL'),
    'acme_ca' => env('FALAK_ACME_CA'),

    // TLS for hosted test domains (<slug>.<FALAK_TEST_DOMAIN>): "acme" or "internal" (local / private setups).
    'test_domain_tls' => env('FALAK_TEST_DOMAIN_TLS', 'acme'),

    // Generated domains for new sites: <label>.<ip-with-dashes>.<suffix>, resolved by a wildcard DNS service to the IP
    // in the name (no DNS setup; Let's Encrypt HTTP-01 works). "sslip.io" (default), "nip.io", your own sslip.io-style
    // server's domain, or "off". Organizations can pick another provider in Settings → Domains.
    'generated_domain_suffix' => env('FALAK_GENERATED_DOMAIN_SUFFIX', 'sslip.io'),

    // Live DNS checks for custom domains: "doh" (DNS-over-HTTPS JSON API; bypasses the host's resolver cache, so a new
    // record shows up as soon as it is published) or "system" (the control plane's resolver).
    'dns' => [
        'resolver' => env('FALAK_DNS_RESOLVER', 'doh'),
        'doh_url' => env('FALAK_DNS_DOH_URL', 'https://cloudflare-dns.com/dns-query'),
        'timeout_seconds' => 3,
    ],

    // Debounce window: bursts of changes within this many seconds collapse into one apply per server.
    'apply_delay_seconds' => (int) env('FALAK_EDGE_APPLY_DELAY', 2),

    // Function sites: Caddy proxies them to the server's function gateway (falak-fn-gateway), which routes by the
    // X-Falak-Function header and starts instances on demand.
    'function_gateway' => '127.0.0.1:7070',

    // Octane sites: how long the edge retries connecting to Octane (it restarts on every deploy) before a 502.
    'octane_try_duration_seconds' => 30,

    // Cloudflare rate limits: seconds a change waits for another one in the same zone (the entry point is read, then
    // replaced as a whole) before giving up.
    'cloudflare_lock_wait' => 15,

    // Cloudflare Tunnel (Settings → Cloudflare → Servers): the cloudflared release servers run, pinned by checksum.
    'cloudflared' => [
        'version' => env('FALAK_CLOUDFLARED_VERSION', '2026.9.3'),
        'url' => env('FALAK_CLOUDFLARED_URL', 'https://github.com/cloudflare/cloudflared/releases/download/{version}/cloudflared-linux-{arch}'),
        'sha256' => [
            'amd64' => env('FALAK_CLOUDFLARED_SHA256_AMD64', '77e26d8d900e0b8469f416239d14b5f296525fdf79fee6f511ef55609e3fbac2'),
            'arm64' => env('FALAK_CLOUDFLARED_SHA256_ARM64', 'aaeb2d7d0da3614634c7e03ab13487a1522c2e79165ed2929cfe23d5e95b326d'),
        ],
    ],

    'apply_timeout_seconds' => 120,
    'cert_install_timeout_seconds' => 60,
];
