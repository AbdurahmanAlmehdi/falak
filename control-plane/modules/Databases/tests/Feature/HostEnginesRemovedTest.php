<?php

use Falak\Databases\Application\Listeners\ConvergeInstanceNetwork;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Identity\Contracts\Role;
use Falak\Network\Contracts\Data\PrivateNetworkMembership;
use Falak\Network\Contracts\PrivateNetwork;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [, $this->organization] = actingAsMember(Role::Admin);
});

it('has no host-engine commands, features or config left', function () {
    $gateway = app(AgentGateway::class);

    foreach (['db.redis.apply', 'db.redis.remove'] as $type) {
        expect($gateway->supports($type))->toBeFalse();
    }

    foreach (['db.instance.create', 'db.instance.update', 'db.instance.restart', 'db.instance.delete', 'db.instance.password', 'db.instance.secrets', 'db.instance.upgrade'] as $type) {
        expect($gateway->supports($type))->toBeTrue();
    }

    expect(config('databases'))->not->toHaveKeys(['distro_versions', 'container_networks', 'docker_bridge_host', 'key_value'])
        ->and(config('servers'))->not->toHaveKeys(['databases', 'caches', 'caches_by_os'])
        ->and(class_exists('Falak\Databases\Application\EngineInventory'))->toBeFalse()
        ->and(class_exists('Falak\Servers\Application\Actions\InstallDatabaseEngine'))->toBeFalse()
        ->and(class_exists('Falak\Databases\Application\Actions\EnableContainerAccess'))->toBeFalse();
});

it('maps compose images and tags to engines and supported majors', function () {
    expect(Engine::fromImage('docker.io/library/postgres:16-alpine'))->toBe(Engine::PostgreSql)
        ->and(Engine::fromImage('valkey/valkey:8'))->toBe(Engine::Valkey)
        ->and(Engine::fromImage('bitnami/mysql:8.0'))->toBe(Engine::MySql)
        ->and(Engine::fromImage('nginx'))->toBeNull()
        ->and(Engine::PostgreSql->versionFromTag('16-alpine'))->toBe('16')
        ->and(Engine::PostgreSql->versionFromTag('16.4'))->toBe('16')
        ->and(Engine::PostgreSql->versionFromTag('12'))->toBe('17')
        ->and(Engine::MySql->versionFromTag('8.0.39'))->toBe('8.0')
        ->and(Engine::Redis->versionFromTag(null))->toBe('8')
        ->and(Engine::PostgreSql->image('17'))->toBe('ghcr.io/falak-sh/falak-postgres:17');
});

it('publishes the port on the private address other servers of the environment reach it on, and only then', function () {
    $dbServer = databases_server($this->organization, attributes: ['name' => 'db-1', 'private_ipv4' => null]);
    $web = databases_server($this->organization, attributes: ['name' => 'web-1', 'private_ipv4' => null]);
    [$database, , $instance] = databases_service($this->organization, 'postgresql', 'shop', $dbServer);
    $environment = strtolower((string) Str::ulid());

    app()->instance(PrivateNetwork::class, new class([$dbServer->id => [new PrivateNetworkMembership('n', 'mesh', 'wg-falak0', '10.90.0.1', '10.90.0.0/24', true)], $web->id => [new PrivateNetworkMembership('n', 'mesh', 'wg-falak0', '10.90.0.2', '10.90.0.0/24', true)]]) implements PrivateNetwork
    {
        public function __construct(private array $memberships) {}

        public function addressOf(string $serverId, ?string $networkId = null): ?string
        {
            return ($this->memberships[$serverId][0] ?? null)?->address;
        }

        public function networksOf(string $serverId): array
        {
            return $this->memberships[$serverId] ?? [];
        }
    });

    $site = Site::query()->forceCreate([
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $this->organization->id, 'name' => 'Shop', 'slug' => 'shop',
        'runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'unix_user' => 'falak', 'deploy_script' => '',
        'laravel' => [], 'app_port' => 3100, 'docker_image' => 'ghcr.io/acme/shop:1', 'test_domain_enabled' => false,
    ]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $web->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

    $projects = Mockery::mock(ProjectDirectory::class);
    $projects->shouldReceive('projectOf')->andReturnUsing(fn ($kind, $id) => $id === $database->id ? new ServiceData('s1', $this->organization->id, 'p', $environment, ServiceKind::Database, $database->id, 'shop', 0, 0) : null);
    $projects->shouldReceive('servicesIn')->with($environment)->andReturn([new ServiceData('s2', $this->organization->id, 'p', $environment, ServiceKind::Site, $site->id, 'shop-web', 0, 0)]);
    $projects->shouldReceive('environment')->andReturn(null);
    app()->instance(ProjectDirectory::class, $projects);

    // New addresses recreate the container: never silently, they wait to be applied.
    app(ConvergeInstanceNetwork::class)->converge($this->organization->id);
    expect($instance->refresh()->pending_published_addresses)->toBe(['10.90.0.1'])
        ->and($instance->published_addresses)->toBeNull()
        ->and($this->agents->dispatched('db.instance.update'))->toBe([]);

    $this->post("/databases/instances/{$instance->id}/network")->assertSessionHasNoErrors();
    $update = $this->agents->last('db.instance.update');
    // Only web-1 (its WireGuard address) gets through the agent's firewall.
    expect($update['payload']['instance']['publish'])->toBe(['addresses' => ['10.90.0.1'], 'allowed_sources' => ['10.90.0.2/32']])
        ->and(databases_schema_errors($update))->toBe([]);
    $this->agents->succeed($update['handle'], ['changed' => true, 'container_id' => 'c', 'health' => 'healthy']);

    // Nothing changed: nothing re-applied.
    app(ConvergeInstanceNetwork::class)->converge($this->organization->id);
    expect($this->agents->dispatched('db.instance.update'))->toHaveCount(1)
        ->and($instance->refresh()->published_addresses)->toBe(['10.90.0.1']);
})->skip(fn () => ! class_exists(EnvironmentData::class), 'Projects contracts missing');

it('refuses to drop the host databases of an earlier version unless the operator confirms', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_10_30_110001_recreate_databases_tables.php';
    Schema::create('databases_servers', fn ($table) => $table->string('id'));
    DB::table('databases_servers')->insert(['id' => 'legacy']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'FALAK_DROP_LEGACY_DATABASES=1')
        ->and(Schema::hasTable('databases_servers'))->toBeTrue();

    putenv('FALAK_DROP_LEGACY_DATABASES=1');

    try {
        $migration->up();
    } finally {
        putenv('FALAK_DROP_LEGACY_DATABASES');
    }

    expect(Schema::hasTable('databases_servers'))->toBeFalse()->and(Schema::hasTable('databases_instances'))->toBeTrue();
});
