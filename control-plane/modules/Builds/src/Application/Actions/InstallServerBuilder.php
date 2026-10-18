<?php

namespace Falak\Builds\Application\Actions;

use Falak\Builds\Domain\Models\Builder;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerType;

/**
 * Register a Falak server as a build worker: issue a builder token and let the agent install falak-builder as a
 * systemd service polling this control plane. Re-running rotates the token. A `builder` server is a pool builder
 * (any build of the organization); any other server gets a builder that only takes the builds pinned to it
 * (on-server builds). One builder per server (server_id is unique), so a server never runs two builds at once.
 *
 * The service runs in falak-build.slice, capped from the server's facts (memory, cores) so a build can't starve
 * the server: see caps(). Its caps live in a separate env file, so they are re-rendered without a token rotation.
 */
final class InstallServerBuilder
{
    public const ENV_PATH = '/etc/falak/builder.env';

    public const CAPS_ENV_PATH = '/etc/falak/builder-caps.env';

    public const UNIT_PATH = '/etc/systemd/system/falak-builder.service';

    public const SLICE = 'falak-build.slice';

    public const SLICE_PATH = '/etc/systemd/system/'.self::SLICE;

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(ServerData $server, ?string $actorId = null, ?bool $pool = null): Builder
    {
        $token = Builder::newToken();
        $pool ??= Builder::query()->where('server_id', $server->id)->value('pool') ?? $server->type === ServerType::Builder;

        /** @var Builder $builder */
        $builder = Builder::query()->updateOrCreate(['server_id' => $server->id], [
            'organization_id' => $server->organizationId,
            'name' => $server->name,
            'kind' => Builder::KIND_SERVER,
            'token_hash' => Builder::hashToken($token),
            'modes' => ['native', 'docker'],
            'enabled' => true,
            'pool' => (bool) $pool,
        ]);

        $key = "builds:builder:{$builder->id}:".substr(Builder::hashToken($token), 0, 16);

        try {
            $this->agents->dispatch($server->id, 'system.write_file', [
                'path' => self::ENV_PATH,
                'content' => $this->environment($builder, $token),
                'mode' => '0600',
                'owner' => 'root',
            ], 60, "{$key}:env");

            $this->writeCaps($builder, (array) ($this->directory->forServer($server->id)?->facts ?? []), $key);

            $this->agents->dispatch($server->id, 'system.write_file', [
                'path' => self::UNIT_PATH,
                'content' => self::unit(),
                'mode' => '0644',
            ], 60, "{$key}:unit");

            $handle = $this->agents->dispatch($server->id, 'system.exec', ['script' => $this->installScript(), 'user' => 'root'], 600, "{$key}:install");
            $builder->forceFill(['install_command_id' => $handle->id])->save();
        } catch (AgentUnavailable) {
            // The token is issued; installation is retried from the Builders page once the agent is back.
        }

        $this->audit->record('builds.builder_installed', 'server', $server->id, ['builder_id' => $builder->id, 'pool' => (bool) $pool], $server->organizationId, $actorId);

        return $builder;
    }

    /**
     * Re-render the caps of an installed server builder from new facts (memory / cores changed). The slice's limits
     * apply on daemon-reload; the BuildKit caps in the env file apply when falak-builder next restarts (a restart
     * here would kill a running build). Returns false when the caps did not change.
     *
     * @param  array<string, mixed>  $facts
     */
    public function refreshCaps(Builder $builder, array $facts): bool
    {
        if ($builder->server_id === null || self::caps($facts, $builder->pool) === $builder->caps) {
            return false;
        }

        $key = "builds:builder:{$builder->id}:caps:".md5((string) json_encode(self::caps($facts, $builder->pool)));

        try {
            $this->writeCaps($builder, $facts, $key);
            $this->agents->dispatch($builder->server_id, 'system.exec', ['script' => 'systemctl daemon-reload', 'user' => 'root'], 60, "{$key}:reload");
        } catch (AgentUnavailable) {
            return false;
        }

        return true;
    }

    /**
     * Limits for falak-builder on a server: MemoryMax = builds.caps.memory_percent (site servers) or
     * dedicated_memory_percent (builder servers) of its RAM, cores − 1 (min 1) for CPU; unknown facts leave that cap out.
     *
     * @param  array<string, mixed>  $facts  agent facts (memory_bytes, cpus)
     * @return array{memory_max_bytes: ?int, cpus: ?int, tasks_max: int, io_weight: int, cpu_weight: int}
     */
    public static function caps(array $facts, bool $dedicated): array
    {
        $memory = is_numeric($facts['memory_bytes'] ?? null) ? (int) $facts['memory_bytes'] : 0;
        $cores = is_numeric($facts['cpus'] ?? null) ? (int) $facts['cpus'] : 0;
        $percent = max(10, min(95, (int) config($dedicated ? 'builds.caps.dedicated_memory_percent' : 'builds.caps.memory_percent', $dedicated ? 85 : 60)));

        return [
            // Whole MiB: what systemd and BuildKit are given.
            'memory_max_bytes' => $memory > 0 ? intdiv(intdiv($memory * $percent, 100), 1024 ** 2) * 1024 ** 2 : null,
            'cpus' => $cores > 0 ? max(1, $cores - 1) : null,
            'tasks_max' => (int) config('builds.caps.tasks_max', 4096),
            'io_weight' => (int) config('builds.caps.io_weight', 50),
            'cpu_weight' => (int) config('builds.caps.cpu_weight', 50),
        ];
    }

