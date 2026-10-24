<?php

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Listeners\DeploySplitSitesFirst;
use Falak\Deployments\Application\Orchestration\StepPayloads;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\ReleaseStatus;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentRolledBack;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\ComposeRewrites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

const COMPOSE_REPO_FILE = <<<'YAML'
services:
  app:
    build: .
    ports: ["8080:8080"]
    environment:
      REDIS_URL: redis://redis:6379
      SECRET: ${APP_KEY}
    labels:
      falak.deploy.leader_command: "node migrate.js --force"
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:8080/health"]
  redis:
    image: redis:7.4.1-alpine
    volumes: [redis-data:/data]
volumes:
  redis-data:
YAML;

function compose_world(int $servers = 1, array $site = []): DeployWorld
{
    $world = deploy_world(servers: $servers, site: [
        'runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'compose_source' => 'repo', 'compose_file' => null, 'deploy_script' => '',
        'public_services' => [['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000]], 'app_port' => 3000,
        'test_domain_enabled' => true,
        ...$site,
    ]);
    config(['sites.test_domain' => 'falak.test', 'builds.registry.username' => 'falak', 'builds.registry.password' => 'secret']);
    $world->builds->composeContent = COMPOSE_REPO_FILE;

    return $world;
}

function compose_deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $releaseId = null): Deployment
{
    return app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, releaseId: $releaseId);
}

it('deploys a repo compose site: build → pull everywhere → leader command → up --wait → health check', function () {
    $world = compose_world(servers: 2);
    $deployment = compose_deploy($world);

    expect($deployment->refresh()->strategy)->toBe(Strategy::Compose)
        ->and($deployment->status)->toBe(DeploymentStatus::Building);

    $world->builds->succeed();
    deploy_complete($world->agents, 'docker.compose.pull');

    // Leader command only on the leader, before any activation.
    $leader = deploy_pending($world->agents, 'system.exec');
    expect($leader)->toHaveCount(1)
        ->and($leader[0]['handle']->serverId)->toBe($world->servers[0]->id)
        ->and($leader[0]['payload']['script'])->toContain("run --rm -T --no-deps 'app' 'node' 'migrate.js' '--force'")
        ->and(deploy_pending($world->agents, 'docker.compose.up'))->toBe([]);

    deploy_run_all($world->agents);

    $up = $world->agents->last('docker.compose.up')['payload'];
    $rendered = Yaml::parse($up['files'][0]['content']);
    $release = Release::query()->find($deployment->release_id);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(deploy_types($world->agents, $world->servers[1]->id))->toBe(['docker.compose.pull', 'docker.compose.up', 'deploy.prune'])
        ->and($up['project'])->toBe($world->site->slug)
        ->and($up['directory'])->toBe("/srv/falak/sites/{$world->site->slug}/releases/".strtoupper($deployment->release_id))
        ->and($up['wait'])->toBeTrue()
        ->and($up['wait_timeout_s'])->toBe(300)
        ->and($up['project_env_file'])->toBe('.env')
        ->and($up['registry_auth'])->toBe(['server' => 'registry.falak.local', 'username' => 'falak-scoped', 'password' => "scoped-pull:{$world->site->organization_id}"])
        ->and($up['env']['APP_KEY'])->toBe('base64:secret')
        ->and($up['env']['FALAK_SERVER_ID'])->toBe(strtoupper($world->servers[1]->id))
        ->and($up['env']['FALAK_RELEASE_ID'])->toBe(strtoupper($deployment->release_id))
        ->and($up['files'][1]['content'])->toContain('FALAK_SITE_ID='.strtoupper($world->site->id))
        ->and($rendered['services']['app']['image'])->toStartWith('registry.falak.local/falak/shop/app@sha256:')
        ->and($rendered['services']['app'])->not->toHaveKey('build')
        ->and($rendered['services']['app']['ports'])->toBe(['127.0.0.1:3000:8080'])
        ->and($rendered['services']['app']['labels']['falak.release'])->toBe(strtoupper($deployment->release_id))
        ->and($release->status)->toBe(ReleaseStatus::Active)
        // pulled images are pinned to what the server resolved
        ->and(Yaml::parse($release->compose['yaml'])['services']['redis']['image'])->toBe('redis:7.4.1-alpine@sha256:'.str_repeat('2', 64));

    // Health check through the edge on the public service's test domain.
    expect(collect($GLOBALS['deploy_http_requests'])->pluck('url')->all())->toContain('https://'.$world->site->slug.'.falak.test/up');

    // Services tab state recorded from the up result.
    expect(app(ComposeSites::class)->status($world->site->id))->toHaveCount(4);
});

