<?php

namespace Falak\Servers\Domain\MachineCheck;

/**
 * Decides per component what provisioning does on a machine that already runs software: install, adopt (use what is
 * there), complete (add the missing pieces from the same source) or block (a conflict with a reason and a fix).
 *
 * Pure: a provision.inspect report, the wanted stack and the `servers` config in, decisions out. The rules are
 * documented in docs/plans/MACHINE_CHECK.md.
 */
final class DecisionEngine
{
    /** Components in display order (also the provision.apply `components` names). */
    public const COMPONENTS = ['base', 'docker', 'edge', 'php', 'node', 'ssh', 'firewall', 'swap', 'hostname', 'unattended_upgrades', 'fail2ban'];

    /** Falak's edge (FrankenPHP or Caddy) runs as this unit. */
    private const EDGE_UNIT = 'falak-edge.service';

    /** On the control plane's machine Falak's edge listens here; the control plane's edge forwards sites to it. */
    private const LOCAL_EDGE_PORTS = [8080, 8443];

    /** Falak's sshd drop-in; sshd keeps the first value of a keyword, so files sorting before it win. */
    private const SSH_DROP_IN = '/etc/ssh/sshd_config.d/50-falak.conf';

    /** nftables tables iptables-nft creates (Docker, ufw): not separate firewalls. */
    private const IPTABLES_TABLES = ['ip filter', 'ip nat', 'ip mangle', 'ip raw', 'ip security', 'ip6 filter', 'ip6 nat', 'ip6 mangle', 'ip6 raw', 'ip6 security'];

    /** @var array<string, mixed> */
    private array $rules;

    /**
     * @param  array<string, mixed>  $config  the `servers` config array
     */
    public function __construct(private readonly array $config)
    {
        $this->rules = (array) ($config['machine_check'] ?? []);
    }

    public function decide(MachineReport $report, Wanted $wanted): MachineCheck
    {
        $cp = $wanted->controlPlaneHost;

        return new MachineCheck(array_values(array_filter([
            $this->base($report, $wanted),
            $cp ? $this->controlPlaneDocker($this->docker($report)) : $this->docker($report),
            $this->edge($report, $wanted),
            $this->php($report, $wanted),
            $this->node($report, $wanted),
            $cp ? $this->kept($this->ssh($report, $wanted)) : $this->ssh($report, $wanted),
            $this->firewall($report),
            $cp ? $this->kept($this->swap($report, $wanted)) : $this->swap($report, $wanted),
            $cp ? $this->kept($this->hostname($report, $wanted)) : $this->hostname($report, $wanted),
            $this->unattended($report),
            $this->fail2ban($report),
        ])));
    }

    /**
     * The control plane's machine: its Docker runs the control plane, so Falak never installs or replaces it (that would
     * restart the panel mid-provisioning); a Docker it would replace blocks instead.
     */
    private function controlPlaneDocker(ComponentDecision $decision): ComponentDecision
    {
        if ($decision->decision !== Decision::Install) {
            return $decision;
        }

        $message = $decision->found === []
            ? 'No Docker found on the control plane\'s machine; Falak does not install Docker where its own control plane runs.'
            : 'Falak would replace Docker on the control plane\'s machine, which restarts the control plane.';

        return new ComponentDecision('docker', 'Docker', Decision::Block, $message, $decision->found, notes: [
            Note::block($message, 'Upgrade Docker Engine on the host yourself (from Docker\'s apt repository, at a quiet moment: the control plane restarts with it), then re-check.'),
            ...$decision->notes,
        ]);
    }

    /**
     * The control plane's machine keeps its SSH (port, password login), swap and hostname: they are the operator's.
     */
    private function kept(ComponentDecision $decision): ComponentDecision
    {
        return new ComponentDecision($decision->component, $decision->label, Decision::Adopt, 'Kept (control-plane machine).', $decision->found);
    }

    private function base(MachineReport $report, Wanted $wanted): ?ComponentDecision
    {
        if ($wanted->basePackages === []) {
            return null;
        }

        $found = [];
        $missing = [];

        foreach ($wanted->basePackages as $name) {
            $package = $report->package($name);

            if ($package === null) {
                $missing[] = $name;
            } else {
                $found[] = $this->found($name, $package);
            }
        }

        if ($missing === []) {
            return new ComponentDecision('base', 'Base packages', Decision::Adopt, 'All base packages are installed.', $found, keep: $wanted->basePackages);
        }

        if ($found === []) {
            return new ComponentDecision('base', 'Base packages', Decision::Install, 'Installs '.implode(', ', $missing).'.', install: $missing);
        }

        return new ComponentDecision('base', 'Base packages', Decision::Complete, 'Installs the missing '.implode(', ', $missing).'.', $found, install: $missing);
    }

