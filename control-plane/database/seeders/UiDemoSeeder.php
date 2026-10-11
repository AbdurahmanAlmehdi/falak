<?php

namespace Database\Seeders;

use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Application\Actions\RegisterUser;
use Falak\Identity\Domain\Models\User;
use Falak\Processes\Domain\Enums\ApplyStatus;
use Falak\Processes\Domain\Enums\OctaneRouteStatus;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\OctaneRoute;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Domain\Models\Worker;
use Falak\Processes\Infrastructure\ProgramNames;
use Falak\Projects\Application\Actions\CreateEnvironment;
use Falak\Projects\Application\Actions\CreateProject;
use Falak\Projects\Application\Actions\GroupServices;
use Falak\Projects\Application\Actions\ToggleFavorite;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\Templates\Application\Actions\SaveCustomTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Local UI demo data (`php artisan db:seed --class=UiDemoSeeder`): an admin, an organization, a few servers and
 * sites so the redesigned pages have something to render. Same credentials as the sim: admin@falak.test / falak-demo-2026.
 * Written directly against module models like DatabaseSeeder — never run in production.
 */
class UiDemoSeeder extends Seeder
{
    public function run(RegisterUser $register, CreateOrganization $createOrganization): void
    {
        if (User::query()->where('email', 'admin@falak.test')->exists()) {
            return;
        }

        $admin = $register('Ada Admin', 'admin@falak.test', 'falak-demo-2026');
        $admin->markEmailAsVerified();
        $organization = $createOrganization($admin, 'Acme Studio');
        $admin->forceFill(['current_organization_id' => $organization->id])->save();

        $servers = collect([
            ['app-1', ServerType::App, ServerStatus::Active],
            ['app-2', ServerType::App, ServerStatus::Active],
            ['db-1', ServerType::Database, ServerStatus::Active],
            ['worker-1', ServerType::Worker, ServerStatus::Provisioning],
            ['edge-1', ServerType::LoadBalancer, ServerStatus::Error],
        ])->map(fn (array $spec) => Server::factory()->type($spec[1])->status($spec[2])->create([
            'organization_id' => $organization->id,
            'name' => $spec[0],
            ...($spec[0] === 'db-1' ? ['stack' => ['database' => 'postgresql'], 'private_ipv4' => '10.0.0.12'] : []),
            // Docker-capable, so compose templates can target it.
            ...($spec[0] === 'app-1' ? ['stack' => [...Stack::defaultsFor(ServerType::App)->toArray(), 'docker' => true]] : []),
        ]));

        $sites = [
            ['Storefront', 'storefront', SiteRuntime::FrankenPhp, Framework::Laravel, 'acme/storefront'],
            ['Marketing', 'marketing', SiteRuntime::Node, Framework::Next, 'acme/marketing-site'],
            ['Docs', 'docs', SiteRuntime::Static, Framework::Static, 'acme/docs'],
            ['Blog', 'blog', SiteRuntime::PhpFpm, Framework::WordPress, 'acme/blog'],
        ];

        $created = [];

        foreach ($sites as $index => [$name, $slug, $runtime, $framework, $repository]) {
            $site = $created[$slug] = Site::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => $slug,
                'runtime' => $runtime,
                'build_mode' => BuildMode::Native,
                'framework' => $framework,
                'php_version' => in_array($runtime, [SiteRuntime::FrankenPhp, SiteRuntime::PhpFpm], true) ? '8.4' : null,
                'web_directory' => 'public',
                'unix_user' => $slug,
                'isolated' => true,
                'repository' => $repository,
                'branch' => $repository ? 'main' : null,
                'deploy_script' => '$FALAK_FETCH',
                'laravel' => new LaravelSettings,
            ]);