it('deploys inline compose files without a build and skips the leader step when there is no leader command', function () {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  redis:\n    image: redis:7\n", 'created_at' => now()]);

    $deployment = compose_deploy($world);
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->builds->builds)->toBe([])
        ->and(deploy_types($world->agents))->toBe(['docker.compose.pull', 'docker.compose.up', 'deploy.prune'])
        ->and($world->agents->last('docker.compose.up')['payload'])->not->toHaveKey('registry_auth')
        ->and(Release::query()->find($deployment->release_id)->compose['version'])->toBe(1);
});

it('joins the stack to its environment network when the environment has a database container', function (bool $withDatabase) {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n", 'created_at' => now()]);
    $environment = strtolower((string) Str::ulid());
    $projects = Mockery::mock(ProjectDirectory::class)->shouldIgnoreMissing();
    $projects->shouldReceive('projectOf')->andReturnUsing(fn ($kind, $id) => $id === $world->site->id ? new ServiceData('s1', $world->organization->id, 'p1', $environment, ServiceKind::Site, $id, 'shop', 0, 0) : null);
    $projects->shouldReceive('servicesIn')->with($environment)->andReturn($withDatabase ? [new ServiceData('s2', $world->organization->id, 'p1', $environment, ServiceKind::Database, strtolower((string) Str::ulid()), 'db', 0, 0)] : []);
    app()->instance(ProjectDirectory::class, $projects);
    app()->forgetInstance(StepPayloads::class);

    compose_deploy($world);
    deploy_run_all($world->agents);
    $up = $world->agents->last('docker.compose.up');

    expect($up['payload']['join_networks'] ?? null)->toBe($withDatabase ? ["falak-env-{$environment}"] : null)
        ->and(array_column($up['payload']['files'], 'name'))->toBe($withDatabase ? ['compose.yaml', 'compose.falak.yaml', '.env'] : ['compose.yaml', '.env']);

    if ($withDatabase) {
        // The services start on it (their first healthchecks reach the databases), keeping their default network.
        $override = Yaml::parse($up['payload']['files'][1]['content']);
        expect($override['networks'])->toBe(["falak-env-{$environment}" => ['external' => true]])
            ->and(array_keys($override['services']['app']['networks']))->toBe(['default', "falak-env-{$environment}"]);
    }
})->with([true, false]);

it('rolls a failed compose release back with the previous release files', function () {
    Event::fake([DeploymentFailed::class, DeploymentRolledBack::class]);
    $world = compose_world(servers: 2);
    $first = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);
    expect($first->refresh()->status)->toBe(DeploymentStatus::Succeeded);
    $firstYaml = Release::query()->find($first->release_id)->compose['yaml'];

    $second = compose_deploy($world);
    $world->builds->succeed();
    deploy_complete($world->agents, 'docker.compose.pull');
    deploy_complete($world->agents, 'system.exec');

    // app-1 comes up, app-2's containers never get healthy.
    $ups = deploy_pending($world->agents, 'docker.compose.up');
    expect($ups)->toHaveCount(2);
    $world->agents->succeed($ups[0]['handle'], deploy_result($ups[0]));
    $world->agents->fail($ups[1]['handle'], 'docker compose up exited 1', 1, result: ['exit_code' => 1, 'services' => [
        ['service' => 'app', 'container_id' => 'c', 'state' => 'running', 'health' => 'unhealthy', 'image' => 'app', 'restarts' => 3],
    ]]);

    // Both servers go back: app-1 switched, and app-2's failed `up` already replaced its containers.
    $reverts = deploy_pending($world->agents, 'docker.compose.up');
    expect($reverts)->toHaveCount(2)
        ->and(array_map(fn ($c) => $c['handle']->serverId, $reverts))->toEqualCanonicalizing($world->serverIds())
        ->and($reverts[0]['payload']['directory'])->toEndWith('/releases/'.strtoupper($first->release_id))
        ->and($reverts[0]['payload']['files'][0]['content'])->toBe($firstYaml);

    deploy_run_all($world->agents);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($second->rolled_back)->toBeTrue()
        ->and($second->error)->toContain('docker compose up exited 1')
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id)
        ->and(Release::query()->find($second->release_id)->status)->toBe(ReleaseStatus::Failed);

    Event::assertDispatched(DeploymentRolledBack::class, fn ($e) => $e->automatic);
});