    private function docker(MachineReport $report): ComponentDecision
    {
        $docker = $report->docker();
        $packages = (array) ($this->config['docker']['packages'] ?? []);
        $service = (string) ($this->config['docker']['service'] ?? 'docker');

        if ($docker === null) {
            return new ComponentDecision('docker', 'Docker', Decision::Install, "Installs Docker Engine from Docker's apt repository (".implode(', ', $packages).').', install: $packages, service: $service);
        }

        $engine = (string) ($docker['engine_package'] ?? '');
        $version = (string) ($docker['server_version'] ?? '') ?: MachineReport::upstream($report->package($engine)['version'] ?? null);
        $source = $engine !== '' ? MachineReport::sourceOf($report->package($engine) ?? []) : ($docker['snap'] ?? false ? 'snap' : 'manual install');
        $found = [['name' => $engine ?: 'docker', 'version' => $version, 'source' => $source]];

        foreach (['compose', 'buildx'] as $plugin) {
            if (is_array($docker[$plugin] ?? null)) {
                $found[] = ['name' => $docker[$plugin]['package'] ?? "docker {$plugin}", 'version' => $docker[$plugin]['version'] ?? null, 'source' => isset($docker[$plugin]['package']) ? MachineReport::sourceOf($report->package($docker[$plugin]['package']) ?? []) : 'plugin file'];
            }
        }

        $notes = $this->daemonNotes((array) ($docker['daemon'] ?? []));
        $block = fn (string $message, string $hint) => new ComponentDecision('docker', 'Docker', Decision::Block, $message, $found, notes: [Note::block($message, $hint), ...$notes]);

        if ($docker['snap'] ?? false) {
            return $block('Docker is installed as a snap.', 'Falak runs Docker from apt packages; the snap cannot reach site directories outside /home. Remove it (snap remove docker), then re-check: Falak installs Docker, or install it from Docker\'s repository first.');
        }

        if ($engine === 'podman-docker') {
            return $block('podman-docker provides the docker command, not the Docker engine.', 'Remove podman-docker (apt purge podman-docker), then re-check.');
        }

        if (! ($docker['system_daemon'] ?? false) && ($docker['rootless'] ?? false)) {
            return $block('Only a rootless Docker is set up.', 'Falak needs the system Docker daemon (docker.service). Install Docker for the whole machine, then re-check.');
        }

        if (($report->service('docker.service')['enabled'] ?? null) === 'masked') {
            return $block('docker.service is masked, so the Docker engine cannot start.', 'Unmask it (systemctl unmask docker.service docker.socket) if Docker should run here, or remove Docker, then re-check.');
        }

        if (! ($docker['system_daemon'] ?? false) && ($docker['server_version'] ?? '') === '') {
            return $block('Only the Docker CLI is installed: there is no Docker engine (no docker.service).', "Install the engine from the CLI's source (docker-ce from Docker's repository), or remove the CLI so Falak installs Ubuntu's Docker, then re-check.");
        }

        if ($docker['rootless'] ?? false) {
            $notes[] = Note::warning('A rootless Docker is also set up for a user; Falak only uses the system daemon.');
        }

        foreach (['compose', 'buildx'] as $plugin) {
            if (is_array($docker[$plugin] ?? null) && ($docker[$plugin]['version'] ?? '') === '') {
                $notes[] = Note::warning("The docker {$plugin} plugin at ".($docker[$plugin]['path'] ?? '?').' was not run: a user other than root can change it.', 'Make it root-owned and not writable by others (chown root: '.($docker[$plugin]['path'] ?? '<plugin>').'; chmod 755), then re-check.');
            }
        }

        $minimum = (string) ($this->rules['minimum_versions']['docker'] ?? '0');

        if ($version !== null && $version !== '' && version_compare($version, $minimum, '<')) {
            // Replaced, not blocked: images, containers and volumes stay in /var/lib/docker.
            return new ComponentDecision('docker', 'Docker', Decision::Install, "Replaces Docker {$version} (older than {$minimum}) with Docker Engine from Docker's apt repository (".implode(', ', $packages).'); containers, images and volumes stay.', $found, install: $packages, service: $service, notes: $notes);
        }

        if (($docker['server_version'] ?? '') === '' && ($docker['server_error'] ?? '') !== '') {
            $notes[] = Note::warning('The Docker daemon did not answer during the check; provisioning starts the docker service.');
        }

        $missing = array_values(array_filter(['compose', 'buildx'], fn (string $plugin) => ! is_array($docker[$plugin] ?? null)));
        $keep = array_values(array_unique(array_filter([$engine, $docker['compose']['package'] ?? null, $docker['buildx']['package'] ?? null])));
        $installed = trim("Docker {$version}")." from {$source}";

        if ($missing === []) {
            return new ComponentDecision('docker', 'Docker', Decision::Adopt, "Uses {$installed} with compose and buildx; installs no Docker packages.", $found, keep: $keep, service: $service, notes: $notes);
        }

        $family = (array) ($this->rules['docker_families'][$engine] ?? []);
        $pieces = implode(' and ', $missing);

        if ($family === []) {
            return $block("{$installed} has no {$pieces}, and Falak does not know where this Docker came from.", "Install the docker {$pieces} plugin from the same place as the engine, then re-check.");
        }

        $add = array_map(fn (string $plugin) => (string) $family[$plugin], $missing);

        if (($family['repo'] ?? null) !== null && ! $report->hasAptSource((string) $family['repo'])) {
            return $block("Docker from {$family['label']} has no {$pieces}, and that repository is not configured on the machine.", "Add Docker's apt repository (https://docs.docker.com/engine/install/ubuntu/) or install ".implode(' and ', $add).' yourself, then re-check.');
        }

        return new ComponentDecision('docker', 'Docker', Decision::Complete, "Uses {$installed}; installs ".implode(' and ', $add)." from {$family['label']}.", $found, install: $add, keep: $keep, service: $service, notes: $notes);
    }

