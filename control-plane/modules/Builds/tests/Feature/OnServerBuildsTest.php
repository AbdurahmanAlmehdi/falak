<?php

use Falak\Builds\Application\Actions\InstallServerBuilder;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;
use Falak\Builds\Infrastructure\EloquentBuildService;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

beforeEach(function () {
    $this->artifacts = sys_get_temp_dir().'/falak-artifacts-'.Str::random(8);
    config([
        'builds.artifacts.driver' => 'local',
        'builds.artifacts.local.root' => $this->artifacts,
        'builds.artifacts.local.url' => 'http://falak.test',
        'builds.local_builder.token' => 'local-builder-token-123',
    ]);
    app()->forgetInstance(ArtifactStorage::class);
});

afterEach(fn () => File::deleteDirectory($this->artifacts));

/**
 * Agents report these facts per server id.
 *
 * @param  array<string, array<string, mixed>>  $facts
 */
function on_server_facts(array $facts): void
{
    app()->instance(AgentDirectory::class, new class($facts) implements AgentDirectory
    {
        public function __construct(private array $facts) {}

        public function forServer(string $serverId): ?AgentInfo
        {
            return isset($this->facts[$serverId])
                ? new AgentInfo('agent', $serverId, AgentStatus::Online, '1.0.0', 'host', 'amd64', $this->facts[$serverId], [], new DateTimeImmutable, null, null)
                : null;
        }

        public function forServers(array $serverIds): array
        {
            return array_filter(array_combine($serverIds, array_map(fn ($id) => $this->forServer($id), $serverIds)));
        }

        public function metrics(string $serverId, DateTimeInterface $since): array
        {
            return [];
        }
    });
}

function on_server_world(string $buildMode = 'on-server', int $servers = 1): DeployWorld
{
    $world = deploy_world(servers: $servers, site: ['build_mode' => $buildMode]);
    on_server_facts([$world->servers[0]->id => ['memory_bytes' => 8 * 1024 ** 3, 'cpus' => 4]]);
    app()->instance(BuildService::class, app(EloquentBuildService::class));

    return $world;
}

function on_server_request(DeployWorld $world): Build
{
    return Build::query()->findOrFail(app(BuildService::class)->request(new BuildRequest($world->site->id, str_repeat('a', 40), 'main'))->id);
}

function on_server_poll(string $token)
{
    return test()->withToken($token)->getJson('/api/internal/builds/next?wait=0&builder=b');
}

/** The plaintext token of the builder installed on $serverId (from the env file the agent was asked to write). */
function on_server_token(DeployWorld $world, string $serverId): string
{
    $env = collect($world->agents->dispatched('system.write_file', $serverId))
        ->last(fn ($c) => $c['payload']['path'] === InstallServerBuilder::ENV_PATH);
    preg_match('/FALAK_BUILDER_TOKEN=(\S+)/', $env['payload']['content'], $m);

    return $m[1];
}

it('pins on-server builds to a capped builder installed on demand on the leader server', function () {
    $world = on_server_world(servers: 2);
    $leader = $world->servers[0]->id;
    $build = on_server_request($world);

    $builder = Builder::query()->where('server_id', $leader)->sole();
    $files = collect($world->agents->dispatched('system.write_file', $leader))->keyBy(fn ($c) => $c['payload']['path']);

    expect($build->builder_server_id)->toBe($leader)
        ->and($build->mode)->toBe('native')
        ->and($builder->pool)->toBeFalse()
        ->and($builder->kind)->toBe('server')
        ->and($files[InstallServerBuilder::UNIT_PATH]['payload']['content'])->toContain('Slice=falak-build.slice', 'EnvironmentFile=-/etc/falak/builder-caps.env')
        ->and($files[InstallServerBuilder::SLICE_PATH]['payload']['content'])->toContain('MemoryMax=4915M', 'CPUQuota=300%', 'TasksMax=4096', 'IOWeight=50', 'CPUWeight=50')
        ->and($files[InstallServerBuilder::CAPS_ENV_PATH]['payload']['content'])->toContain('FALAK_BUILD_MEMORY_MB=4915', 'FALAK_BUILD_CPUS=3', 'FALAK_BUILD_CGROUP_PARENT=falak-build.slice')
        ->and($world->agents->dispatched('system.exec'))->toHaveCount(1)
        // Replicated sites build once, on the leader only.
        ->and(Builder::query()->where('server_id', $world->servers[1]->id)->exists())->toBeFalse();

    // The pool (control-plane) builder never gets it; the site server's builder does.
    on_server_poll('local-builder-token-123')->assertNoContent();
    expect(on_server_poll(on_server_token($world, $leader))->assertOk()->json('id'))->toBe($build->id);

    // A second build reuses the server's builder: one builder (one process, one build at a time) per server.
    on_server_request($world);
    expect(Builder::query()->where('server_id', $leader)->count())->toBe(1)
        ->and($world->agents->dispatched('system.exec'))->toHaveCount(1);
});