it('rolls back manually to a retained compose release', function () {
    $world = compose_world();
    $first = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);
    compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $rollback = compose_deploy($world, Trigger::Rollback, $first->release_id);
    deploy_run_all($world->agents);

    $up = $world->agents->last('docker.compose.up')['payload'];
    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($up['directory'])->toEndWith('/releases/'.strtoupper($first->release_id))
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id);
});

it('ships the repository files a compose project mounts with each release (agent feature compose.v2)', function () {
    $world = compose_world();
    $world->builds->composeContent = "services:\n  app:\n    build: .\n    volumes: [\"./docker/nginx.conf:/etc/nginx/nginx.conf:ro\", \"./data:/data\"]\n";
    $world->builds->composeAssets = [['path' => 'docker/nginx.conf', 'content' => base64_encode("events {}\n"), 'mode' => 0o644]];

    // An agent that can't write repository files gets a clear error instead of a broken mount.
    Agent::factory()->create(['server_id' => $world->servers[0]->id, 'organization_id' => $world->site->organization_id, 'facts' => ['features' => []]]);
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('too old for compose projects that mount repository files');

    Agent::query()->where('server_id', $world->servers[0]->id)->update(['facts' => ['features' => ['compose.v2']]]);
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->error)->toBeNull();
    $pull = $world->agents->last('docker.compose.pull')['payload'];
    $up = $world->agents->last('docker.compose.up')['payload'];
    $app = Yaml::parse($up['files'][0]['content'])['services']['app'];

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($pull['assets'])->toBe([['path' => 'docker/nginx.conf', 'content' => base64_encode("events {}\n"), 'mode' => 0o644]])
        ->and($up['assets'])->toBe($pull['assets'])
        ->and($app['volumes'])->toBe(['./repo/docker/nginx.conf:/etc/nginx/nginx.conf:ro', 'app-data:/data'])
        ->and(Release::query()->find($deployment->release_id)->compose['assets'])->toHaveCount(1);
});

it('points stack variables at services moved out of the stack, resolved like other references', function () {
    $world = compose_world();
    app()->instance(ComposeServiceExtraction::class, new class implements ComposeServiceExtraction
    {
        public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
        {
            throw new LogicException('not used');
        }

        public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
        {
            throw new LogicException('not used');
        }

        public function rewrites(string $siteId): ComposeRewrites
        {
            return new ComposeRewrites([ComposeRewrites::STACK => ['DATABASE_URL' => 'postgres://shop@10.0.0.5:5432/shop'], 'worker' => ['DB_PASSWORD' => 'other-db-password']]);
        }
    });

    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $env = $world->agents->last('docker.compose.up')['payload']['env'];
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($env['DATABASE_URL'])->toBe('postgres://shop@10.0.0.5:5432/shop')
        ->and($env['FALAK_SVC_WORKER_DB_PASSWORD'])->toBe('other-db-password')
        ->and($env['APP_KEY'])->toBe('base64:secret');
});

it('fails with a clear error when the compose file cannot be rendered', function () {
    $world = compose_world();
    $world->builds->composeContent = "services:\n  web:\n    image: nginx:1\n";
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('The public service app is not in the compose file.')
        ->and($world->agents->dispatched('docker.compose.up'))->toBe([]);
});

it('waits for a split-out service\'s own site before deploying the stack without it', function () {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  api:\n    image: ghcr.io/acme/api:1\n", 'created_at' => now()]);
    $split = '01j9zq4n8v2m6r0t3w5y7b9d1f';
    Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode(['api' => ['mode' => 'site', 'site_id' => $split]])]);

    $deployment = compose_deploy($world);
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain("its own Falak site(s) that aren't live yet")
        ->and($deployment->setting('awaits_sites'))->toBe([$split])
        ->and($world->agents->dispatched('docker.compose.up'))->toBe([]);
});