    /**
     * @param  array<string, mixed>  $daemon  daemon.json settings from the report
     * @return list<Note>
     */
    private function daemonNotes(array $daemon): array
    {
        $notes = [];

        if (($daemon['iptables'] ?? null) === false) {
            $notes[] = Note::warning('daemon.json sets "iptables": false: published container ports and Falak\'s container firewall rules do not work.', 'Remove "iptables": false from /etc/docker/daemon.json and restart Docker.');
        }

        if (($daemon['userns_remap'] ?? '') !== '') {
            $notes[] = Note::warning("daemon.json sets \"userns-remap\": \"{$daemon['userns_remap']}\": files containers write in mounted directories get remapped owners.");
        }

        if (($daemon['bip'] ?? '') !== '' || ($daemon['default_address_pools'] ?? []) !== []) {
            $notes[] = Note::info('daemon.json sets custom address ranges (bip / default-address-pools); Falak keeps them.');
        }

        if (($daemon['error'] ?? '') !== '') {
            $notes[] = Note::warning("/etc/docker/daemon.json is not valid JSON: {$daemon['error']}");
        }

        return $notes;
    }

    /**
     * Blocks for a port the component needs that a container or another process holds.
     *
     * @param  list<string>  $allowed  process names that may hold the port (the adopted engine itself)
     * @return list<Note>
     */
    private function portNotes(MachineReport $report, int $port, string $for, array $allowed, ?string $allowedUnit = null): array
    {
        $notes = [];
        $containers = $report->containersPublishing($port);

        foreach ($containers as $container) {
            $notes[] = Note::block('A container ('.($container['name'] ?? '?').', '.($container['image'] ?? 'unknown image').") publishes port {$port}, which {$for} needs.",
                'Stop the container (docker stop '.($container['name'] ?? '<name>').') or publish it on another port, then re-check. To keep a database in Docker, create it as a Falak database instead (it runs in a container).');
        }

        foreach ($report->listenersOn($port) as $listener) {
            $process = (string) ($listener['process'] ?? '');
            $unit = (string) ($listener['unit'] ?? '');

            if (($allowedUnit !== null && $unit === $allowedUnit) || in_array($process, $allowed, true)) {
                continue;
            }

            if ($listener['container'] ?? false) {
                if ($containers === []) {
                    $notes[] = Note::block("A container publishes port {$port} ({$process}), which {$for} needs.", 'Stop the container or publish it on another port, then re-check.');
                }

                continue;
            }

            $name = $process !== '' ? $process : 'another process';
            $name .= $unit !== '' && $unit !== "{$process}.service" ? " ({$unit})" : '';
            $service = $unit !== '' && str_ends_with($unit, '.service') ? $unit : ($process !== '' ? $process : null);
            $notes[] = Note::block("Port {$port} is in use by {$name}, which {$for} needs.",
                $service !== null ? "Stop and disable it (systemctl disable --now {$service}) or move it to another port, then re-check." : 'Stop it or move it to another port, then re-check.');
        }

        // ss reports a port once per address: one note per holder is enough.
        return array_values(array_unique($notes, SORT_REGULAR));
    }

