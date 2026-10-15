<?php

namespace Database\Seeders;

use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Command;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Recipes\Domain\Enums\RunStatus;
use Falak\Recipes\Domain\Enums\TargetStatus as RunTargetStatus;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Domain\Models\RunTarget;
use Falak\Servers\Application\Actions\AttachSshKey;
use Falak\Servers\Application\Actions\CreateSshKey;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalFrame;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Realistic infrastructure for the UI demo (called by UiDemoSeeder): agents with heartbeat metrics, PHP versions,
 * SSH keys, firewalls, a private network, recipe runs, a terminal recording, a provisioning log, a custom server
 * waiting for its agent and one the machine check stopped (needs attention). Written directly against module models — local demo data only.
 */
class InfrastructureDemoSeeder extends Seeder
{
    private const GB = 1024 ** 3;

    public function run(string $organizationId, string $userId): void
    {
        $servers = Server::query()->where('organization_id', $organizationId)->get()->keyBy('name');
        $now = now();

        $specs = [
            'app-1' => ['hetzner', 'fsn1', 'cpx31', '49.12.40.11', '10.0.0.2', 4, 8, 160, AgentStatus::Online, 0.34, 0.52],
            'app-2' => ['hetzner', 'fsn1', 'cpx31', '49.12.40.12', '10.0.0.3', 4, 8, 160, AgentStatus::Offline, 0.22, 0.41],
            'db-1' => ['hetzner', 'nbg1', 'cpx41', '49.12.51.20', '10.0.0.4', 8, 16, 240, AgentStatus::Online, 0.18, 0.71],
            'worker-1' => ['digitalocean', 'ams3', 's-2vcpu-4gb', '167.99.12.40', null, 2, 4, 80, AgentStatus::Online, 0.61, 0.38],
            'edge-1' => ['vultr', 'fra', 'vc2-1c-2gb', '45.77.61.3', null, 1, 2, 55, null, 0, 0],
        ];

        foreach ($specs as $name => [$provider, $region, $size, $ipv4, $private, $cpus, $memory, $disk, $agentStatus, $cpuBase, $memBase]) {
            $server = $servers[$name] ?? null;

            if (! $server) {
                continue;
            }

            $server->forceFill([
                'provider' => $provider,
                'region' => $region,
                'size' => $size,
                'image' => 'ubuntu-24.04',
                'ipv4' => $ipv4,
                'ipv6' => '2a01:4f8:c012:'.substr(md5($name), 0, 4).'::1',
                'private_ipv4' => $private,
                'provider_server_id' => (string) random_int(40000000, 49999999),
                'os' => 'ubuntu 24.04',
                'arch' => 'amd64',
                'cpus' => $cpus,
                'memory_bytes' => $memory * self::GB,
                'disk_bytes' => $disk * self::GB,
                'provisioned_at' => $server->status === ServerStatus::Active ? $now->copy()->subDays(12) : null,
                'status_message' => $name === 'edge-1' ? 'provision.apply failed: apt-get install caddy exited with 100 (unable to fetch archive).' : null,
            ])->save();

            if ($agentStatus === null) {
                continue;
            }

            $lastHeartbeat = $agentStatus === AgentStatus::Online ? $now->copy()->subSeconds(8) : $now->copy()->subMinutes(23);
            $agent = Agent::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'status' => $agentStatus,
                'hostname' => $name,
                'arch' => 'amd64',
                'agent_version' => '1.4.2',
                'facts' => [
                    // Every server runs Docker (sites, compose stacks and database containers).
                    'kernel' => '6.8.0-45-generic', 'docker' => '27.3.1', 'runtimes' => ['php' => ['8.3', '8.4']], 'features' => ['fn.v1', 'fn.v2', 'fn.v3'],
                ],
                'metrics' => [
                    'at' => $lastHeartbeat->toIso8601String(),
                    'uptime_s' => 12 * 86400 + 3 * 3600,
                    'load' => [round($cpuBase * $cpus, 2), round($cpuBase * $cpus * 0.9, 2), round($cpuBase * $cpus * 0.8, 2)],
                    'cpu_percent' => round($cpuBase * 100, 1),
                    'memory_used_bytes' => (int) ($memBase * $memory * self::GB),
                    'disk_used_bytes' => (int) (0.42 * $disk * self::GB),
                ],
                'enrolled_at' => $now->copy()->subDays(12),
                'last_heartbeat_at' => $lastHeartbeat,
            ]);