it('keeps unpinned builds on pool builders: a site server builder never takes them', function () {
    $pinned = on_server_world();
    on_server_request($pinned);
    $siteToken = on_server_token($pinned, $pinned->servers[0]->id);
    on_server_poll($siteToken)->assertOk();

    $world = deploy_world(site: ['build_mode' => 'native']);
    app()->instance(BuildService::class, app(EloquentBuildService::class));
    $build = on_server_request($world);

    expect($build->builder_server_id)->toBeNull();
    on_server_poll($siteToken)->assertNoContent();
    expect(on_server_poll('local-builder-token-123')->assertOk()->json('id'))->toBe($build->id);
});

it('lets a builder server (pool) take pinned builds of its own server and every unpinned build', function () {
    $world = on_server_world('native');
    $server = sites_server($world->organization->id, ['type' => ServerType::Builder, 'name' => 'builder-1']);
    on_server_facts([$server->id => ['memory_bytes' => 4 * 1024 ** 3, 'cpus' => 1]]);
    ServerProvisioned::dispatch($server->id, $world->organization->id, 'builder', 'builder-1');

    $builder = Builder::query()->where('server_id', $server->id)->sole();
    $slice = collect($world->agents->dispatched('system.write_file'))->firstWhere('payload.path', InstallServerBuilder::SLICE_PATH);

    expect($builder->pool)->toBeTrue()
        // Dedicated builder servers: 85 % of the RAM, still at least one core.
        ->and($slice['payload']['content'])->toContain('MemoryMax=3481M', 'CPUQuota=100%')
        ->and($builder->caps)->toMatchArray(['memory_max_bytes' => 3481 * 1024 ** 2, 'cpus' => 1]);

    $build = on_server_request($world);
    expect(on_server_poll(on_server_token($world, $server->id))->assertOk()->json('id'))->toBe($build->id);
});

it('renders caps from the facts, leaving out unknown ones, with the disk guard only when configured', function () {
    expect(InstallServerBuilder::slice(InstallServerBuilder::caps([], false)))->not->toContain('MemoryMax', 'CPUQuota')
        ->and(InstallServerBuilder::capsEnvironment(InstallServerBuilder::caps([], false)))->toBe("FALAK_BUILD_CGROUP_PARENT=falak-build.slice\n");

    config(['builds.caps.memory_percent' => 50, 'builds.caps.min_free_gb' => '8', 'builds.caps.abort_free_gb' => '3']);
    $caps = InstallServerBuilder::caps(['memory_bytes' => 2 * 1024 ** 3, 'cpus' => 8], false);

    expect(InstallServerBuilder::slice($caps))->toContain('MemoryMax=1024M', 'CPUQuota=700%')
        ->and(InstallServerBuilder::capsEnvironment($caps))->toBe(implode("\n", [
            'FALAK_BUILD_MEMORY_MB=1024', 'FALAK_BUILD_CPUS=7', 'FALAK_BUILD_CGROUP_PARENT=falak-build.slice',
            'FALAK_BUILD_MIN_FREE_GB=8', 'FALAK_BUILD_ABORT_FREE_GB=3', '',
        ]));
});