    private function edge(MachineReport $report, Wanted $wanted): ?ComponentDecision
    {
        $frankenphp = $wanted->stack->phpRuntime === 'frankenphp';
        $webServers = (array) ($this->rules['web_servers'] ?? []);
        $found = [];

        foreach ([...array_keys($webServers), 'caddy'] as $name) {
            if (($package = $report->package($name)) !== null) {
                $found[] = $this->found($name, $package);
            }
        }

        if (! $wanted->servesHttp) {
            return $found === [] ? null : new ComponentDecision('edge', 'Web server', Decision::Skip, 'A web server is installed; this server type does not serve HTTP.', $found);
        }

        $notes = [];

        // The control plane's machine: the control plane's edge owns 80/443 and hands sites to Falak's edge on
        // 8080/8443 (falak-ctl local-server enable writes the agent's local-edge config; the agent reports local_edge).
        if ($wanted->controlPlaneHost && ! $wanted->localEdge) {
            $message = 'The control plane\'s edge owns ports 80 and 443 here, and the agent has not set up its local edge (8080/8443) yet.';

            return new ComponentDecision('edge', 'Web server', Decision::Block, $message, $found, notes: [Note::block($message, 'Run sudo falak-ctl local-server enable on the host, then re-check.')]);
        }

        $ports = $wanted->controlPlaneHost ? self::LOCAL_EDGE_PORTS : (array) ($this->rules['edge_ports'] ?? [80, 443]);

        foreach ($ports as $port) {
            $notes = [...$notes, ...$this->portNotes($report, (int) $port, "Falak's edge", [], self::EDGE_UNIT)];
        }

        if ($report->serviceActive('caddy.service') && ! $this->mentions($notes, 'caddy')) {
            $notes[] = Note::block('caddy.service is running; Falak would stop and disable it for its own edge (falak-edge).',
                'Move the sites it serves into Falak, then stop it (systemctl disable --now caddy) and re-check.');
        }

        foreach ($webServers as $name => $label) {
            $service = $report->service("{$name}.service");

            if ($service !== null && ($service['active'] ?? '') !== 'active' && ($service['enabled'] ?? '') === 'enabled') {
                $notes[] = Note::warning("{$label} is installed and enabled but not running; it would take port 80 at the next boot.", "Disable it: systemctl disable {$name}.");
            }
        }

        if ($this->hasBlock($notes)) {
            return new ComponentDecision('edge', 'Web server', Decision::Block, $notes[0]->message, $found, notes: $notes);
        }

        if ($report->service(self::EDGE_UNIT) !== null) {
            return new ComponentDecision('edge', 'Web server', Decision::Adopt, "Falak's edge (falak-edge) is already set up.", $found, notes: $notes);
        }

        if (! $frankenphp && ($caddy = $report->package('caddy')) !== null) {
            return new ComponentDecision('edge', 'Web server', Decision::Adopt, 'Uses the installed Caddy '.MachineReport::upstream($caddy['version'] ?? null).' as falak-edge (its caddy.service is not running).', $found, notes: $notes);
        }

        $where = $wanted->controlPlaneHost ? "ports 8080 and 8443, behind the control plane's edge" : 'ports 80 and 443';

        return new ComponentDecision('edge', 'Web server', Decision::Install, $frankenphp
            ? "FrankenPHP serves {$where} as falak-edge."
            : "Installs Caddy from Caddy's repository and runs it as falak-edge on {$where}.", $found, notes: $notes);
    }