    /**
     * @param  array{memory_max_bytes: ?int, cpus: ?int, tasks_max: int, io_weight: int, cpu_weight: int}  $caps
     */
    public static function slice(array $caps): string
    {
        $lines = [
            '[Unit]',
            'Description=Falak builds (capped so a build cannot take the server down)',
            'Before=slices.target',
            '',
            '[Slice]',
            'MemoryAccounting=yes',
        ];

        if ($caps['memory_max_bytes'] !== null) {
            $lines[] = 'MemoryMax='.intdiv($caps['memory_max_bytes'], 1024 ** 2).'M';
        }

        if ($caps['cpus'] !== null) {
            $lines[] = 'CPUQuota='.($caps['cpus'] * 100).'%';
        }

        return implode("\n", [
            ...$lines,
            "TasksMax={$caps['tasks_max']}",
            "IOWeight={$caps['io_weight']}",
            "CPUWeight={$caps['cpu_weight']}",
            '',
        ]);
    }

    /**
     * Env for falak-builder's own caps (BuildKit memory/CPU, cgroup parent, disk guard).
     *
     * @param  array{memory_max_bytes: ?int, cpus: ?int, tasks_max: int, io_weight: int, cpu_weight: int}  $caps
     */
    public static function capsEnvironment(array $caps): string
    {
        $env = array_filter([
            'FALAK_BUILD_MEMORY_MB' => $caps['memory_max_bytes'] !== null ? intdiv($caps['memory_max_bytes'], 1024 ** 2) : null,
            'FALAK_BUILD_CPUS' => $caps['cpus'],
            'FALAK_BUILD_CGROUP_PARENT' => self::SLICE,
            'FALAK_BUILD_MIN_FREE_GB' => is_numeric(config('builds.caps.min_free_gb')) ? (int) config('builds.caps.min_free_gb') : null,
            'FALAK_BUILD_ABORT_FREE_GB' => is_numeric(config('builds.caps.abort_free_gb')) ? (int) config('builds.caps.abort_free_gb') : null,
        ], fn ($value) => $value !== null);

        return implode('', array_map(fn ($key, $value) => "{$key}={$value}\n", array_keys($env), $env));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function writeCaps(Builder $builder, array $facts, string $key): void
    {
        $caps = self::caps($facts, $builder->pool);
        $serverId = (string) $builder->server_id;

        $this->agents->dispatch($serverId, 'system.write_file', ['path' => self::SLICE_PATH, 'content' => self::slice($caps), 'mode' => '0644'], 60, "{$key}:slice");
        $this->agents->dispatch($serverId, 'system.write_file', ['path' => self::CAPS_ENV_PATH, 'content' => self::capsEnvironment($caps), 'mode' => '0644'], 60, "{$key}:caps");
        $builder->forceFill(['caps' => $caps])->save();
    }

    private function environment(Builder $builder, string $token): string
    {
        $url = rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/');

        return implode("\n", [
            "FALAK_URL={$url}",
            "FALAK_BUILDER_TOKEN={$token}",
            'FALAK_BUILDER_NAME='.preg_replace('/[^A-Za-z0-9._-]/', '-', $builder->name),
            'FALAK_BUILDER_DIR=/var/lib/falak-builder',
            '',
        ]);
    }

    public static function downloadUrl(): string
    {
        $configured = (string) config('builds.builder_binary.download_url');

        return $configured !== '' ? $configured : rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/').'/install/builder/linux-{arch}';
    }

    private function installScript(): string
    {
        $url = escapeshellarg(self::downloadUrl());

        return <<<SH
            set -euo pipefail
            arch=\$(uname -m); case "\$arch" in x86_64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; esac
            url={$url}; url="\${url//\{arch\}/\$arch}"
            curl -fsSL --retry 3 -o /usr/local/bin/falak-builder.tmp "\$url"
            chmod 0755 /usr/local/bin/falak-builder.tmp && mv /usr/local/bin/falak-builder.tmp /usr/local/bin/falak-builder
            mkdir -p /var/lib/falak-builder
            systemctl daemon-reload
            systemctl enable falak-builder >/dev/null
            systemctl restart falak-builder
            SH;
    }

    public static function unit(): string
    {
        return <<<'UNIT'
            [Unit]
            Description=Falak build worker
            After=network-online.target docker.service
            Wants=network-online.target

            [Service]
            Slice=falak-build.slice
            EnvironmentFile=/etc/falak/builder.env
            EnvironmentFile=-/etc/falak/builder-caps.env
            ExecStart=/usr/local/bin/falak-builder serve
            Restart=always
            RestartSec=5
            KillMode=mixed
            TimeoutStopSec=120
            OOMScoreAdjust=500

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
