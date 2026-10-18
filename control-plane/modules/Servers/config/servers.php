<?php

return [
    // PHP versions offered in the UI (must be allowed by runtime.php.install.schema.json).
    'php_versions' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
    'default_php' => '8.4',

    // Releases where only some of those can be installed, keyed by the server's reported OS ("<id> <version>").
    // ppa:ondrej/php has no packages for Ubuntu 26.04 yet, so PHP comes from Ubuntu's archive there: 8.5 only.
    'php_versions_by_os' => [
        'ubuntu 26.04' => ['8.5'],
    ],

    // Installed for every PHP version (php<v>-<ext>).
    'php_extensions' => ['bcmath', 'cli', 'curl', 'gd', 'igbinary', 'intl', 'mbstring', 'mysql', 'pgsql', 'readline', 'redis', 'soap', 'sqlite3', 'xml', 'zip'],

    // Node majors offered, pinned to exact releases (provision.apply wants full semver).
    'node_versions' => [
        '20' => env('FALAK_NODE_20', '20.19.5'),
        '22' => env('FALAK_NODE_22', '22.20.0'),
        '24' => env('FALAK_NODE_24', '24.9.0'),
    ],
    'default_node' => '22',

    // The control plane's own machine as a server: memory falak-ctl keeps for the control plane (falakcp.slice
    // MemoryMin), never allocatable to sites there. Must match the host's FALAK_CP_RESERVE_MB.
    'control_plane_reserve_mb' => (int) env('FALAK_CP_RESERVE_MB', 1536),

    'frankenphp' => [
        'version' => env('FALAK_FRANKENPHP_VERSION', '1.9.1'),
        'sha256' => env('FALAK_FRANKENPHP_SHA256'),
    ],

    // Optional download mirrors for runtimes fetched over HTTPS during provisioning (air-gapped installs, a
    // caching proxy in front of GitHub / nodejs.org). Unset = the upstream release URLs.
    //   FALAK_FRANKENPHP_MIRROR  replaces https://github.com/php/frankenphp/releases/download
    //   FALAK_NODE_MIRROR        replaces https://nodejs.org/dist
    'mirrors' => [
        'frankenphp' => env('FALAK_FRANKENPHP_MIRROR'),
        'node' => env('FALAK_NODE_MIRROR'),
    ],

    // Every server runs Docker (sites, compose stacks, functions and database containers).
    // Docker Engine from Docker's apt repository, at least min_version: the agent adds the repository (its key's fingerprint
    // checked), replaces an older Docker, and falls back to the distribution's docker.io only where Docker's repository
    // has no suite yet and that one is recent enough (provision.apply docker.min_version).
    'docker' => ['packages' => ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin'], 'service' => 'docker', 'min_version' => '28'],

    'base_packages' => ['acl', 'ca-certificates', 'curl', 'fail2ban', 'git', 'htop', 'jq', 'rsync', 'sqlite3', 'tcpdump', 'unattended-upgrades', 'unzip', 'zip'],

    // Unix user owning sites and receiving synced SSH keys.
    'unix_user' => 'falak',

    // Swap by RAM: first threshold (bytes) the machine is below wins; null = no managed swapfile.
    'swap' => [
        [2 * 1024 ** 3, 2048],
        [8 * 1024 ** 3, 4096],
        [PHP_INT_MAX, 0],
    ],

    'provision_timeout' => 1800,

    // Machine check before provisioning (docs/plans/MACHINE_CHECK.md): provision.inspect reports what is on the machine,
    // Servers\Domain\MachineCheck\DecisionEngine decides per component with these rules.
    'machine_check' => [
        'timeout' => 180,

        // Lowest versions Falak adopts or completes; an older Docker is replaced with Docker's (docker-ce). Compared with
        // version_compare on the upstream version (Debian epoch and revision stripped).
        'minimum_versions' => [
            'docker' => '28',
        ],

        // Docker package families: a missing piece is completed from the engine's own family (never mixed: Ubuntu's
        // docker-buildx overwrites the files of Docker's docker-buildx-plugin). `repo` must be an apt source for the
        // family's packages to be installable.
        'docker_families' => [
            'docker-ce' => ['label' => "Docker's repository", 'compose' => 'docker-compose-plugin', 'buildx' => 'docker-buildx-plugin', 'repo' => 'download.docker.com'],
            'docker.io' => ['label' => "Ubuntu's archive", 'compose' => 'docker-compose-v2', 'buildx' => 'docker-buildx', 'repo' => null],
        ],

        // Ports the edge needs on servers that serve HTTP (its admin API is a unix socket, no port).
        'edge_ports' => [80, 443],

        // Web servers that would take the edge's ports.
        'web_servers' => ['nginx' => 'nginx', 'apache2' => 'Apache'],
    ],

    // Health alerts (CheckServerHealth, from the agents' heartbeats): disk per mount, a disk filling within
    // forecast_hours (a line fitted to the last six hours), memory, CPU and load held for their window, a pending
    // reboot, and an agent older than the one shipped for agent_outdated_minutes.
    'health' => [
        'disk_warning_percent' => 80,
        'disk_critical_percent' => 90,
        // A disk alert needs the level to hold this long; every alert here resolves only this many points below its
        // threshold (load: 10% below), for clear_minutes for the sampled ones: no alert/recovery flapping.
        'disk_hold_minutes' => 5,
        'hysteresis_points' => 5,
        'clear_minutes' => 5,
        'forecast_hours' => 48,
        'memory_percent' => 90,
        'memory_minutes' => 10,
        'cpu_percent' => 90,
        'cpu_minutes' => 15,
        'load_per_cpu' => 2.0,
        'load_minutes' => 15,
        'agent_outdated_minutes' => 60,
    ],
];
