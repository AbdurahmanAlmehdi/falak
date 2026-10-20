<?php

use Falak\Fleet\Events\AgentServiceEventsReported;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Volumes\Domain\Models\Volume;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Viewer);
    $this->environment = projects_default_env($this->organization);
    $this->url = "/projects/{$this->environment->project_id}/production";
    config(['sites.test_domain' => 'falak.test']);
});

it('returns every service of the environment with live status, servers and reference edges', function () {
    $web1 = sites_server($this->organization->id, ['name' => 'web-1']);
    $web2 = sites_server($this->organization->id, ['name' => 'web-2']);
    sites_fake_agent_memory([$web1->id => 1]); // web-1 online, web-2 without agent

    [$database, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $shop = projects_site($this->organization, 'Storefront', ['DATABASE_URL' => '${{ shop.DATABASE_URL }}', 'API' => '${{ api.URL }}'], $this->environment, [$web1, $web2], ['test_domain_enabled' => true]);
    $api = projects_site($this->organization, 'Api', ['URL' => 'x', 'DB' => '${{ shop.DB_HOST }}'], $this->environment, [$web1], ['framework' => 'node', 'runtime' => 'bun', 'php_version' => null]);

    // Shop: succeeded 2 minutes ago; the older failure is superseded.
    projects_deployment($shop, 'failed', ['finished_at' => now()->subHour()]);
    $done = projects_deployment($shop, 'succeeded', ['finished_at' => now()->subMinutes(2)]);
    // Api: deploying, 2 of 3 forward steps done.
    $running = projects_deployment($api, 'deploying', ['phase' => 'prepare']);
    projects_steps($running, ['succeeded', 'skipped', 'running']);

    $serviceIds = Service::query()->pluck('id', 'ref_id');
    $engineServer = Server::query()->find($engine->server_id);

    $response = $this->getJson("{$this->url}/canvas")->assertOk();
    $storage = Volume::query()->where('name', "{$shop->slug}/storage")->firstOrFail();

    expect($response->json('services'))->toHaveCount(3)
        ->and($response->json('services.0'))->toBe([
            'id' => $serviceIds[$database->id],
            'kind' => 'database',
            'ref_id' => $database->id,
            'name' => 'shop',
            'custom_icon' => null,
            'position' => ['x' => 0, 'y' => 0],
            'group_id' => null,
            'icon' => 'postgresql',
            'status' => 'active',
            'status_label' => 'Active',
            'url' => null,
            'subtitle' => 'PostgreSQL 17 · 512 MB · '.$engineServer->name,
            'servers' => [['id' => $engineServer->id, 'name' => $engineServer->name, 'leader' => false, 'online' => false]],
            'badges' => [],
            'volumes' => [],
            'compose' => null,
            'last_deployment' => null,
        ])
        ->and($response->json('services.1'))->toBe([
            'id' => $serviceIds[$shop->id],
            'kind' => 'site',
            'ref_id' => $shop->id,
            'name' => 'Storefront',
            'custom_icon' => null,
            'position' => ['x' => 300, 'y' => 0],
            'group_id' => null,
            'icon' => 'laravel',
            'status' => 'active',
            'status_label' => 'Active · 2m ago',
            'url' => "https://{$shop->slug}.falak.test",
            'subtitle' => 'Laravel · PHP 8.4',
            'servers' => [
                ['id' => $web1->id, 'name' => 'web-1', 'leader' => true, 'online' => true],
                ['id' => $web2->id, 'name' => 'web-2', 'leader' => false, 'online' => false],
            ],
            'badges' => [],
            'volumes' => [['id' => $storage->id, 'name' => 'storage', 'detail' => 'shared', 'used_bytes' => null, 'limit_bytes' => null, 'url' => "/volumes/{$storage->id}"]],
            'compose' => null,
            'last_deployment' => [
                'id' => $done->id,
                'status' => 'succeeded',
                'commit' => 'bbbbbbb',
                'message' => 'Ship it',
                'finished_at' => $done->finished_at->toIso8601String(),
            ],
        ])
        ->and($response->json('services.2.icon'))->toBe('bun')
        ->and($response->json('services.2.status'))->toBe('deploying')
        ->and($response->json('services.2.status_label'))->toBe('Deploying 66%')
        ->and($response->json('services.2.last_deployment.finished_at'))->toBeNull()
        ->and(array_map(fn (array $edge) => array_diff_key($edge, ['problem' => 0]), $response->json('edges')))->toEqualCanonicalizing([
            ['from' => $serviceIds[$shop->id], 'to' => $serviceIds[$database->id], 'kind' => 'reference'],
            ['from' => $serviceIds[$shop->id], 'to' => $serviceIds[$api->id], 'kind' => 'reference'],
            ['from' => $serviceIds[$api->id], 'to' => $serviceIds[$database->id], 'kind' => 'reference'],
        ]);

    // The engine runs on an app server the sites don't run on: their host references don't resolve, and the edges
    // say why before a deploy fails on it. Keys without a host (none here) and site edges carry nothing.
    $problems = collect($response->json('edges'))->mapWithKeys(fn (array $edge) => ["{$edge['from']}>{$edge['to']}" => $edge['problem'] ?? null]);
    expect($problems["{$serviceIds[$shop->id]}>{$serviceIds[$database->id]}"])->toStartWith("DATABASE_URL: shop.DATABASE_URL cannot be used here: Storefront runs on web-1, web-2, which shares no private network with {$engineServer->name}")
        ->and($problems["{$serviceIds[$api->id]}>{$serviceIds[$database->id]}"])->toStartWith('DB: shop.DB_HOST cannot be used here: Api runs on web-1, which shares no private network')
        ->and($problems["{$serviceIds[$shop->id]}>{$serviceIds[$api->id]}"])->toBeNull();
});

it('flags references to a dedicated database server that shares no private network with the site (v0.9.0: never public)', function () {
    $dbServer = databases_server($this->organization, ServerType::Database, ['name' => 'db-1', 'provider' => 'hetzner']);
    [$database, , $instance] = projects_database($this->organization, 'shop', $this->environment, server: $dbServer);
    $web = sites_server($this->organization->id, ['name' => 'web-1', 'provider' => 'hetzner']);
    $site = projects_site($this->organization, 'Storefront', ['DB_HOST' => '${{ shop.DB_HOST }}', 'DB_DATABASE' => '${{ shop.DB_DATABASE }}'], $this->environment, [$web]);
    // Only the database name: nothing to resolve per server.
    $other = projects_site($this->organization, 'Reports', ['DB_DATABASE' => '${{ shop.DB_DATABASE }}'], $this->environment, [$web]);
    $serviceIds = Service::query()->pluck('id', 'ref_id');

    $edges = collect($this->getJson("{$this->url}/canvas")->assertOk()->json('edges'))->keyBy(fn (array $edge) => $edge['from']);

    expect($edges[$serviceIds[$site->id]]['problem'])
        ->toBe("DB_HOST: shop.DB_HOST cannot be used here: Storefront runs on web-1, which shares no private network with db-1, and PostgreSQL {$instance->name} on db-1 is never exposed on a public address for references. Add both servers to a private network (Network → Private networks).")
        ->and($edges[$serviceIds[$other->id]])->not->toHaveKey('problem');
});

it('flags no reference edge whose database host resolves', function () {
    $server = databases_server($this->organization, ServerType::App, ['name' => 'app-1']);
    [$database] = projects_database($this->organization, 'shop', $this->environment, server: $server);
    projects_site($this->organization, 'Storefront', ['DB_HOST' => '${{ shop.DB_HOST }}'], $this->environment, [$server]);

    $edges = $this->getJson("{$this->url}/canvas")->assertOk()->json('edges');

    expect($edges)->toHaveCount(1)->and($edges[0])->not->toHaveKey('problem');
});

it('derives statuses from targets and deployments', function (?string $deployment, TargetStatus $target, bool $servers, string $status, string $label) {
    $server = sites_server($this->organization->id);
    $site = projects_site($this->organization, 'Shop', [], $this->environment, $servers ? [$server] : [], targetStatus: $target);

    if ($deployment !== null) {
        projects_deployment($site, $deployment, ['finished_at' => now()->subMinutes(5)]);
    }

    $this->getJson("{$this->url}/canvas")
        ->assertJsonPath('services.0.status', $status)
        ->assertJsonPath('services.0.status_label', $label);
})->with([
    'never deployed' => [null, TargetStatus::Ready, true, 'inactive', 'Not deployed'],
    'no servers' => [null, TargetStatus::Ready, false, 'inactive', 'No servers'],
    'provisioning' => [null, TargetStatus::Provisioning, true, 'provisioning', 'Provisioning'],
    'target failed' => ['succeeded', TargetStatus::Failed, true, 'failed', 'Server setup failed'],
    'queued' => ['queued', TargetStatus::Ready, true, 'queued', 'Queued'],
    'building' => ['building', TargetStatus::Ready, true, 'building', 'Building'],
    'failed' => ['failed', TargetStatus::Ready, true, 'failed', 'Failed · 5m ago'],
    'cancelled' => ['cancelled', TargetStatus::Ready, true, 'inactive', 'Cancelled · 5m ago'],
]);

it('badges Laravel sites served by Octane', function () {
    $site = projects_site($this->organization, 'Shop', [], $this->environment, [sites_server($this->organization->id)]);

    $this->getJson("{$this->url}/canvas")->assertJsonPath('services.0.badges', []);

    $site->forceFill(['laravel' => ['octane' => true, 'octane_server' => 'frankenphp', 'octane_port' => 8100]])->save();

    $this->getJson("{$this->url}/canvas")->assertJsonPath('services.0.badges', ['Octane']);
});

it('shows pending databases as provisioning', function () {
    [$database] = projects_database($this->organization, 'shop', $this->environment);
    $database->forceFill(['status' => 'pending'])->save();

    $this->getJson("{$this->url}/canvas")
        ->assertJsonPath('services.0.status', 'provisioning')
        ->assertJsonPath('services.0.status_label', 'Creating');
});

it('accepts environment ids as well as slugs and 404s unknown environments', function () {
    $this->getJson("/projects/{$this->environment->project_id}/{$this->environment->id}/canvas")->assertOk()->assertExactJson(['services' => [], 'edges' => [], 'groups' => []]);
    $this->getJson("/projects/{$this->environment->project_id}/nope/canvas")->assertNotFound();
});

it('renders the canvas page with project, environment, canvas and panel props', function () {
    $site = projects_site($this->organization, 'Shop', [], $this->environment);

    $this->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Canvas', false)
        ->where('project.id', $this->environment->project_id)
        ->where('project.is_default', true)
        ->where('environment.slug', 'production')
        ->where('environment.is_production', true)
        ->has('canvas.services', 1)
        ->has('canvas.edges', 0)
        ->where('panel', null)
        ->where('can.manage', false)
        ->where('falak.current.project_id', $this->environment->project_id)
        ->where('falak.current.environment_id', $this->environment->id));

    $this->get("{$this->url}/service/site/{$site->id}/variables")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Canvas', false)
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => 'variables', 'item' => null]));

    $this->get("{$this->url}/service/site/{$site->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => null, 'item' => null]));

    $this->get("{$this->url}/service/site/{$site->id}/deployments/01JDEPLOYMENT0000000000000")->assertOk()->assertInertia(fn ($page) => $page
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => 'deployments', 'item' => '01jdeployment0000000000000']));

    $this->get("{$this->url}/service/database/{$site->id}")->assertNotFound();
});