    private function php(MachineReport $report, Wanted $wanted): ?ComponentDecision
    {
        $binaries = $report->list('php');
        $found = array_map(fn (array $b) => ['name' => 'PHP', 'version' => $b['version'] ?? null, 'source' => $this->binarySource($b)], $binaries);
        $runtime = $wanted->stack->phpRuntime;

        if ($runtime === null) {
            return $found === [] ? null : new ComponentDecision('php', 'PHP', Decision::Skip, 'PHP is installed; this server does not run PHP sites.', $found);
        }

        $notes = [];

        foreach ($binaries as $binary) {
            if (($binary['path'] ?? '') === '/usr/local/bin/php') {
                $notes[] = Note::warning('A php binary in /usr/local/bin'.(isset($binary['version']) ? " (PHP {$binary['version']})" : '').' comes before Falak\'s PHP on PATH.', 'Remove or rename /usr/local/bin/php if deploy hooks and the CLI should use Falak\'s PHP.');
            }
        }

        foreach ($report->list('frankenphp') as $binary) {
            if ($runtime === 'frankenphp' && ($binary['source'] ?? '') !== 'falak') {
                $notes[] = Note::warning('A FrankenPHP binary at '.($binary['path'] ?? '?').' was not installed by Falak; Falak installs its pinned build at /usr/local/bin/frankenphp.');
            }
        }

        $packaged = array_values(array_filter($binaries, fn (array $b) => isset($b['package'])));
        $present = array_values(array_intersect($wanted->phpVersions, array_map(fn (array $b) => (string) ($b['version'] ?? ''), $packaged)));
        $versions = implode(', ', $wanted->phpVersions);

        if ($present === []) {
            return new ComponentDecision('php', 'PHP', Decision::Install, "Installs PHP {$versions} with Falak's extensions".match ($runtime) {
                'frankenphp' => ' and FrankenPHP.', 'cli' => ' (the CLI, for builds).', default => ' and PHP-FPM.'
            }, $found, notes: $notes);
        }

        $sources = array_values(array_unique(array_map(fn (array $b) => $this->binarySource($b), array_filter($packaged, fn (array $b) => in_array($b['version'] ?? '', $present, true)))));

        return new ComponentDecision('php', 'PHP', Decision::Complete,
            'PHP '.implode(', ', $present).' is installed ('.implode(', ', $sources).'); adds the missing versions and extensions from the same source.', $found, notes: $notes);
    }

    private function node(MachineReport $report, Wanted $wanted): ?ComponentDecision
    {
        $binaries = $report->list('node');
        $found = array_map(fn (array $b) => ['name' => 'Node', 'version' => $b['version'] ?? null, 'source' => $this->binarySource($b)], $binaries);

        if ($wanted->nodeVersion === null) {
            return $found === [] ? null : new ComponentDecision('node', 'Node', Decision::Skip, 'Node is installed; this server does not use Node.', $found);
        }

        $notes = [];
        $falak = false;

        foreach ($binaries as $binary) {
            $source = (string) ($binary['source'] ?? '');

            if ($source === 'falak') {
                $falak = $falak || ($binary['version'] ?? null) === $wanted->nodeVersion;

                continue;
            }

            if (($binary['path'] ?? '') === '/usr/local/bin/node') {
                $notes[] = Note::warning('/usr/local/bin/node was not installed by Falak; Falak\'s default Node replaces it with a link to /opt/falak/node.');
            } else {
                $notes[] = Note::info(trim('Node '.($binary['version'] ?? '')).' at '.($binary['path'] ?? 'an unknown path')." ({$this->binarySource($binary)}) stays as it is; sites run Falak's Node.");
            }
        }

        return $falak
            ? new ComponentDecision('node', 'Node', Decision::Adopt, "Falak's Node {$wanted->nodeVersion} is already installed.", $found, notes: $notes)
            : new ComponentDecision('node', 'Node', Decision::Install, "Installs Node {$wanted->nodeVersion} in /opt/falak/node.", $found, notes: $notes);
    }