it('deploys a service split out at the stack\'s creation first, then the stack, without manual steps', function () {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  api:\n    image: ghcr.io/acme/api:1\n", 'created_at' => now()]);
    $split = app(SiteFactory::class)->create($world->site->organization_id, null, [
        'name' => 'shop-api', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'ghcr.io/acme/api:1', 'container_port' => 3000,
        'server_ids' => [$world->servers[0]->id], 'test_domain_enabled' => false,
    ])->site;
    Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode(['api' => ['mode' => 'site', 'site_id' => $split->id]])]);

    $first = compose_deploy($world);
    deploy_run_all($world->agents);

    $stack = Deployment::query()->where('site_id', $world->site->id)->orderBy('number')->get();
    $api = Deployment::query()->where('site_id', $split->id)->get();

    // The stack stops for the site, the site deploys, the stack follows once.
    expect($first->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($first->error)->toContain('Deploying shop-api first; the stack follows when it\'s live.')
        ->and($api)->toHaveCount(1)
        ->and($api[0]->status)->toBe(DeploymentStatus::Succeeded)
        ->and($stack)->toHaveCount(2)
        ->and($stack[1]->status)->toBe(DeploymentStatus::Succeeded)
        ->and($stack[1]->setting('awaits_site'))->toBeNull();

    // Another deploy of the site doesn't redeploy the stack again.
    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($split->id), Trigger::Manual);
    deploy_run_all($world->agents);
    expect(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(2);

    // The stop handled again (a retried job) finds the site live: no second deploy of it, and the stack already moved on.
    $siteDeploys = Deployment::query()->where('site_id', $split->id)->count();
    app(DeploySplitSitesFirst::class)->onStackFailed(new DeploymentFailed($first->id, $first->organization_id, $first->site_id, 'shop', $first->number, 'manual', 'fetch', (string) $first->error, $first->commit, false));
    deploy_run_all($world->agents);
    expect(Deployment::query()->where('site_id', $split->id)->count())->toBe($siteDeploys)
        ->and(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(2);
});

const SPLIT_STACK = "services:\n  app:\n    image: nginx:1.27\n    depends_on: [api]\n  api:\n    image: ghcr.io/acme/api:1\n    depends_on: [postgres, redis]\n  postgres:\n    image: postgres:17\n  redis:\n    image: redis:7\n";

/**
 * A stack with `api` split out into its own Docker site that uses the stack's postgres and redis.
 *
 * @return array{0: DeployWorld, 1: SiteData}
 */
function split_stack_world(bool $bootstrapCapable = true): array
{
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => SPLIT_STACK, 'created_at' => now()]);
    $split = app(SiteFactory::class)->create($world->site->organization_id, null, [
        'name' => 'shop-api', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'ghcr.io/acme/api:1', 'container_port' => 3000,
        'server_ids' => [$world->servers[0]->id], 'test_domain_enabled' => false,
    ])->site;
    Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode(['api' => ['mode' => 'site', 'site_id' => $split->id, 'uses' => ['postgres', 'redis']]])]);

    Agent::factory()->create(['server_id' => $world->servers[0]->id, 'organization_id' => $world->site->organization_id,
        'facts' => ['features' => $bootstrapCapable ? ['compose.up.services', 'docker.networks', 'docker.networks.create'] : ['docker.networks']]]);

    return [$world, $split];
}