            $this->metrics($agent->id, $server->id, $cpuBase, $memBase, $memory * self::GB, $disk * self::GB, $cpus, $agentStatus === AgentStatus::Online ? $now : $lastHeartbeat);
        }

        $this->php($servers);
        $this->provisioningLog($organizationId, $servers['worker-1'] ?? null);
        $this->customServer($organizationId);
        $this->attentionServer($organizationId);
        $this->sshKeys($organizationId, $userId, $servers);
        $this->firewalls($organizationId, $servers);
        $this->privateNetwork($organizationId, $servers);
        $this->recipes($organizationId, $userId, $servers);
        $this->terminal($organizationId, $userId, $servers['app-1'] ?? null);
    }

    /** One sample per minute for the last 24 hours, with a gentle daily wave and noise. */
    private function metrics(string $agentId, string $serverId, float $cpuBase, float $memBase, int $memory, int $disk, int $cpus, Carbon $until): void
    {
        $rows = [];

        for ($minute = 24 * 60; $minute >= 0; $minute--) {
            $at = $until->copy()->subMinutes($minute);
            $wave = sin($minute / 180) * 0.12 + (mt_rand(-50, 50) / 1000);
            $cpu = max(0.02, min(0.97, $cpuBase + $wave));
            $rows[] = [
                'agent_id' => $agentId,
                'server_id' => $serverId,
                'at' => $at,
                'uptime_s' => 12 * 86400,
                'load1' => round($cpu * $cpus, 2),
                'load5' => round($cpu * $cpus * 0.92, 2),
                'load15' => round($cpu * $cpus * 0.85, 2),
                'cpu_percent' => round($cpu * 100, 1),
                'memory_used_bytes' => (int) (max(0.1, min(0.95, $memBase + $wave / 3)) * $memory),
                'disk_used_bytes' => (int) ((0.40 + (24 * 60 - $minute) / (24 * 60) * 0.02) * $disk),
            ];

            if (count($rows) === 500) {
                DB::table('fleet_agent_metrics')->insert($rows);
                $rows = [];
            }
        }

        DB::table('fleet_agent_metrics')->insert($rows);
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function php($servers): void
    {
        foreach (['app-1' => ['8.3', '8.4'], 'app-2' => ['8.4'], 'worker-1' => ['8.4']] as $name => $versions) {
            $server = $servers[$name] ?? null;

            if (! $server) {
                continue;
            }

            foreach ($versions as $version) {
                PhpVersion::query()->create([
                    'server_id' => $server->id,
                    'version' => $version,
                    'status' => PhpVersionStatus::Installed,
                    'is_default' => $version === '8.4',
                    'ini' => $version === '8.4' ? ['memory_limit' => '512M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M'] : [],
                    'fpm' => PhpVersion::defaultFpm($server->memory_bytes),
                ]);
            }
        }
    }

    private function provisioningLog(string $organizationId, ?Server $server): void
    {
        if (! $server) {
            return;
        }

        $agentId = Agent::query()->where('server_id', $server->id)->value('id');
        $started = now()->subMinutes(3);
        $command = Command::query()->create([
            'organization_id' => $organizationId,
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'type' => 'provision.apply',
            'payload' => '{}',
            'timeout_s' => 1800,
            'idempotency_key' => "provision:{$server->id}:1",
            'status' => CommandStatus::Running,
            'attempts' => 1,
            'queued_at' => $started->copy()->subSeconds(4),
            'delivered_at' => $started->copy()->subSeconds(2),
            'started_at' => $started,
        ]);

        $lines = [
            "\e[1m==> Preparing system\e[0m",
            'Hit:1 http://mirror.hetzner.com/ubuntu/packages noble InRelease',
            'Reading package lists... Done',
            "\e[1m==> Creating falak user\e[0m",
            'useradd: falak (uid 1001) created',
            "\e[1m==> Installing PHP 8.4 (FrankenPHP)\e[0m",
            'Setting up frankenphp (1.4.4) ...',
            "\e[32m✓\e[0m frankenphp.service enabled",
            "\e[1m==> Installing Docker Engine\e[0m",
            'Setting up docker-ce (5:27.3.1-1~ubuntu.24.04~noble) ...',
            "\e[33mwarning:\e[0m br_netfilter module not loaded, loading it now",
            "\e[1m==> Configuring supervisor\e[0m",
        ];

        foreach ($lines as $seq => $line) {
            DB::table('fleet_command_events')->insert([
                'command_id' => $command->id,
                'seq' => $seq,
                'kind' => 'output',
                'stream' => str_contains($line, 'warning') ? 'stderr' : 'stdout',
                'data' => $line."\n",
                'at' => $started->copy()->addSeconds($seq * 9),
            ]);
        }

        $server->forceFill(['provision_command_id' => $command->id])->save();
    }

    /**
     * A customer's own VM the machine check stopped: nginx holds port 80. Docker from Docker's repository (with compose
     * and buildx) is adopted; the MariaDB on it is left alone (Falak's databases run in containers).
     */
    private function attentionServer(string $organizationId): void
    {
        $server = Server::query()->create([
            'organization_id' => $organizationId,
            'name' => 'customer-vm',
            'type' => ServerType::App,
            'status' => ServerStatus::NeedsAttention,
            'provider' => 'custom',
            'ipv4' => '203.0.113.77',
            'os' => 'ubuntu 26.04',
            'arch' => 'amd64',
            'cpus' => 4,
            'memory_bytes' => 8 * self::GB,
            'disk_bytes' => 120 * self::GB,
            'timezone' => 'UTC',
            'stack' => new Stack('frankenphp', ['8.5'], '8.5', '22'),
        ]);

        Agent::query()->create([
            'organization_id' => $organizationId,
            'server_id' => $server->id,
            'status' => AgentStatus::Online,
            'hostname' => 'customer-vm',
            'arch' => 'amd64',
            'agent_version' => '0.6.0',
            'facts' => ['kernel' => '7.0.0-12-generic', 'docker' => '28.1.1', 'runtimes' => [], 'features' => ['provision.v2']],
            'metrics' => ['at' => now()->toIso8601String(), 'uptime_s' => 86400, 'load' => [0.1, 0.1, 0.1], 'cpu_percent' => 3.2, 'memory_used_bytes' => (int) (1.4 * self::GB), 'disk_used_bytes' => 18 * self::GB],
            'enrolled_at' => now()->subMinutes(14),
            'last_heartbeat_at' => now()->subSeconds(6),
        ]);

        $docker = 'https://download.docker.com/linux/ubuntu';
        $package = fn (string $name, string $version, string $origin = 'archive', ?string $repo = 'http://archive.ubuntu.com/ubuntu', ?string $label = 'Ubuntu') => array_filter(['name' => $name, 'version' => $version, 'origin' => $origin, 'repo' => $repo, 'label' => $label]);
        $report = [
            'version' => 1, 'hostname' => 'customer-vm', 'os' => ['id' => 'ubuntu', 'version' => '26.04', 'codename' => 'resolute'], 'in_container' => false,
            'packages' => [
                $package('docker-ce', '5:28.1.1-1~ubuntu.26.04~resolute', 'vendor', $docker, 'Docker'),
                $package('docker-compose-plugin', '2.35.1-1~ubuntu.26.04~resolute', 'vendor', $docker, 'Docker'),
                $package('docker-buildx-plugin', '0.23.0-1~ubuntu.26.04~resolute', 'vendor', $docker, 'Docker'),
                $package('mariadb-server', '1:11.8.2-1'),
                $package('nginx', '1.28.0-2ubuntu1'),
                $package('openssh-server', '1:10.0p1-2ubuntu1'),
                $package('curl', '8.14.1-2ubuntu1'),
                $package('git', '1:2.48.1-0ubuntu1'),
            ],
            'snaps' => [],
            'apt_sources' => [['file' => '/etc/apt/sources.list.d/docker.list', 'uris' => [$docker]]],
            'services' => [
                ['unit' => 'docker.service', 'active' => 'active', 'enabled' => 'enabled'],
                ['unit' => 'nginx.service', 'active' => 'active', 'enabled' => 'enabled'],
                ['unit' => 'mariadb.service', 'active' => 'active', 'enabled' => 'enabled'],
            ],
            'listeners' => [
                ['port' => 22, 'address' => '0.0.0.0', 'process' => 'sshd', 'unit' => 'ssh.service'],
                ['port' => 80, 'address' => '0.0.0.0', 'process' => 'nginx', 'pid' => 1201, 'unit' => 'nginx.service'],
                ['port' => 3306, 'address' => '127.0.0.1', 'process' => 'mariadbd', 'pid' => 1302, 'unit' => 'mariadb.service'],
            ],
            'containers' => [],
            'docker' => [
                'engine_package' => 'docker-ce', 'client_version' => '28.1.1', 'server_version' => '28.1.1',
                'compose' => ['version' => '2.35.1', 'package' => 'docker-compose-plugin', 'path' => '/usr/libexec/docker/cli-plugins/docker-compose'],
                'buildx' => ['version' => '0.23.0', 'package' => 'docker-buildx-plugin', 'path' => '/usr/libexec/docker/cli-plugins/docker-buildx'],
                'snap' => false, 'rootless' => false, 'system_daemon' => true, 'daemon' => null,
            ],
            'ssh' => [
                'drop_ins' => [['file' => '/etc/ssh/sshd_config.d/50-cloud-init.conf', 'settings' => ['passwordauthentication' => 'yes']]],
                'effective' => ['passwordauthentication' => 'yes', 'permitrootlogin' => 'prohibit-password', 'port' => '22'],
                'effective_source' => 'sshd -T',
                'users' => [['name' => 'root', 'uid' => 0, 'authorized_keys' => 1], ['name' => 'ubuntu', 'uid' => 1000, 'authorized_keys' => 1]],
            ],
            'firewall' => ['ufw' => 'active', 'firewalld' => 'absent', 'nft_tables' => ['ip filter', 'ip nat']],
            'swap' => [['name' => '/swap.img', 'type' => 'file', 'size_bytes' => 4 * self::GB]],
            'node' => [['path' => '/usr/bin/node', 'version' => '20.19.2', 'source' => 'nodesource', 'package' => 'nodejs', 'repo' => 'https://deb.nodesource.com/node_20.x']],
            'php' => [], 'frankenphp' => [],
            'unattended_upgrades' => ['installed' => true, 'periodic' => ['Update-Package-Lists' => '1', 'Unattended-Upgrade' => '1'], 'managed_by_falak' => false],
            'fail2ban' => ['installed' => false, 'active' => false, 'jails' => []],
            'errors' => [],
        ];

        $check = app(MachineChecks::class)->decide($server, $report);
        $server->forceFill(['status_message' => $check->summary()])->save();

        MachineInspection::query()->create([
            'server_id' => $server->id,
            'command_id' => (string) Str::ulid(),
            'purpose' => MachineInspection::PURPOSE_PROVISION,
            'status' => MachineInspection::FINISHED,
            'report' => $report,
            'decisions' => $check->toArray(),
            'blocking' => $check->blocking(),
            'agent_version' => '0.6.0',
            'checked_at' => now()->subMinutes(12),
        ]);
    }

    private function customServer(string $organizationId): void
    {
        Server::query()->create([
            'organization_id' => $organizationId,
            'name' => 'office-nuc',
            'type' => ServerType::Worker,
            'status' => ServerStatus::Creating,
            'provider' => 'custom',
            'timezone' => 'Europe/Amsterdam',
            'stack' => Stack::defaultsFor(ServerType::Worker),
            'install_command' => 'curl -fsSL https://panel.falak.test/install/'.Str::lower(Str::random(40)).' | sudo bash',
        ]);
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function sshKeys(string $organizationId, string $userId, $servers): void
    {
        $create = app(CreateSshKey::class);
        $attach = app(AttachSshKey::class);

        $laptop = $create($organizationId, $userId, 'ada@laptop', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH2FPCLaNIa5y0nRNSFX+6ChIQ9P5jPmbmIsK+bmeo5s ada@laptop');
        $ci = $create($organizationId, $userId, 'GitHub Actions deploy', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEZON12LwqEThsky8ygzEZmjL875DO3VY+su/31D7zKl ci@deploy');

        foreach (['app-1', 'app-2', 'db-1'] as $name) {
            if (isset($servers[$name])) {
                $attach($servers[$name], $laptop, 'falak');
            }
        }

        if (isset($servers['app-1'])) {
            $attach($servers['app-1'], $ci, 'falak');
            $attach($servers['app-1'], $laptop, 'root');
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function firewalls(string $organizationId, $servers): void
    {
        foreach ($servers as $server) {
            $rules = [['SSH', '22', null]];

            if (in_array($server->type, [ServerType::App, ServerType::Web, ServerType::LoadBalancer], true)) {
                $rules[] = ['HTTP', '80', null];
                $rules[] = ['HTTPS', '443', null];
            }

            foreach ($rules as $position => [$name, $port, $source]) {
                FirewallRule::query()->create([
                    'organization_id' => $organizationId,
                    'server_id' => $server->id,
                    'name' => $name,
                    'action' => RuleAction::Allow,
                    'protocol' => RuleProtocol::Tcp,
                    'port' => $port,
                    'source' => $source,
                    'is_default' => true,
                    'position' => $position,
                ]);
            }

            $active = $server->status === ServerStatus::Active;
            FirewallState::query()->create([
                'server_id' => $server->id,
                'organization_id' => $organizationId,
                'revision' => $active ? 3 : 0,
                'desired_hash' => $active ? str_repeat('a', 64) : null,
                'applied_hash' => $active ? str_repeat('a', 64) : null,
                'status' => $active ? ApplyStatus::Applied : ApplyStatus::Pending,
                'ruleset_sha256' => $active ? hash('sha256', $server->id) : null,
                'applied_at' => $active ? now()->subDays(2) : null,
            ]);
        }

        if (isset($servers['db-1'])) {
            FirewallRule::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $servers['db-1']->id,
                'name' => 'PostgreSQL from app servers',
                'action' => RuleAction::Allow,
                'protocol' => RuleProtocol::Tcp,
                'port' => '5432',
                'source' => '10.0.0.0/24',
                'is_default' => false,
                'position' => 5,
            ]);
            FirewallRule::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $servers['db-1']->id,
                'name' => 'Block scanner',
                'action' => RuleAction::Deny,
                'protocol' => RuleProtocol::Any,
                'port' => null,
                'source' => '203.0.113.0/24',
                'is_default' => false,
                'position' => 6,
            ]);
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function privateNetwork(string $organizationId, $servers): void
    {
        $network = PrivateNetwork::query()->create([
            'organization_id' => $organizationId,
            'name' => 'backend',
            'cidr' => '10.90.0.0/24',
            'interface' => 'falak0',
            'listen_port' => 51820,
        ]);
        PrivateNetwork::query()->create([
            'organization_id' => $organizationId,
            'name' => 'observability',
            'cidr' => '10.91.0.0/24',
            'interface' => 'falak1',
            'listen_port' => 51821,
        ]);

        foreach (['app-1', 'app-2', 'db-1'] as $index => $name) {
            if (! isset($servers[$name])) {
                continue;
            }

            PrivateNetworkMember::query()->create([
                'network_id' => $network->id,
                'organization_id' => $organizationId,
                'server_id' => $servers[$name]->id,
                'address' => '10.90.0.'.($index + 1),
                'public_key' => base64_encode(random_bytes(32)),
                'key_status' => KeyStatus::Installed,
                'status' => $name === 'app-2' ? ApplyStatus::Pending : ApplyStatus::Applied,
                'revision' => 2,
                'applied_at' => $name === 'app-2' ? null : now()->subDays(3),
            ]);
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function recipes(string $organizationId, string $userId, $servers): void
    {
        $recipe = Recipe::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Clear OPcache',
            'description' => 'Reload FrankenPHP workers so fresh code and ini changes take effect.',
            'script' => "#!/usr/bin/env bash\nset -euo pipefail\nsystemctl reload frankenphp\necho \"opcache cleared on \$(hostname)\"",
            'user' => 'root',
        ]);

        $runs = [
            [['app-1', 'app-2'], [RunTargetStatus::Succeeded, RunTargetStatus::Unavailable], RunStatus::Partial, 90],
            [['app-1'], [RunTargetStatus::Succeeded], RunStatus::Succeeded, 30],
            [['app-1', 'db-1'], [RunTargetStatus::Failed, RunTargetStatus::Succeeded], RunStatus::Partial, 5],
        ];

        foreach ($runs as [$names, $statuses, $status, $minutesAgo]) {
            $at = now()->subMinutes($minutesAgo);
            $run = Run::query()->create([
                'organization_id' => $organizationId,
                'recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name,
                'script' => $recipe->script,
                'user' => $recipe->user,
                'env' => [],
                'timeout_s' => 900,
                'requested_by' => $userId,
                'status' => $status,
                'started_at' => $at,
                'finished_at' => $at->copy()->addSeconds(4),
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            foreach ($names as $index => $name) {
                if (! isset($servers[$name])) {
                    continue;
                }

                $targetStatus = $statuses[$index];
                RunTarget::query()->create([
                    'run_id' => $run->id,
                    'organization_id' => $organizationId,
                    'server_id' => $servers[$name]->id,
                    'server_name' => $name,
                    'status' => $targetStatus,
                    'exit_code' => match ($targetStatus) {
                        RunTargetStatus::Succeeded => 0,
                        RunTargetStatus::Failed => 1,
                        default => null,
                    },
                    'error' => match ($targetStatus) {
                        RunTargetStatus::Failed => 'Job for frankenphp.service failed because the control process exited with error code.',
                        RunTargetStatus::Unavailable => 'The server agent is not connected.',
                        default => null,
                    },
                    'duration_ms' => $targetStatus === RunTargetStatus::Unavailable ? null : random_int(900, 3800),
                    'started_at' => $at,
                    'finished_at' => $at->copy()->addSeconds(3),
                ]);
            }
        }
    }

    private function terminal(string $organizationId, string $userId, ?Server $server): void
    {
        if (! $server) {
            return;
        }

        $opened = now()->subHours(2);
        $session = TerminalSession::query()->create([
            'organization_id' => $organizationId,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'user_id' => $userId,
            'unix_user' => 'falak',
            'status' => SessionStatus::Closed,
            'cols' => 120,
            'rows' => 32,
            'initial_cols' => 120,
            'initial_rows' => 32,
            'idle_timeout_s' => 900,
            'shared' => false,
            'channel_epoch' => 1,
            'started_at' => $opened,
            'last_activity_at' => $opened->copy()->addMinutes(4),
            'closed_at' => $opened->copy()->addMinutes(4),
            'close_reason' => 'exited',
            'exit_code' => 0,
            'recording_bytes' => 0,
            'created_at' => $opened,
            'updated_at' => $opened,
        ]);

        $output = [
            [0, "\e[32mfalak@app-1\e[0m:\e[34m~\e[0m$ "],
            [900, "uptime\r\n"],
            [1100, " 14:02:11 up 12 days,  3:04,  1 user,  load average: 0.41, 0.38, 0.35\r\n"],
            [1200, "\e[32mfalak@app-1\e[0m:\e[34m~\e[0m$ "],
            [3200, "df -h /\r\n"],
            [3400, "Filesystem      Size  Used Avail Use% Mounted on\r\n/dev/sda1       152G   64G   82G  44% /\r\n"],
            [3500, "\e[32mfalak@app-1\e[0m:\e[34m~\e[0m$ "],
            [6000, "exit\r\nlogout\r\n"],
        ];
        $bytes = 0;

        foreach ($output as $seq => [$offset, $text]) {
            TerminalFrame::query()->create([
                'session_id' => $session->id,
                'fleet_seq' => $seq,
                'kind' => TerminalFrame::OUTPUT,
                'offset_ms' => $offset,
                'data' => base64_encode($text),
            ]);
            $bytes += strlen($text);
        }

        $session->forceFill(['recording_bytes' => $bytes])->save();
    }
}