it('opens the production canvas for a project url and serves project JSON', function () {
    $this->get("/projects/{$this->environment->project_id}")->assertRedirect($this->url);
    $this->getJson("/projects/{$this->environment->project_id}")->assertOk()
        ->assertJsonPath('data.name', 'Default')
        ->assertJsonPath('data.environments.0.slug', 'production')
        ->assertJsonPath('data.environments.0.services_count', 0);
});

it('shows compose sites as "Compose · N services" and crashed when a service is down', function () {
    $web = sites_server($this->organization->id, ['name' => 'web-1'], docker: true);
    $site = projects_site($this->organization, 'Stack', [], $this->environment, [$web], [
        'framework' => 'docker', 'runtime' => 'compose', 'build_mode' => 'docker', 'php_version' => null, 'compose_source' => 'inline',
    ]);
    ComposeVersion::query()->create(['site_id' => $site->id, 'version' => 1, 'content' => "services:\n  app: {image: a:1}\n  db: {image: b:1}\n  cache: {image: c:1}\n", 'created_at' => now()]);

    expect($this->getJson("{$this->url}/canvas")->json('services.0'))->toMatchArray(['icon' => 'compose', 'subtitle' => 'Compose · 3 services', 'status' => 'inactive']);

    projects_deployment($site, 'succeeded', ['finished_at' => now()->subMinute()]);
    app(ComposeSites::class)->recordStatus($site->id, $web->id, [
        ['service' => 'app', 'state' => 'running', 'health' => 'healthy', 'image' => 'a:1', 'restarts' => 0],
        ['service' => 'db', 'state' => 'running', 'image' => 'b:1', 'restarts' => 0],
        ['service' => 'cache', 'state' => 'exited', 'image' => 'c:1', 'restarts' => 5],
    ]);

    expect($this->getJson("{$this->url}/canvas")->json('services.0'))->toMatchArray(['status' => 'crashed', 'status_label' => '2/3 services healthy · cache down']);
});

it('shows a site’s limits in its card subtitle and OOM kills as a badge', function () {
    $web = sites_server($this->organization->id, ['name' => 'web-1']);
    $site = projects_site($this->organization, 'Storefront', [], $this->environment, [$web], ['runtime' => 'php-fpm', 'limits' => ['memory_limit' => 512, 'cpus' => 1]]);
    AgentServiceEventsReported::dispatch('agent', $this->organization->id, $web->id, [
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => 'site_'.str_replace('-', '_', $site->slug), 'site' => null, 'project' => null, 'service' => null, 'instance' => null, 'count' => 1, 'at' => now()->toIso8601String()],
    ]);

    expect($this->getJson("{$this->url}/canvas")->json('services.0'))->toMatchArray(['subtitle' => 'Laravel · PHP 8.4 · 512 MB · 1 CPU', 'badges' => ['OOM killed']]);
});