it('bootstraps a new stack with the services its split-out site uses, deploys the site, then the full stack', function () {
    [$world, $split] = split_stack_world();

    $first = compose_deploy($world);
    deploy_run_all($world->agents);

    $stack = Deployment::query()->where('site_id', $world->site->id)->orderBy('number')->get();
    $ups = array_map(fn (array $c) => $c['payload'], $world->agents->dispatched('docker.compose.up'));

    // 1. Bootstrap: only postgres and redis (and what compose pulls in), nothing removed, no edge check.
    expect($first->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($first->setting('bootstrap'))->toBe(['postgres', 'redis'])
        ->and($first->setting('awaits_sites'))->toBe([$split->id])
        ->and($ups[0]['services'])->toBe(['postgres', 'redis'])
        ->and($ups[0]['remove_orphans'])->toBeFalse()
        ->and(OutputLine::query()->where('deployment_id', $first->id)->pluck('data')->implode(''))->toContain('Bootstrap: started postgres, redis for shop-api; the full stack follows once shop-api is live.')
        // 2. The split-out site deployed.
        ->and(Deployment::query()->where('site_id', $split->id)->value('status'))->toBe(DeploymentStatus::Succeeded)
        // 3. The full stack: every service, orphans removed, a normal deployment.
        ->and($stack)->toHaveCount(2)
        ->and($stack[1]->status)->toBe(DeploymentStatus::Succeeded)
        ->and($stack[1]->setting('bootstrap'))->toBeNull()
        ->and(end($ups))->not->toHaveKey('services')
        ->and(end($ups)['remove_orphans'])->toBeTrue()
        ->and(Release::current($world->site->id)->id)->toBe($stack[1]->release_id);

    // The panel shows the bootstrap pass as partial.
    $rows = collect($this->getJson("/api/v1/sites/{$world->site->id}/deployments")->assertOk()->json('data'))->keyBy('id');
    expect($rows[$first->id]['partial'])->toBe(['services' => ['postgres', 'redis'], 'awaits_sites' => [$split->id]])
        ->and($rows[$stack[1]->id]['partial'])->toBeNull();
});

it('deploys the split-out site first when the stack already runs, or its agents can\'t start a subset', function (bool $live) {
    [$world, $split] = split_stack_world(bootstrapCapable: $live);

    if ($live) {
        // The stack ran before api was split out.
        Site::query()->whereKey($world->site->id)->update(['compose_services' => null]);
        $old = compose_deploy($world);
        deploy_run_all($world->agents);
        expect($old->refresh()->status)->toBe(DeploymentStatus::Succeeded);
        Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode(['api' => ['mode' => 'site', 'site_id' => $split->id, 'uses' => ['postgres', 'redis']]])]);
    }

    $stop = compose_deploy($world);
    deploy_run_all($world->agents);

    $stack = Deployment::query()->where('site_id', $world->site->id)->orderByDesc('number')->get();
    expect($stop->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($stop->error)->toContain('Deploying shop-api first; the stack follows when it\'s live.')
        ->and($stop->setting('bootstrap'))->toBeNull()
        ->and(Deployment::query()->where('site_id', $split->id)->value('status'))->toBe(DeploymentStatus::Succeeded)
        ->and($stack[0]->status)->toBe(DeploymentStatus::Succeeded)
        ->and($stack[0]->id)->not->toBe($stop->id);
})->with(['stack already live' => [true], 'agent without compose.up.services' => [false]]);

it('deploys the full stack once, after all of its split-out sites are live', function () {
    [$world, $api] = split_stack_world();
    $worker = app(SiteFactory::class)->create($world->site->organization_id, null, [
        'name' => 'shop-worker', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'ghcr.io/acme/worker:1', 'container_port' => 3000,
        'server_ids' => [$world->servers[0]->id], 'test_domain_enabled' => false,
    ])->site;
    Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode([
        'api' => ['mode' => 'site', 'site_id' => $api->id, 'uses' => ['postgres', 'redis']],
        'worker' => ['mode' => 'site', 'site_id' => $worker->id, 'uses' => ['redis']],
    ])]);

    $first = compose_deploy($world);
    deploy_run_all($world->agents);

    $stack = Deployment::query()->where('site_id', $world->site->id)->orderBy('number')->get();
    expect($first->refresh()->setting('bootstrap'))->toBe(['postgres', 'redis'])
        ->and($first->setting('awaits_sites'))->toBe([$api->id, $worker->id])
        ->and(Deployment::query()->where('site_id', $api->id)->value('status'))->toBe(DeploymentStatus::Succeeded)
        ->and(Deployment::query()->where('site_id', $worker->id)->value('status'))->toBe(DeploymentStatus::Succeeded)
        ->and($stack)->toHaveCount(2)
        ->and($stack[1]->status)->toBe(DeploymentStatus::Succeeded)
        ->and($stack[1]->setting('bootstrap'))->toBeNull();
});