    private function ssh(MachineReport $report, Wanted $wanted): ComponentDecision
    {
        $ssh = $report->section('ssh');
        $effective = (array) ($ssh['effective'] ?? []);
        $users = array_values(array_filter((array) ($ssh['users'] ?? []), 'is_array'));
        $withKeys = array_values(array_filter($users, fn (array $u) => (int) ($u['authorized_keys'] ?? 0) > 0));
        // Users whose keys still let them in once Falak turns off passwords: root only when no earlier drop-in or the
        // current config keeps root out, and only users sshd's Allow/Deny lists let in.
        $canLogIn = array_values(array_filter($withKeys, fn (array $u) => $this->sshAllowed($u, $ssh)));
        // Keyboard-interactive is password login too (PAM asks for the password).
        $password = $this->yes($effective['passwordauthentication'] ?? 'yes') || $this->yes($effective['kbdinteractiveauthentication'] ?? 'no')
            || $this->yes($effective['challengeresponseauthentication'] ?? 'no');
        $found = [['name' => 'OpenSSH', 'version' => MachineReport::upstream($report->package('openssh-server')['version'] ?? null), 'source' => 'password login '.($password ? 'on' : 'off')]];
        $notes = [];

        if ($ssh !== [] && $password && $canLogIn === []) {
            $excluded = array_values(array_diff(array_map(fn (array $u) => (string) ($u['name'] ?? '?'), $withKeys), array_map(fn (array $u) => (string) ($u['name'] ?? '?'), $canLogIn)));
            $notes[] = Note::block('Password login would be turned off, but no user who may log in over SSH has a key in authorized_keys'
                .($excluded !== [] ? ' ('.implode(', ', $excluded).' has keys but is not allowed to log in: PermitRootLogin, AllowUsers/DenyUsers or AllowGroups/DenyGroups).' : '.'),
                'Add your public key to ~/.ssh/authorized_keys of a sudo user sshd lets in (or root when root login is allowed), then re-check. Falak turns off password login.');
        }

        foreach ((array) ($ssh['drop_ins'] ?? []) as $dropIn) {
            $file = (string) ($dropIn['file'] ?? '');

            if ($file === '' || strcmp($file, self::SSH_DROP_IN) >= 0) {
                continue;
            }

            foreach (['passwordauthentication' => 'PasswordAuthentication', 'permitrootlogin' => 'PermitRootLogin', 'port' => 'Port'] as $key => $keyword) {
                if (isset($dropIn['settings'][$key])) {
                    $notes[] = Note::warning("{$file} sets {$keyword} {$dropIn['settings'][$key]} and is read before Falak's 50-falak.conf, so it wins.",
                        "Remove the {$keyword} line from {$file} if Falak's SSH settings should apply.");
                }
            }
        }

        $ports = array_filter(explode(' ', (string) ($effective['port'] ?? '')));

        if ($ports !== [] && ! in_array((string) $wanted->sshPort, $ports, true)) {
            $notes[] = Note::warning('sshd listens on port '.implode(', ', $ports)." today; Falak moves SSH to port {$wanted->sshPort}.",
                "Make sure port {$wanted->sshPort} is open in your provider's firewall before provisioning.");
        }

        if (($ssh['effective_source'] ?? null) === 'files') {
            $notes[] = Note::info('sshd -T could not run during the check; the SSH settings were read from the config files.');
        }

        if ($this->hasBlock($notes)) {
            return new ComponentDecision('ssh', 'SSH', Decision::Block, $notes[0]->message, $found, notes: $notes);
        }

        $who = $canLogIn === [] ? '' : ' Keys found for '.implode(', ', array_map(fn (array $u) => (string) ($u['name'] ?? '?'), $canLogIn)).'.';

        return new ComponentDecision('ssh', 'SSH', Decision::Install, "Key-only login, root without password, port {$wanted->sshPort}.{$who}", $found, notes: $notes);
    }

    private function yes(mixed $value): bool
    {
        return strtolower(trim((string) $value)) === 'yes';
    }