            foreach ($index === 0 ? [$servers[0], $servers[1]] : [$servers[$index % 2]] as $position => $server) {
                SiteTarget::query()->create([
                    'site_id' => $site->id,
                    'server_id' => $server->id,
                    'role' => $position === 0 ? TargetRole::Leader : TargetRole::Member,
                    // Blog's server is still being prepared (its deployment waits for it).
                    'status' => $slug === 'blog' ? TargetStatus::Provisioning : TargetStatus::Ready,
                ]);
            }
        }

        $created['stack'] = $this->composeSite($organization->id, $admin->id, $servers[0]);

        $this->database($organization->id, $servers[2]);
        $this->cacheInstance($organization->id, $servers[0]);
        $this->variables($created['storefront'], ['APP_ENV' => 'production', 'APP_URL' => 'https://shop.acme.dev', 'DATABASE_URL' => '${{ storefront_db.DATABASE_URL }}', 'DB_HOST' => '${{ storefront_db.DB_HOST }}']);
        // Marketing calls the storefront API and posts leads into the automations stack (canvas reference edges).
        $this->variables($created['marketing'], ['NEXT_PUBLIC_SHOP_URL' => '${{ storefront.APP_URL }}', 'N8N_WEBHOOK' => '${{ automations.N8N_ENCRYPTION_KEY }}']);
        $this->deployments($organization->id, $created, [$servers[0], $servers[1]]);
        $this->waitingDeployment($organization->id, $created['blog'], $servers[1]);
        $this->processes($organization->id, $created['storefront'], $created['marketing'], [$servers[0], $servers[1]]);

        // Place the sites into the organization's Default project, and add a second project with staging so the
        // project/environment switchers have something to switch between.
        Artisan::call('projects:backfill', ['--organization' => $organization->id]);
        $platform = app(CreateProject::class)($organization->id, $admin->id, ['name' => 'Platform']);
        app(CreateEnvironment::class)($platform, 'staging', $admin->id);
        $this->canvas($organization->id, $admin->id);

        $this->callWith(InfrastructureDemoSeeder::class, ['organizationId' => $organization->id, 'userId' => $admin->id]);

        // Organization settings: git connections, clouds, buckets, builders, alerting, recipes.
        $this->callWith(SettingsDemoSeeder::class, ['organizationId' => $organization->id, 'userId' => $admin->id]);

        $this->call(ObservabilityDemoSeeder::class, false, ['organizationId' => $organization->id, 'userId' => $admin->id]);

        $this->configAsCode($organization->id, $created['storefront']);

        // Settings → Templates: an organization template next to the catalog.
        app(SaveCustomTemplate::class)($organization->id, $admin->id, self::CUSTOM_TEMPLATE, self::CUSTOM_COMPOSE);
    }

    /**
     * Canvas layout (docs/UI_DESIGN.md §4.3): the Automations compose stack as a group at the top left, Storefront and
     * its database in a "Commerce" group, Marketing beside them; Docs and Blog move to a starred "Content" project so
     * the Projects dashboard shows several projects with what's inside.
     */
    private function canvas(string $organizationId, string $userId): void
    {
        $default = Project::query()->where('organization_id', $organizationId)->where('is_default', true)->firstOrFail();
        $production = $default->production() ?? throw new \RuntimeException('Default project without production');
        $services = Service::query()->where('environment_id', $production->id)->get()->keyBy('name');
        $place = fn (string $name, int $x, int $y) => $services->get($name)?->forceFill(['x' => $x, 'y' => $y])->save();

        $place('Automations', 0, 60);
        $place('Storefront', 720, 20);
        $place('storefront_db', 720, 240);
        $place('sessions', 1140, 240);
        $place('Marketing', 1140, 20);
        app(GroupServices::class)($production, 'Commerce', [$services['Storefront']->id, $services['storefront_db']->id]);

        $content = app(CreateProject::class)($organizationId, $userId, ['name' => 'Content', 'description' => 'Docs and the company blog.']);
        $contentProduction = $content->production() ?? throw new \RuntimeException('Content project without production');
        foreach ([['Docs', 0, 0], ['Blog', 320, 0]] as [$name, $x, $y]) {
            $services->get($name)?->forceFill(['project_id' => $content->id, 'environment_id' => $contentProduction->id, 'x' => $x, 'y' => $y])->save();
        }

        app(ToggleFavorite::class)($content, $userId, true);
    }

    /**
     * Config as code: the Default project follows acme/storefront's falak.yaml, which manages Storefront's deploy
     * strategy, health check path and APP_ENV (read-only in the dashboard with the "Managed by falak.yaml" badge).
     */
    private function configAsCode(string $organizationId, Site $storefront): void
    {
        $default = Project::query()->where('organization_id', $organizationId)->where('is_default', true)->firstOrFail();
        $production = $default->production() ?? throw new \RuntimeException('Default project without production');
        $connection = Connection::query()->where('organization_id', $organizationId)->value('id') ?? strtolower((string) Str::ulid());
        $commit = '4be09d2'.str_repeat('a', 33);

        ConfigSource::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $default->id,
            'source_connection_id' => $connection,
            'repository' => 'acme/storefront',
            'path' => 'falak.yaml',
            'enabled' => true,
            'experimental' => true,
        ]);

        $file = "version: 1\nunits:\n  storefront:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    env: { APP_ENV: production }\n    deploy:\n      strategy: zero-downtime\n      health: { path: /up }\nenvironments:\n  production: { branch: main }\n";
        $run = ConfigRun::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $default->id,
            'environment_id' => $production->id,
            'environment' => $production->slug,
            'commit' => $commit,
            'branch' => 'main',
            'trigger' => 'push',
            'status' => 'applied',
            'file' => $file,
            'format' => 'yaml',
            'plan' => ['changes' => [
                ['op' => 'update', 'path' => '/units/storefront/deploy/health/path', 'before' => '/', 'after' => '/up', 'section' => 'units.*(app).deploy', 'unit' => 'storefront', 'risky' => false, 'reason' => null, 'rule' => null],
            ], 'summary' => ['create' => 0, 'update' => 1, 'delete' => 0, 'detach' => 0, 'noop' => 3], 'risky' => false, 'deploy' => ['storefront']],
            'deploy' => [['unit' => 'storefront', 'site_id' => $storefront->id, 'deployment_id' => null, 'status' => 'released', 'error' => null]],
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(2),
        ]);

        foreach (['/deploy/strategy', '/deploy/health/path', '/env/APP_ENV'] as $path) {
            ConfigOwnership::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $default->id,
                'environment_id' => $production->id,
                'resource_type' => 'site',
                'resource_id' => $storefront->id,
                'unit' => 'storefront',
                'path' => $path,
                'created_by_file' => false,
                'run_id' => $run->id,
            ]);
        }
    }

    private const CUSTOM_TEMPLATE = <<<'YAML'