it('leaves a bootstrapped stack as it is when its split-out site fails to deploy', function () {
    [$world, $split] = split_stack_world();

    $first = compose_deploy($world);
    // Bootstrap, then the site's swap fails its health check (it can't run without something we didn't start).
    for ($i = 0; $i < 50 && deploy_pending($world->agents, 'deploy.container.swap') === [] && deploy_complete($world->agents) > 0; $i++) {
    }
    foreach (deploy_pending($world->agents, 'deploy.container.swap') as $swap) {
        $world->agents->fail($swap['handle'], 'health check /healthz on :3001 did not return 200 within 30s');
    }
    deploy_run_all($world->agents);

    expect($first->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(Deployment::query()->where('site_id', $split->id)->value('status'))->toBe(DeploymentStatus::Failed)
        ->and(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(1);
});

it('follows a stack up only after a recent stop for its site, and never over a newer deployment', function () {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  api:\n    image: ghcr.io/acme/api:1\n", 'created_at' => now()]);
    $split = app(SiteFactory::class)->create($world->site->organization_id, null, [
        'name' => 'shop-api', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'ghcr.io/acme/api:1', 'container_port' => 3000,
        'server_ids' => [$world->servers[0]->id], 'test_domain_enabled' => false,
    ])->site;
    Site::query()->whereKey($world->site->id)->update(['compose_services' => json_encode(['api' => ['mode' => 'site', 'site_id' => $split->id]])]);
    $stop = fn (array $attributes) => Deployment::query()->create([
        'organization_id' => $world->site->organization_id, 'site_id' => $world->site->id, 'site_slug' => $world->site->slug,
        'number' => (int) Deployment::query()->where('site_id', $world->site->id)->max('number') + 1, 'trigger' => Trigger::Manual,
        'status' => DeploymentStatus::Failed, 'settings' => ['awaits_site' => $split->id], 'error' => 'waits', ...$attributes,
    ]);
    $deploySite = function () use ($world, $split) {
        app(TriggerDeployment::class)(app(SiteDirectory::class)->find($split->id), Trigger::Manual);
        deploy_run_all($world->agents);
    };

    // A stop older than a day is not followed up.
    $stop(['created_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);
    $deploySite();
    expect(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(1);

    // A recent stop followed by another stack deployment (a push) is not followed up: only the latest one counts.
    $stop(['finished_at' => now()]);
    $stop(['status' => DeploymentStatus::Succeeded, 'settings' => [], 'finished_at' => now()]);
    $deploySite();
    expect(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(3);

    // A recent stop as the latest deployment is followed up once.
    $stop(['finished_at' => now()]);
    $deploySite();
    expect(Deployment::query()->where('site_id', $world->site->id)->count())->toBe(5);
});

it('checks every public service through the edge', function () {
    $world = compose_world(site: ['public_services' => [
        ['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000],
        ['service' => 'redis', 'port' => 6379, 'domain' => 'cache.example.com', 'host_port' => 3001],
    ]]);
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_path' => '/health'])->save();
    deploy_http(['https://cache.example.com' => 503]);

    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('[redis] GET https://cache.example.com/')
        ->and(collect($GLOBALS['deploy_http_requests'])->pluck('url')->all())->toContain('https://'.$world->site->slug.'.falak.test/health');
});

it('checks a public service through its own domains and health check path', function () {
    $world = compose_world(site: ['public_services' => [
        ['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000],
        ['service' => 'redis', 'port' => 6379, 'domain' => 'cache.example.com', 'host_port' => 3001, 'health_check_path' => '/ping'],
    ]]);
    // Domain rows of the service (Edge): checked before the name kept in public_services and the test domain.
    $world->edge->domains["{$world->site->id}:redis"] = ['cache-2.example.com'];
    deploy_http(['https://cache-2.example.com/ping' => 404]);

    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    // A configured path must answer 2xx/3xx.
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('[redis] GET https://cache-2.example.com/ping')
        ->and(collect($GLOBALS['deploy_http_requests'])->pluck('url')->filter(fn ($url) => str_contains($url, 'cache.example.com'))->all())->toBe([]);
});

it('accepts a redirect from the primary public service (apps that redirect to a login page)', function () {
    $world = compose_world();
    deploy_http(['https://'.$world->site->slug.'.falak.test' => 302]);
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded);
});

it('writes a compose .env that compose reads literally', function () {
    expect(StepPayloads::composeDotenv(['A' => 'plain', 'B' => 'has space $HOME', 'C' => "it's\nmultiline"]))
        ->toBe("A=plain\nB='has space \$HOME'\nC=\"it's\\nmultiline\"\n");
});