    /**
     * Whether sshd lets a user with keys in: root needs PermitRootLogin other than no / forced-commands-only (the current
     * value and any drop-in read before Falak's), everyone must pass AllowUsers/DenyUsers/AllowGroups/DenyGroups.
     *
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $ssh
     */
    private function sshAllowed(array $user, array $ssh): bool
    {
        $effective = (array) ($ssh['effective'] ?? []);
        $name = (string) ($user['name'] ?? '');
        $groups = array_map('strval', (array) ($user['groups'] ?? []));

        if ((int) ($user['uid'] ?? -1) === 0) {
            $values = [(string) ($effective['permitrootlogin'] ?? '')];

            foreach ((array) ($ssh['drop_ins'] ?? []) as $dropIn) {
                if (strcmp((string) ($dropIn['file'] ?? ''), self::SSH_DROP_IN) < 0 && isset($dropIn['settings']['permitrootlogin'])) {
                    $values[] = (string) $dropIn['settings']['permitrootlogin'];
                }
            }

            foreach ($values as $value) {
                if (in_array(strtolower(trim($value)), ['no', 'forced-commands-only'], true)) {
                    return false;
                }
            }
        }

        $list = fn (string $key) => array_values(array_filter(explode(' ', (string) ($effective[$key] ?? ''))));
        // user@host patterns: only the user part decides here (the host is unknown).
        $userMatches = fn (array $patterns) => array_filter($patterns, fn (string $p) => fnmatch(explode('@', $p, 2)[0], $name)) !== [];
        $groupMatches = fn (array $patterns) => array_filter($patterns, fn (string $p) => array_filter($groups, fn (string $g) => fnmatch($p, $g)) !== []) !== [];

        if ($userMatches($list('denyusers')) || $groupMatches($list('denygroups'))) {
            return false;
        }

        if (($allow = $list('allowusers')) !== [] && ! $userMatches($allow)) {
            return false;
        }

        return ($allow = $list('allowgroups')) === [] || $groupMatches($allow);
    }

    private function firewall(MachineReport $report): ComponentDecision
    {
        $fw = $report->section('firewall');
        $found = [];
        $notes = [];

        foreach (['ufw' => 'ufw', 'firewalld' => 'firewalld'] as $key => $label) {
            $state = (string) ($fw[$key] ?? 'absent');

            if ($state === 'absent') {
                continue;
            }

            $found[] = ['name' => $label, 'version' => null, 'source' => $state];

            if ($state === 'active') {
                $notes[] = Note::warning("{$label} is active next to Falak's firewall: a port must be allowed by both, or sites are not reachable.",
                    $key === 'ufw' ? 'Allow the ports Falak opens (ufw allow 80,443/tcp) or turn ufw off (ufw disable).' : 'Allow the ports Falak opens (firewall-cmd --permanent --add-service={http,https}) or stop firewalld.');
            }
        }

        $others = array_values(array_diff(array_map('strval', (array) ($fw['nft_tables'] ?? [])), ['inet falak', ...self::IPTABLES_TABLES]));

        if ($others !== []) {
            $notes[] = Note::info('Other nftables tables stay as they are: '.implode(', ', $others).'.');
        }

        return new ComponentDecision('firewall', 'Firewall', Decision::Install, "Falak's nftables firewall (table inet falak) is applied once provisioning finishes.", $found, notes: $notes);
    }

    private function swap(MachineReport $report, Wanted $wanted): ComponentDecision
    {
        $swaps = $report->list('swap');
        $found = array_map(fn (array $s) => ['name' => (string) ($s['name'] ?? '?'), 'version' => null, 'source' => $this->bytes((int) ($s['size_bytes'] ?? 0))], $swaps);
        $foreign = array_values(array_filter($swaps, fn (array $s) => ($s['name'] ?? '') !== '/swapfile'));

        if ($report->inContainer()) {
            return new ComponentDecision('swap', 'Swap', Decision::Adopt, 'Runs in a container: swap belongs to the host.', $found);
        }

        if ($foreign !== []) {
            return new ComponentDecision('swap', 'Swap', Decision::Adopt, 'Keeps the existing swap ('.implode(', ', array_map(fn (array $s) => (string) ($s['name'] ?? '?'), $foreign)).'); no /swapfile is created.', $found);
        }

        if ($wanted->swapMb <= 0) {
            return new ComponentDecision('swap', 'Swap', $swaps === [] ? Decision::Skip : Decision::Adopt, $swaps === [] ? 'No swap needed with this much memory.' : 'Keeps the existing /swapfile.', $found);
        }

        return new ComponentDecision('swap', 'Swap', Decision::Install, 'Creates a '.$this->bytes($wanted->swapMb << 20).' /swapfile.', $found);
    }