name: Acme Status API
slug: acme-status-api
version: 1.2.0
description: Internal status API with a Redis cache, used by every Acme storefront.
category: dev-tools
icon: docker
stateful: false
min_memory_mb: 256
tags: [internal, api]
public:
  - service: api
    port: 8080
inputs:
  - key: API_TOKEN
    type: secret
    generate: hex(32)
    label: API token
  - key: LOG_LEVEL
    type: select
    options: [debug, info, warn, error]
    default: info
YAML;

    private const CUSTOM_COMPOSE = <<<'YAML'
services:
  api:
    image: ghcr.io/acme/status-api:1.2.0
    restart: unless-stopped
    expose: ["8080"]
    environment:
      API_TOKEN: ${API_TOKEN}
      LOG_LEVEL: ${LOG_LEVEL}
      PUBLIC_URL: ${{ falak.url(api) }}
      REDIS_URL: redis://cache:6379
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/health"]
  cache:
    image: redis:8.2.1-alpine
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
YAML;

    /**
     * A Docker Compose site (docs/COMPOSE_TEMPLATES.md §1) created from the n8n template: two inline compose versions,
     * public services, and a reported state with one unhealthy service for the Services tab.
     */
    private function composeSite(string $organizationId, string $userId, Server $server): Site
    {
        $site = Site::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Automations',
            'slug' => 'automations',
            'runtime' => SiteRuntime::Compose,
            'build_mode' => BuildMode::Docker,
            'framework' => Framework::Docker,
            'web_directory' => '',
            'unix_user' => 'falak',
            'deploy_script' => '',
            'laravel' => new LaravelSettings,
            'compose_source' => ComposeSource::Inline,
            'public_services' => [
                ['service' => 'n8n', 'port' => 5678, 'domain' => 'automations.acme.dev', 'host_port' => 3200],
                ['service' => 'grafana', 'port' => 3000, 'domain' => null, 'host_port' => 3201],
            ],
            'app_port' => 3200,
            'test_domain_enabled' => true,
            'template' => ['slug' => 'n8n', 'version' => '1.0.0', 'source' => 'catalog'],
        ]);

        SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

        $v1 = <<<'YAML'
            services:
              n8n:
                image: n8nio/n8n:1.64.0
                environment:
                  N8N_ENCRYPTION_KEY: ${N8N_ENCRYPTION_KEY}
                  DB_TYPE: postgresdb
                  DB_POSTGRESDB_HOST: postgres
                volumes:
                  - n8n-data:/home/node/.n8n
                depends_on: [postgres]
              postgres:
                image: postgres:17.2-alpine
                environment:
                  POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
                volumes:
                  - pg-data:/var/lib/postgresql/data
            volumes:
              n8n-data:
              pg-data:
            YAML;
        $v2 = <<<'YAML'
            # n8n with Postgres, Redis queue mode and Grafana
            services:
              n8n:
                image: n8nio/n8n:1.64.0
                environment:
                  N8N_ENCRYPTION_KEY: ${N8N_ENCRYPTION_KEY}
                  DB_TYPE: postgresdb
                  DB_POSTGRESDB_HOST: postgres
                  QUEUE_BULL_REDIS_HOST: redis
                  WEBHOOK_URL: https://automations.acme.dev/
                expose: ["5678"]
                labels:
                  falak.deploy.leader_command: "n8n db:migrate"
                volumes:
                  - n8n-data:/home/node/.n8n
                depends_on: [postgres, redis]
                healthcheck:
                  test: ["CMD", "wget", "-qO-", "http://localhost:5678/healthz"]
                  interval: 10s
              postgres:
                image: postgres:17.2-alpine
                environment:
                  POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
                volumes:
                  - pg-data:/var/lib/postgresql/data
              redis:
                image: redis:7.4.1-alpine
                command: ["redis-server", "--appendonly", "yes"]
                volumes:
                  - redis-data:/data
              grafana:
                image: grafana/grafana-oss:11.3.0
                ports: ["3000:3000"]
            volumes:
              n8n-data:
              pg-data:
              redis-data:
            YAML;

        foreach ([1 => $v1, 2 => $v2] as $version => $content) {
            ComposeVersion::query()->create(['site_id' => $site->id, 'version' => $version, 'content' => $content."\n", 'created_by' => $userId, 'created_at' => now()->subDays(3 - $version)]);
        }

        $this->variables($site, ['N8N_ENCRYPTION_KEY' => Str::random(32), 'POSTGRES_PASSWORD' => Str::random(24)]);

        $digest = fn (string $seed) => 'sha256:'.hash('sha256', $seed);
        app(ComposeSites::class)->recordStatus($site->id, $server->id, [
            ['service' => 'grafana', 'container_id' => 'c-grafana', 'container_name' => 'automations-grafana-1', 'state' => 'running', 'image' => 'grafana/grafana-oss:11.3.0', 'image_digest' => $digest('grafana'), 'restarts' => 0, 'cpu_percent' => 0.8, 'memory_bytes' => 96 * 1024 ** 2, 'memory_limit_bytes' => 2 * 1024 ** 3,
                'ports' => [['host_ip' => '127.0.0.1', 'host_port' => 3201, 'container_port' => 3000, 'protocol' => 'tcp']]],
            ['service' => 'n8n', 'container_id' => 'c-n8n', 'container_name' => 'automations-n8n-1', 'state' => 'running', 'health' => 'healthy', 'image' => 'n8nio/n8n:1.64.0@'.$digest('n8n'), 'image_digest' => $digest('n8n'), 'restarts' => 0, 'cpu_percent' => 3.4, 'memory_bytes' => 312 * 1024 ** 2, 'memory_limit_bytes' => 2 * 1024 ** 3,
                'ports' => [['host_ip' => '127.0.0.1', 'host_port' => 3200, 'container_port' => 5678, 'protocol' => 'tcp']]],
            ['service' => 'postgres', 'container_id' => 'c-pg', 'container_name' => 'automations-postgres-1', 'state' => 'running', 'image' => 'postgres:17.2-alpine', 'image_digest' => $digest('pg'), 'restarts' => 0, 'cpu_percent' => 1.1, 'memory_bytes' => 58 * 1024 ** 2, 'memory_limit_bytes' => 2 * 1024 ** 3,
                'ports' => [['container_port' => 5432, 'protocol' => 'tcp']]],
            ['service' => 'redis', 'container_id' => 'c-redis', 'container_name' => 'automations-redis-1', 'state' => 'restarting', 'image' => 'redis:7.4.1-alpine', 'image_digest' => $digest('redis'), 'restarts' => 7,
                'ports' => [['container_port' => 6379, 'protocol' => 'tcp']]],
        ]);

        // Its public service domains as Edge rows (a real site gets them through SiteCreated).
        app(ComposeServiceDomains::class)->import($site->id);

        return $site;
    }

    /**
     * A PostgreSQL engine on db-1 with the Storefront database, a user, a nightly schedule and backup history.
     */
    private function database(string $organizationId, Server $server): Database
    {
        $engine = $this->instance($organizationId, $server, Engine::PostgreSql, 'storefront-db', 1024);
        $database = $engine->databases()->create(['organization_id' => $organizationId, 'server_id' => $server->id, 'name' => 'storefront_db', 'status' => ResourceStatus::Active]);
        $user = $engine->users()->create([
            'organization_id' => $organizationId, 'server_id' => $server->id, 'username' => 'storefront', 'password' => 'demo-password-not-real', 'host' => '%', 'status' => ResourceStatus::Active,
        ]);
        Grant::query()->create(['user_id' => $user->id, 'database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

        $storage = StorageProvider::query()->create([
            'organization_id' => $organizationId, 'name' => 'Backups', 'driver' => StorageDriver::R2, 'endpoint' => 'https://r2.storage.invalid',
            'region' => 'auto', 'bucket' => 'acme-backups', 'path_style' => false, 'access_key_id' => 'demo-access-key-id', 'secret_access_key' => 'demo-secret', 'verified_at' => now(),
        ]);
        $schedule = BackupSchedule::query()->create([
            'organization_id' => $organizationId, 'database_instance_id' => $engine->id, 'storage_provider_id' => $storage->id, 'name' => 'Nightly',
            'cron' => '0 3 * * *', 'retention_count' => 14, 'compression' => 'gzip', 'enabled' => true, 'last_run_at' => now()->subDay()->setTime(3, 0), 'next_run_at' => now()->addDay()->setTime(3, 0),
        ]);
        $schedule->databases()->attach($database->id);

        foreach ([[1, 'succeeded', 48_211_004], [2, 'succeeded', 47_902_311], [3, 'failed', null]] as [$daysAgo, $status, $size]) {
            Backup::query()->create([
                'organization_id' => $organizationId, 'schedule_id' => $schedule->id, 'database_id' => $database->id, 'database_instance_id' => $engine->id, 'instance_name' => $engine->name, 'engine_version' => $engine->version,
                'server_id' => $server->id, 'server_name' => $server->name, 'database_name' => $database->name, 'engine' => 'postgresql', 'storage_provider_id' => $storage->id,
                'object_key' => "storefront_db/{$daysAgo}.sql.gz", 'compression' => 'gzip', 'trigger' => 'scheduled', 'status' => $status, 'size_bytes' => $size,
                'duration_ms' => $size ? 8200 + $daysAgo * 100 : null, 'error' => $size ? null : 'pg_dump: connection timed out', 'started_at' => now()->subDays($daysAgo),
                'finished_at' => now()->subDays($daysAgo)->addSeconds(9), 'created_at' => now()->subDays($daysAgo),
            ]);
        }

        return $database;
    }

    /**
     * A Redis container on app-1 (its keyspace and `default` user), next to Marketing on the canvas.
     */
    private function cacheInstance(string $organizationId, Server $server): Database
    {
        $engine = $this->instance($organizationId, $server, Engine::Redis, 'sessions', 320, ['eviction' => 'noeviction', 'persistence' => 'rdb']);
        $database = $engine->databases()->create([
            'organization_id' => $organizationId, 'server_id' => $server->id, 'name' => 'sessions', 'status' => ResourceStatus::Active,
        ]);
        $user = $engine->users()->create([
            'organization_id' => $organizationId, 'server_id' => $server->id, 'username' => 'default', 'password' => $engine->root_password, 'host' => '%', 'status' => ResourceStatus::Active,
        ]);
        Grant::query()->create(['user_id' => $user->id, 'database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

        return $database;
    }

    /**
     * A running database container (demo rows only: no agent creates it).
     *
     * @param  array<string, string|int>  $settings
     */
    private function instance(string $organizationId, Server $server, Engine $engine, string $name, int $memoryMb, array $settings = []): DatabaseInstance
    {
        $instance = new DatabaseInstance;
        $instance->id = strtolower((string) Str::ulid());
        $instance->forceFill([
            'organization_id' => $organizationId, 'server_id' => $server->id, 'server_name' => $server->name, 'name' => $name,
            'engine' => $engine, 'version' => $engine->defaultVersion(), 'image' => $engine->image($engine->defaultVersion()),
            'image_digest' => 'sha256:'.hash('sha256', $name), 'hostname' => "falak-db-{$instance->id}", 'port' => $engine->defaultPort(),
            'host_port' => 20000 + DatabaseInstance::query()->where('server_id', $server->id)->count(), 'memory_bytes' => $memoryMb * 1024 ** 2,
            'settings' => $settings ?: null, 'root_password' => Passwords::generate(), 'status' => InstanceStatus::Active, 'health' => 'healthy', 'health_at' => now(),
        ])->save();

        return $instance;
    }

    /**
     * Storefront runs Octane (FrankenPHP; proxied on app-1, still starting on app-2), Horizon, a queue worker, a Reverb
     * daemon, the scheduler and two cron jobs on app-1/app-2 (one worker instance crash-looping on app-2); Marketing's Next.js web process runs on app-2. Job names match
     * the heartbeats ObservabilityDemoSeeder sends.
     *
     * @param  list<Server>  $servers
     */
    private function processes(string $organizationId, Site $storefront, Site $marketing, array $servers): void
    {
        $storefront->forceFill(['laravel' => LaravelSettings::fromArray(['scheduler' => true, 'horizon' => true, 'octane' => true, 'maintenance' => false, 'octane_server' => 'frankenphp', 'octane_port' => 8412])])->save();

        foreach ($servers as $index => $server) {
            OctaneRoute::query()->create([
                'organization_id' => $organizationId, 'site_id' => $storefront->id, 'server_id' => $server->id, 'octane_server' => OctaneServer::FrankenPhp, 'port' => 8412,
                'status' => $index === 0 ? OctaneRouteStatus::Listening : OctaneRouteStatus::Starting,
                'checked_at' => now()->subMinutes(2), 'listening_at' => $index === 0 ? now()->subHours(3) : null,
            ]);
        }

        $worker = Worker::query()->create([
            'organization_id' => $organizationId, 'site_id' => $storefront->id, 'connection' => 'redis', 'queue' => 'emails,default', 'processes' => 2,
            'timeout' => 90, 'sleep' => 3, 'tries' => 3, 'max_time' => 3600, 'memory' => 256, 'env' => [],
        ]);
        $daemon = Daemon::query()->create([
            'organization_id' => $organizationId, 'site_id' => $storefront->id, 'name' => 'Reverb', 'command' => 'php8.4 artisan reverb:start --port=8080',
            'instances' => 1, 'restart' => 'always', 'stop_signal' => 'TERM', 'stop_timeout' => 30, 'env' => [],
        ]);
        $schedules = collect([
            ['Sync inventory', 'php8.4 artisan inventory:sync', '*/15 * * * *'],
            ['Abandoned cart emails', 'php8.4 artisan carts:remind', '*/30 * * * *'],
        ])->map(fn (array $spec) => Schedule::query()->create([
            'organization_id' => $organizationId, 'site_id' => $storefront->id, 'name' => $spec[0], 'command' => $spec[1], 'expression' => $spec[2],
            'timezone' => 'UTC', 'overlap' => 'skip', 'timeout' => 600, 'heartbeat' => true, 'enabled' => true, 'all_servers' => false,
        ]));

        $programs = [
            ProgramNames::octane('storefront') => ['site_id' => $storefront->id, 'kind' => 'octane', 'label' => 'Octane', 'numprocs' => 1],
            ProgramNames::horizon('storefront') => ['site_id' => $storefront->id, 'kind' => 'horizon', 'label' => 'Horizon', 'numprocs' => 1],
            ProgramNames::worker('storefront', $worker->id) => ['site_id' => $storefront->id, 'kind' => 'worker', 'label' => 'redis: emails,default', 'numprocs' => 2],
            ProgramNames::daemon('storefront', $daemon->id) => ['site_id' => $storefront->id, 'kind' => 'daemon', 'label' => 'Reverb', 'numprocs' => 1],
        ];
        $jobs = [ProgramNames::scheduler('storefront') => ['site_id' => $storefront->id, 'kind' => 'scheduler', 'label' => 'Scheduler', 'schedule' => '* * * * *', 'timezone' => 'UTC', 'heartbeat' => true]];

        foreach ($schedules as $schedule) {
            $jobs[ProgramNames::cron('storefront', $schedule->id)] = ['site_id' => $storefront->id, 'kind' => 'cron', 'label' => $schedule->name, 'schedule' => $schedule->expression, 'timezone' => 'UTC', 'heartbeat' => true];
        }

        foreach ($servers as $index => $server) {
            $mine = $programs;
            if ($index === 1) {
                $mine[ProgramNames::app('marketing')] = ['site_id' => $marketing->id, 'kind' => 'app', 'label' => 'Web process', 'numprocs' => 1];
            }
            $status = [];
            foreach ($mine as $name => $meta) {
                for ($instance = 0; $instance < $meta['numprocs']; $instance++) {
                    $crashing = $index === 1 && $meta['kind'] === 'worker' && $instance === 1;
                    $status[] = [
                        'name' => $name, 'instance' => $instance, 'state' => $crashing ? 'backoff' : 'running', 'pid' => $crashing ? null : 4100 + $index * 100 + $instance,
                        'restarts' => $crashing ? 7 : 0, 'started_at' => now()->subHours(3)->toIso8601String(), 'last_exit_code' => $crashing ? 1 : null,
                    ];
                }
            }

            ServerState::query()->create([
                'server_id' => $server->id, 'organization_id' => $organizationId,
                'proc_status' => ApplyStatus::Applied, 'programs' => $mine, 'applied_programs' => array_keys($mine), 'proc_applied_at' => now()->subHours(3),
                'cron_status' => ApplyStatus::Applied, 'jobs' => $index === 0 ? $jobs : [], 'applied_jobs' => $index === 0 ? array_keys($jobs) : [], 'cron_applied_at' => now()->subHours(3),
                'process_status' => $status, 'status_at' => now()->subMinutes(2),
                'crash_looping' => $index === 1 ? [ProgramNames::worker('storefront', $worker->id)] : [],
            ]);
        }
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function variables(Site $site, array $variables): void
    {
        EnvironmentVersion::query()->create(['site_id' => $site->id, 'version' => 1, 'variables' => $variables, 'exposed' => [], 'changed_keys' => array_keys($variables), 'created_at' => now()]);
    }

    /**
     * Deployment history with releases, per-server phase steps and output: Storefront has a live deployment in
     * progress plus one queued, Marketing is healthy, Docs failed, Blog was never deployed (its first deployment waits
     * for its server, see waitingDeployment()).
     *
     * @param  array<string, Site>  $sites
     * @param  list<Server>  $servers
     */
    private function deployments(string $organizationId, array $sites, array $servers): void
    {
        $messages = ['Fix checkout rounding', 'Add wishlist sharing', 'Upgrade to Laravel 12', 'Speed up product search'];

        foreach ([[$sites['storefront'], ['failed', 'succeeded', 'succeeded', 'deploying', 'queued'], $servers], [$sites['marketing'], ['succeeded'], [$servers[1]]], [$sites['docs'], ['failed'], [$servers[1]]], [$sites['stack'], ['succeeded', 'succeeded'], [$servers[0]]]] as [$site, $statuses, $targets]) {
            $active = null;

            foreach ($statuses as $index => $status) {
                $number = $index + 1;
                $started = now()->subHours(count($statuses) - $index)->subMinutes(7);
                $finished = in_array($status, ['succeeded', 'failed'], true) ? $started->copy()->addSeconds(74 + $index * 9) : null;
                $commit = substr(hash('sha1', $site->slug.$number), 0, 40);

                $deployment = Deployment::query()->create([
                    'organization_id' => $organizationId, 'site_id' => $site->id, 'site_slug' => $site->slug, 'number' => $number,
                    'trigger' => $index === 0 ? 'manual' : 'push', 'status' => $status, 'phase' => $status === 'deploying' ? 'migrate' : null, 'strategy' => 'zero-downtime',
                    'branch' => 'main', 'commit' => $commit, 'commit_message' => $messages[$index % count($messages)], 'commit_author' => $index % 2 ? 'Grace Hopper' : 'Ada Admin',
                    'error' => $status === 'failed' ? 'Health check failed: GET /up returned 500 on app-1.' : null,
                    'started_at' => $status === 'queued' ? null : $started, 'finished_at' => $finished, 'created_at' => $started, 'updated_at' => $finished ?? $started,
                ]);

                if ($status === 'queued') {
                    continue;
                }

                if ($status === 'succeeded') {
                    $release = Release::query()->forceCreate([
                        'id' => strtolower((string) Str::ulid()),
                        'organization_id' => $organizationId, 'site_id' => $site->id, 'deployment_id' => $deployment->id, 'commit' => $commit, 'branch' => 'main',
                        'commit_message' => $deployment->commit_message, 'commit_author' => $deployment->commit_author, 'status' => 'inactive', 'activated_at' => $finished,
                    ]);
                    $deployment->forceFill(['release_id' => $release->id])->save();
                    $active = $release;
                }

                $this->steps($deployment, $targets, $status, $started);
            }

            $active?->forceFill(['status' => 'active'])->save();
        }
    }

    /**
     * Blog was triggered right after it was created: its deployment waits for app-2 to finish preparing.
     */
    private function waitingDeployment(string $organizationId, Site $site, Server $server): void
    {
        $at = now()->subMinutes(2)->subSeconds(14);
        $reason = "Waiting for 1 server to finish preparing: {$server->name}";
        $deployment = Deployment::query()->create([
            'organization_id' => $organizationId, 'site_id' => $site->id, 'site_slug' => $site->slug, 'number' => 1, 'trigger' => 'push', 'status' => 'waiting',
            'branch' => 'main', 'commit' => substr(hash('sha1', 'blog1'), 0, 40), 'commit_message' => 'Initial theme', 'commit_author' => 'Grace Hopper',
            'waiting_since' => $at, 'waiting_reason' => $reason, 'created_at' => $at, 'updated_at' => $at,
        ]);

        foreach (['Queued by push at '.substr((string) $deployment->commit, 0, 7).'.', "{$reason}. The deployment starts automatically once they are ready."] as $line) {
            OutputLine::query()->create(['deployment_id' => $deployment->id, 'stream' => 'stdout', 'data' => $line."\n", 'at' => $at]);
        }
    }

    /**
     * @param  list<Server>  $servers
     */
    private function steps(Deployment $deployment, array $servers, string $status, Carbon $at): void
    {
        $phases = ['fetch', 'prepare', 'migrate', 'activate', 'restart', 'healthcheck'];
        $position = 0;
        $clock = $at->copy();

        $build = DeploymentStep::query()->create([
            'deployment_id' => $deployment->id, 'key' => 'build', 'kind' => 'build', 'phase' => 'build', 'position' => $position++, 'depends_on' => [],
            'status' => 'succeeded', 'started_at' => $clock->copy(), 'finished_at' => $clock->addSeconds(38)->copy(),
        ]);
        foreach (["\e[1;34m==>\e[0m Installing dependencies", 'composer install --no-dev --optimize-autoloader', 'Generating optimized autoload files', "\e[1;34m==>\e[0m Building assets", 'vite v6.0.7 building for production...', "\e[32m✓\e[0m 1482 modules transformed.", "\e[32m✓ built in 6.21s\e[0m", 'Uploading artifact (42.1 MB)'] as $i => $line) {
            OutputLine::query()->create(['deployment_id' => $deployment->id, 'step_id' => $build->id, 'phase' => 'build', 'stream' => 'stdout', 'data' => $line."\n", 'at' => $at->copy()->addSeconds($i * 4)]);
        }

        foreach ($servers as $serverIndex => $server) {
            $target = DeploymentTarget::query()->create([
                'deployment_id' => $deployment->id, 'server_id' => $server->id, 'server_name' => $server->name, 'role' => $serverIndex === 0 ? 'leader' : 'member',
                'position' => $serverIndex, 'status' => match ($status) {
                    'deploying' => 'deploying', 'failed' => 'failed', default => 'succeeded'
                }, 'activated' => $status === 'succeeded',
            ]);
            $time = $clock->copy();

            foreach ($phases as $phaseIndex => $phase) {
                if ($phase === 'migrate' && $serverIndex > 0) {
                    continue;
                }
                $stepStatus = match (true) {
                    $status === 'deploying' => $phaseIndex < 2 ? 'succeeded' : ($phaseIndex === 2 || ($phaseIndex === 3 && $serverIndex > 0) ? 'running' : 'pending'),
                    $status === 'failed' => $phase === 'healthcheck' && $serverIndex === 0 ? 'failed' : ($phase === 'healthcheck' ? 'skipped' : 'succeeded'),
                    default => 'succeeded',
                };
                $started = in_array($stepStatus, ['succeeded', 'failed', 'running'], true) ? $time->copy() : null;
                $done = in_array($stepStatus, ['succeeded', 'failed'], true) ? $time->addSeconds(3 + $phaseIndex * 2)->copy() : null;
                $step = DeploymentStep::query()->create([
                    'deployment_id' => $deployment->id, 'target_id' => $target->id, 'server_id' => $server->id, 'key' => "{$phase}-{$server->name}", 'kind' => $phase === 'migrate' ? 'hook' : $phase,
                    'phase' => $phase, 'position' => $position++, 'depends_on' => [], 'meta' => $phase === 'migrate' ? ['name' => 'migrate'] : null, 'status' => $stepStatus,
                    'error' => $stepStatus === 'failed' ? 'GET /up returned 500' : null, 'started_at' => $started, 'finished_at' => $done,
                ]);

                if ($started !== null) {
                    $line = match ($phase) {
                        'fetch' => 'Fetched artifact into releases/'.strtoupper(substr($deployment->id, 0, 10)),
                        'prepare' => 'Linked shared paths: storage, .env',
                        'migrate' => "\e[33mINFO\e[0m  Running migrations.  2026_09_20_120000_add_wishlists_table ......... \e[32m12ms DONE\e[0m",
                        'activate' => 'Switched current -> new release',
                        'restart' => 'Reloaded php-fpm and 2 queue workers',
                        default => $stepStatus === 'failed' ? "\e[31mGET /up -> 500 Internal Server Error\e[0m" : 'GET /up -> 200 OK (38ms)',
                    };
                    OutputLine::query()->create([
                        'deployment_id' => $deployment->id, 'step_id' => $step->id, 'server_id' => $server->id, 'server_name' => $server->name, 'phase' => $phase,
                        'stream' => $stepStatus === 'failed' ? 'stderr' : 'stdout', 'data' => $line."\n", 'at' => $started,
                    ]);
                }
            }
        }
    }
}
