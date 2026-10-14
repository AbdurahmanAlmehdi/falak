<?php

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Domain\Models\PathRateLimit;
use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;

/*
 * Path rate limits at the edge (docs/plans/network/protection.md): rendered only for sites with rules (every other
 * route byte for byte the same), Falak's Caddy build sent along, refused where the edge cannot enforce them.
 */

require_once __DIR__.'/../Support/helpers.php';

function rl_agents(array $features): void
{
    app()->instance(AgentDirectory::class, new class($features) implements AgentDirectory
    {
        /** @param array<string, list<string>> $features server id => features */
        public function __construct(private array $features) {}

        public function forServer(string $serverId): ?AgentInfo
        {
            return isset($this->features[$serverId]) ? new AgentInfo('a-'.$serverId, $serverId, AgentStatus::Online, 'v0.17.0', 'h', 'amd64',
                ['features' => $this->features[$serverId]], [], new DateTimeImmutable, null, null) : null;
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

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $org = $this->organization->id;
    $this->web = edge_server($this->servers, $org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-1', 'phpRuntime' => 'fpm', 'arch' => 'x86_64']);
    $this->franken = edge_server($this->servers, $org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-2', 'phpRuntime' => 'frankenphp']);
    $this->shop = edge_site($this->sites, $org, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'shop', 'name' => 'Shop']);
    $this->blog = edge_site($this->sites, $org, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'blog', 'name' => 'Blog']);
    $this->app2 = edge_site($this->sites, $org, [$this->franken->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'app2', 'name' => 'App 2']);
    rl_agents([$this->web->id => ['edge.rate_limit'], $this->franken->id => ['edge.rate_limit']]);
    app(AddDomain::class)($this->shop, 'shop.example.com');
    app(AddDomain::class)($this->blog, 'blog.example.com');

    $dir = sys_get_temp_dir().'/falak-caddy-builds-'.Str::random(6);
    @mkdir($dir);
    file_put_contents("{$dir}/falak-caddy-linux-amd64", 'caddy');
    file_put_contents("{$dir}/falak-caddy-linux-amd64.sha256", hash('sha256', 'caddy')."  falak-caddy-linux-amd64\n");
    config(['edge.caddy_builds_path' => $dir, 'fleet.panel_url' => 'https://cp.example.com']);
});

it('renders rules only for the site that has them and sends the build with them', function () {
    $before = edge_compile($this->web->id);
    expect($before)->not->toHaveKey('caddy_build')
        ->and(json_encode($before))->not->toContain('rate_limits');

    $this->post('/network/rate-limits', ['site_id' => $this->shop->id, 'path' => '/login', 'events' => 10, 'window_s' => 60])->assertSessionHasNoErrors();
    $this->post('/network/rate-limits', ['site_id' => $this->shop->id, 'path' => '/api/*', 'events' => 600, 'window_s' => 60])->assertSessionHasNoErrors();

    $after = edge_compile($this->web->id);
    $shop = edge_entry($after, strtolower($this->shop->id));
    expect($shop['rate_limits'])->toBe([['path' => '/api/*', 'events' => 600, 'window_s' => 60], ['path' => '/login', 'events' => 10, 'window_s' => 60]])
        ->and(json_encode(edge_entry($after, strtolower($this->blog->id))))->toBe(json_encode(edge_entry($before, strtolower($this->blog->id))))
        ->and($after['caddy_build'])->toBe(['url' => 'https://cp.example.com/install/edge/caddy-linux-amd64', 'sha256' => hash('sha256', 'caddy')]);

    // Older agents get neither field.
    $old = PayloadCompatibility::adapt('edge.caddy.apply', json_decode(json_encode($after)), []);
    expect(json_encode($old))->not->toContain('rate_limits')->not->toContain('caddy_build');

    expect(AuditEntry::query()->where('action', 'edge.rate_limit_saved')->count())->toBe(2);

    // Removing the last rule removes the build too.
    foreach (PathRateLimit::query()->get() as $limit) {
        $this->delete("/network/rate-limits/{$limit->id}")->assertSessionHasNoErrors();
    }
    expect(edge_compile($this->web->id))->toBe($before);
});

it('validates rules and refuses sites whose edge cannot enforce them', function () {
    foreach ([['path' => 'login'], ['path' => '/a b'], ['path' => '/{http.request.uri}'], ['path' => '/*/x'], ['events' => 0], ['window_s' => 86401]] as $bad) {
        $this->post('/network/rate-limits', [...['site_id' => $this->shop->id, 'path' => '/login', 'events' => 10, 'window_s' => 60], ...$bad])
            ->assertSessionHasErrors(array_keys($bad));
    }

    $this->post('/network/rate-limits', ['site_id' => $this->app2->id, 'path' => '/login', 'events' => 10, 'window_s' => 60])
        ->assertSessionHasErrors('site_id');
    expect(session('errors')->first('site_id'))->toContain('FrankenPHP');

    rl_agents([$this->web->id => []]);
    $this->post('/network/rate-limits', ['site_id' => $this->shop->id, 'path' => '/login', 'events' => 10, 'window_s' => 60])
        ->assertSessionHasErrors('site_id');
    expect(session('errors')->first('site_id'))->toContain('Update the agent');

    expect(PathRateLimit::query()->count())->toBe(0);
});

it('keeps rules to the organization and to edge.manage', function () {
    $other = Organization::factory()->create();
    $foreign = edge_site($this->sites, $other->id, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'foreign']);
    $this->post('/network/rate-limits', ['site_id' => $foreign->id, 'path' => '/login', 'events' => 10, 'window_s' => 60])->assertNotFound();

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->post('/network/rate-limits', ['site_id' => $this->shop->id, 'path' => '/login', 'events' => 10, 'window_s' => 60])->assertForbidden();
    $this->actingAs($viewer)->get('/network/rate-limits')->assertOk()->assertInertia(fn ($page) => $page->component('Edge/RateLimits', false)
        ->where('can.manage', false)
        ->where('sites', fn ($sites) => collect($sites)->firstWhere('name', 'App 2')['refusal'] !== null));
});

it('serves the build publicly with its checksum', function () {
    auth()->logout();
    $this->get('/install/edge/caddy-linux-amd64')->assertOk()->assertHeader('X-Checksum-Sha256', hash('sha256', 'caddy'));
    $this->get('/install/edge/caddy-linux-arm64')->assertNotFound();
    $this->get('/install/edge/caddy-linux-mips')->assertNotFound();
});