    private function hostname(MachineReport $report, Wanted $wanted): ComponentDecision
    {
        $current = $report->hostname();
        $found = $current !== '' ? [['name' => $current, 'version' => null, 'source' => null]] : [];

        if ($wanted->customServer && $current !== '') {
            return new ComponentDecision('hostname', 'Hostname', Decision::Adopt, "Keeps the machine's hostname {$current}.", $found);
        }

        if ($current === $wanted->hostname) {
            return new ComponentDecision('hostname', 'Hostname', Decision::Adopt, "The hostname is already {$current}.", $found);
        }

        return new ComponentDecision('hostname', 'Hostname', Decision::Install, "Sets the hostname to {$wanted->hostname}.", $found);
    }

    private function unattended(MachineReport $report): ComponentDecision
    {
        $u = $report->section('unattended_upgrades');
        $periodic = is_array($u['periodic'] ?? null) ? $u['periodic'] : null;
        $installed = (bool) ($u['installed'] ?? false);
        // Ubuntu's own 20auto-upgrades (both settings "1", nothing else) is no customisation: Falak writes its config as on
        // any fresh machine.
        $stock = $periodic !== null && array_diff_key($periodic, ['Update-Package-Lists' => 1, 'Unattended-Upgrade' => 1]) === []
            && array_unique(array_values($periodic)) === ['1'];
        $source = ($u['managed_by_falak'] ?? false) ? "Falak's config" : ($periodic === null || $stock ? 'default config' : 'own config');
        $found = $installed || $periodic !== null ? [['name' => 'unattended-upgrades', 'version' => null, 'source' => $source]] : [];

        if ($periodic === null || $stock || ($u['managed_by_falak'] ?? false)) {
            return new ComponentDecision('unattended_upgrades', 'Automatic updates', Decision::Install, 'Turns on automatic security updates (no automatic reboot).', $found);
        }

        $notes = [];

        if (($periodic['Unattended-Upgrade'] ?? '1') === '0') {
            $notes[] = Note::warning('Automatic upgrades are turned off in 20auto-upgrades; Falak keeps it that way.', 'Set APT::Periodic::Unattended-Upgrade "1" in /etc/apt/apt.conf.d/20auto-upgrades to get security updates.');
        }

        return new ComponentDecision('unattended_upgrades', 'Automatic updates', Decision::Adopt, 'Keeps the existing automatic-update config; Falak writes none.', $found, notes: $notes);
    }

    private function fail2ban(MachineReport $report): ComponentDecision
    {
        $f = $report->section('fail2ban');
        $jails = count((array) ($f['jails'] ?? []));

        if (! ($f['installed'] ?? false)) {
            return new ComponentDecision('fail2ban', 'fail2ban', Decision::Install, 'Installs fail2ban with its default SSH jail.');
        }

        $package = $report->package('fail2ban');
        $found = [$package !== null ? $this->found('fail2ban', $package) : ['name' => 'fail2ban', 'version' => null, 'source' => null]];
        $notes = $jails > 0 ? [Note::info("{$jails} custom jail ".($jails === 1 ? 'file stays' : 'files stay').'; Falak writes no jails.')] : [];

        return new ComponentDecision('fail2ban', 'fail2ban', Decision::Adopt, 'Uses the installed fail2ban and makes sure it runs.', $found, keep: ['fail2ban'], service: 'fail2ban', notes: $notes);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{name: string, version: ?string, source: string}
     */
    private function found(string $name, array $package): array
    {
        return ['name' => $name, 'version' => MachineReport::upstream((string) ($package['version'] ?? '')), 'source' => MachineReport::sourceOf($package)];
    }

    /**
     * @param  array<string, mixed>  $binary
     */
    private function binarySource(array $binary): string
    {
        return match ($binary['source'] ?? '') {
            'falak' => 'Falak',
            'archive' => 'Ubuntu archive',
            'nodesource' => 'NodeSource',
            'nvm' => 'nvm',
            'snap' => 'snap',
            'unknown' => 'unknown source',
            'vendor' => (string) (parse_url((string) ($binary['repo'] ?? ''), PHP_URL_HOST) ?: 'another repository'),
            default => 'manual install',
        };
    }

    /**
     * @param  list<Note>  $notes
     */
    private function hasBlock(array $notes): bool
    {
        foreach ($notes as $note) {
            if ($note->severity === Severity::Block) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Note>  $notes
     */
    private function mentions(array $notes, string $needle): bool
    {
        foreach ($notes as $note) {
            if (str_contains($note->message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1 << 30 ? round($bytes / (1 << 30), 1).' GB' : (int) round($bytes / (1 << 20)).' MB';
    }
}