it('re-renders the caps when the server reports new memory or cores, without rotating the token', function () {
    $world = on_server_world();
    $leader = $world->servers[0]->id;
    on_server_request($world);
    $token = Builder::query()->where('server_id', $leader)->value('token_hash');
    $writes = count($world->agents->dispatched('system.write_file'));

    AgentFactsReported::dispatch('agent', $world->organization->id, $leader, ['memory_bytes' => 8 * 1024 ** 3, 'cpus' => 4, 'hostname' => 'renamed']);
    expect($world->agents->dispatched('system.write_file'))->toHaveCount($writes);

    AgentFactsReported::dispatch('agent', $world->organization->id, $leader, ['memory_bytes' => 16 * 1024 ** 3, 'cpus' => 8]);
    $slice = collect($world->agents->dispatched('system.write_file'))->last(fn ($c) => $c['payload']['path'] === InstallServerBuilder::SLICE_PATH);

    expect($slice['payload']['content'])->toContain('MemoryMax=9830M', 'CPUQuota=700%')
        ->and($world->agents->last('system.exec')['payload']['script'])
        ->toContain('systemctl daemon-reload', 'systemctl set-property --runtime falak-build.slice MemoryMax=9830M CPUQuota=700% TasksMax=4096 IOWeight=50 CPUWeight=50')
        ->not->toContain('restart')
        // The unit is current already: only the caps are rewritten.
        ->and(collect($world->agents->dispatched('system.write_file'))->where('payload.path', InstallServerBuilder::UNIT_PATH))->toHaveCount(1)
        ->and(Builder::query()->where('server_id', $leader)->value('token_hash'))->toBe($token)
        ->and(Builder::query()->where('server_id', $leader)->sole()->caps['cpus'])->toBe(7);
});

it('fails the request early when the server builder is disabled', function () {
    $world = on_server_world();
    on_server_request($world);
    Builder::query()->update(['enabled' => false]);

    expect(fn () => on_server_request($world))->toThrow(InvalidArgumentException::class, 'is disabled');
});

it('deploys on-server sites (the orchestrator no longer refuses them)', function () {
    $world = on_server_world();
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);

    $build = Build::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Building)
        ->and($build->status)->toBe(BuildStatus::Queued)
        ->and($build->builder_server_id)->toBe($world->servers[0]->id);
});

it('pins on-server builds on the control-plane machine to the control plane builder', function () {
    $world = on_server_world();
    $world->servers[0]->forceFill(['control_plane_host' => true])->save();
    $build = on_server_request($world);

    $local = Builder::query()->where('kind', 'local')->sole();
    expect($build->builder_server_id)->toBe($world->servers[0]->id)
        ->and($local->server_id)->toBe($world->servers[0]->id)
        ->and(Builder::query()->count())->toBe(1)
        ->and($world->agents->dispatched('system.exec'))->toBe([]);

    expect(on_server_poll('local-builder-token-123')->assertOk()->json('id'))->toBe($build->id);
});

it('refuses the control plane builder when it is disabled', function () {
    $world = on_server_world();
    $world->servers[0]->forceFill(['control_plane_host' => true])->save();
    on_server_request($world);
    Builder::query()->where('kind', 'local')->update(['enabled' => false]);

    expect(fn () => on_server_request($world))->toThrow(InvalidArgumentException::class, "The control plane's builder is disabled.");
});

it('never turns the control plane builder into a server builder, and keeps it when its server is deleted', function () {
    $world = on_server_world();
    $server = $world->servers[0];
    $server->forceFill(['control_plane_host' => true])->save();
    on_server_request($world);
    $local = Builder::query()->where('kind', 'local')->sole();

    expect(app(InstallServerBuilder::class)(app(ServerDirectory::class)->find($server->id))->id)->toBe($local->id)
        ->and($local->refresh()->kind)->toBe('local')
        ->and($world->agents->dispatched())->toBe([]);

    ServerDeleted::dispatch($server->id, $world->organization->id, 'web', $server->name);

    // Still a working (pool) builder; the deleted server's queued pinned build fails at once.
    expect($local->refresh()->server_id)->toBeNull()
        ->and(Build::query()->sole()->status)->toBe(BuildStatus::Failed);
    on_server_poll('local-builder-token-123')->assertNoContent();
});

it('installs a site server builder under a per-server lock', function () {
    $world = on_server_world();
    $leader = $world->servers[0]->id;
    $cache = new class(app()) extends CacheManager
    {
        public array $locks = [];

        public function lock(string $name, int $seconds = 0): Lock
        {
            $this->locks[] = $name;

            return $this->store()->lock($name, $seconds);
        }
    };
    Cache::swap($cache);

    on_server_request($world);
    on_server_request($world);

    // Every lookup-or-install holds the server's lock, and releases it.
    expect($cache->locks)->toBe(["builds:server-builder:{$leader}", "builds:server-builder:{$leader}"])
        ->and(Cache::lock("builds:server-builder:{$leader}", 30)->get())->toBeTrue()
        ->and($world->agents->dispatched('system.exec'))->toHaveCount(1);
});

it('brings builders installed before the caps up to date without a token rotation or a restart', function () {
    $world = on_server_world();
    $leader = $world->servers[0]->id;
    on_server_request($world);
    $builder = Builder::query()->where('server_id', $leader)->sole();
    $builder->forceFill(['caps' => null])->save(); // a pre-v0.23 install: old unit, no slice
    $token = $builder->token_hash;
    $before = count($world->agents->dispatched());

    app(InstallServerBuilder::class)->refreshPending();

    $sent = array_slice($world->agents->dispatched(), $before);
    $paths = array_map(fn ($c) => $c['payload']['path'] ?? $c['handle']->type, $sent);

    expect($paths)->toBe([InstallServerBuilder::UNIT_PATH, InstallServerBuilder::SLICE_PATH, InstallServerBuilder::CAPS_ENV_PATH, 'system.exec'])
        ->and($sent[0]['payload']['content'])->toContain('Slice=falak-build.slice')
        ->and($sent[3]['payload']['script'])->toContain('systemctl daemon-reload', 'set-property --runtime falak-build.slice MemoryMax=4915M')
        ->not->toContain('restart')
        ->and($builder->refresh()->token_hash)->toBe($token)
        ->and($builder->caps['cpus'])->toBe(3);

    // Once: nothing pending afterwards.
    app(InstallServerBuilder::class)->refreshPending();
    expect($world->agents->dispatched())->toHaveCount(count($sent) + $before);
});

it('keeps the caps stale when the agent is away, so a later report retries', function () {
    $world = on_server_world();
    $leader = $world->servers[0]->id;
    on_server_request($world);
    $world->agents->unavailable($leader);

    AgentFactsReported::dispatch('agent', $world->organization->id, $leader, ['memory_bytes' => 16 * 1024 ** 3, 'cpus' => 8]);

    expect(Builder::query()->where('server_id', $leader)->sole()->caps['cpus'])->toBe(3);
});

it('only queues facts reports that change a server builder caps', function () {
    $world = on_server_world();
    $leader = $world->servers[0]->id;
    on_server_request($world);
    Queue::fake();

    AgentFactsReported::dispatch('agent', $world->organization->id, $leader, ['memory_bytes' => 8 * 1024 ** 3, 'cpus' => 4, 'hostname' => 'renamed']);
    AgentFactsReported::dispatch('agent', $world->organization->id, $world->servers[0]->id.'x', ['memory_bytes' => 1, 'cpus' => 1]);
    Queue::assertNotPushed(CallQueuedListener::class, fn ($job) => $job->method === 'facts');

    AgentFactsReported::dispatch('agent', $world->organization->id, $leader, ['memory_bytes' => 16 * 1024 ** 3, 'cpus' => 8]);
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->method === 'facts');
});
