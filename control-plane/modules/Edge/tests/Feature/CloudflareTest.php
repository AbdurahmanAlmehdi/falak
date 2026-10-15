<?php

use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\RemoveDomain;
use Falak\Edge\Application\CloudflareConnections;
use Falak\Edge\Application\CloudflareDns;
use Falak\Edge\Application\CloudflareEdgeControls;
use Falak\Edge\Application\CloudflareTunnels;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\DnsInstructions;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Application\Jobs\ReconcileCloudflareTunnels;
use Falak\Edge\Contracts\CloudflareRanges;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsRecord;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\OrganizationSetting;
use Falak\Edge\Tests\Support\FakeCloudflare;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\Data\AgentUpgradeData;
use Falak\Fleet\Contracts\Data\AgentVersionInfo;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Contracts\WebOriginPolicy;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Infrastructure\FirewallCompiler;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\Data\DomainChoice;
use Falak\Sites\Contracts\Data\PublicService;
use Falak\Sites\Contracts\DomainType;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Cloudflare integration: connection, managed zones, DNS records Falak creates / updates / removes (its own only),
 * DNS-01 certificates in managed zones, generated names under a zone and trusted proxies. Cloudflare is faked.
 */

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    $this->cf = FakeCloudflare::install();
    $this->zoneId = $this->cf->zone('example.com');
    $this->org = strtolower((string) Str::ulid());
    $this->web1 = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-1', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10']);
    $this->web2 = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-2', 'ipv4' => '203.0.113.11']);
    $this->site = edge_site($this->sites, $this->org, [$this->web1->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'shop']);
    $this->connections = app(CloudflareConnections::class);
});

function cf_connect(object $test, bool $proxied = true): void
{
    $credential = $test->connections->connect($test->org, 'Cloudflare', $test->cf->validToken, null);
    $test->zone = $test->connections->enable($credential, $test->zoneId, $proxied);
}

it('connects a token after verifying it, and rejects a token Cloudflare refuses', function () {
    expect(fn () => $this->connections->connect($this->org, 'Cloudflare', 'wrong-token-0123456789', null))
        ->toThrow(ValidationException::class);

    $credential = $this->connections->connect($this->org, 'Cloudflare', $this->cf->validToken, null);

    expect($credential->provider)->toBe('cloudflare')
        ->and($credential->account_id)->toBe('acc-1')
        ->and($credential->verified_at)->not->toBeNull()
        ->and($credential->getRawOriginal('api_token'))->not->toBe($this->cf->validToken) // encrypted at rest
        ->and(array_column($this->connections->zones($credential), 'name'))->toBe(['example.com']);
});

it('creates tagged A and AAAA records for a domain in a managed zone', function () {
    cf_connect($this);

    $domain = app(AddDomain::class)($this->site, 'shop.example.com', www: WwwRedirect::ToApex);

    $records = collect($this->cf->recordsOf($this->zoneId));
    expect($records->map(fn ($r) => "{$r['type']} {$r['name']} {$r['content']}")->sort()->values()->all())->toBe([
        'A shop.example.com 203.0.113.10',
        'A www.shop.example.com 203.0.113.10',
        'AAAA shop.example.com 2001:db8::10',
        'AAAA www.shop.example.com 2001:db8::10',
    ])
        ->and($records->every(fn ($r) => $r['proxied'] === true && str_starts_with($r['comment'], "falak:{$domain->id}")))->toBeTrue()
        ->and(DnsRecord::query()->where('status', DnsRecord::SYNCED)->count())->toBe(4)
        // Let's Encrypt HTTP-01 goes through Cloudflare's proxy; no DNS plugin needed on the servers.
        ->and($domain->refresh()->tls_mode)->toBe(TlsMode::Auto);
});

it('leaves domains outside managed zones alone', function () {
    cf_connect($this);

    $domain = app(AddDomain::class)($this->site, 'shop.other.org');

    expect($this->cf->recordsOf($this->zoneId))->toBe([])
        ->and($domain->tls_mode)->toBe(TlsMode::Auto);
});

it('deletes only its own records when a domain is removed', function () {
    cf_connect($this);
    $theirs = $this->cf->put($this->zoneId, ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com']);
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');
    expect($this->cf->recordsOf($this->zoneId))->toHaveCount(3);

    app(RemoveDomain::class)($domain);

    expect(array_keys($this->cf->records[$this->zoneId]))->toBe([$theirs])
        ->and(DnsRecord::query()->count())->toBe(0);
});

it('reports a record it did not create as a conflict and never overwrites it', function () {
    cf_connect($this);
    $this->cf->put($this->zoneId, ['type' => 'A', 'name' => 'shop.example.com', 'content' => '198.51.100.7']);

    app(AddDomain::class)($this->site, 'shop.example.com');

    $record = DnsRecord::query()->where('type', 'A')->sole();
    expect($record->status)->toBe(DnsRecord::CONFLICT)
        ->and($record->error)->toContain('198.51.100.7')
        ->and(collect($this->cf->recordsOf($this->zoneId))->where('type', 'A')->pluck('content')->all())->toBe(['198.51.100.7']);
});

it('follows the site to new servers and drops the records of removed ones', function () {
    cf_connect($this);
    app(AddDomain::class)($this->site, 'shop.example.com');

    edge_site($this->sites, $this->org, [$this->web2->id], ['id' => $this->site->id, 'slug' => 'shop']);
    SiteTargetsChanged::dispatch($this->site->id, $this->org, [$this->web2->id], [$this->web1->id], [$this->web2->id], $this->web2->id);

    expect(collect($this->cf->recordsOf($this->zoneId))->map(fn ($r) => "{$r['type']} {$r['content']}")->all())->toBe(['A 203.0.113.11']);
});

it('switches one domain between proxied and DNS only', function () {
    cf_connect($this);
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');

    $domain->forceFill(['cloudflare_proxied' => false])->save();
    app(CloudflareDns::class)->sync($domain);

    expect(collect($this->cf->recordsOf($this->zoneId))->pluck('proxied')->unique()->all())->toBe([false]);
});

it('generates names under the zone and never manages the panel or agent hosts', function () {
    cf_connect($this);
    OrganizationSetting::for($this->org)->forceFill(['generated_domain_provider' => GeneratedDomains::CLOUDFLARE.'example.com'])->save();

    $name = app(SiteDomains::class)->resolveChoice($this->org, new DomainChoice(DomainType::Generated, null), 'shop-staging', [$this->web1->id], 'domain');
    expect($name)->toBe('shop-staging.example.com');

    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop-staging.example.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    expect(app(SiteDomains::class)->resolveChoice($this->org, new DomainChoice(DomainType::Generated, null), 'shop-staging', [$this->web1->id], 'domain'))
        ->toBe('shop-staging-2.example.com');

    config(['fleet.api_url' => 'https://agents.example.com/agent/v1']);
    app(AddDomain::class)($this->site, 'agents.example.com');
    expect(collect($this->cf->recordsOf($this->zoneId))->pluck('name')->all())->not->toContain('agents.example.com');
});

it('says Falak manages the records in the DNS instructions', function () {
    $instructions = DnsInstructions::for('shop.example.com', [], managedZone: 'example.com');

    expect($instructions['managed_by'])->toBe(['provider' => 'cloudflare', 'zone' => 'example.com'])
        ->and($instructions['notes'][0])->toContain('nothing to add by hand');
});

it('trusts Cloudflare for the client IP once a zone is managed', function () {
    app(AddDomain::class)($this->site, 'shop.example.com');
    expect(edge_compile($this->web1->id))->not->toHaveKey('trusted_proxies');

    cf_connect($this);

    expect(edge_compile($this->web1->id)['trusted_proxies'])->toBe(CloudflareRanges::RANGES);
});

it('checks and fixes the zone TLS settings', function () {
    cf_connect($this);

    expect($this->connections->health($this->zone))->toBe([
        'ssl' => ['value' => 'full', 'recommended' => 'strict', 'ok' => false],
        'min_tls_version' => ['value' => '1.0', 'recommended' => '1.2', 'ok' => false],
        'always_use_https' => ['value' => 'off', 'recommended' => 'off', 'ok' => true],
    ]);

    $this->connections->applyRecommended($this->zone, 'ssl');
    $this->connections->applyRecommended($this->zone, 'min_tls_version');

    expect(collect($this->connections->health($this->zone))->every(fn ($s) => $s['ok']))->toBeTrue();
});

it('releases a zone and keeps or deletes its records', function () {
    cf_connect($this);
    app(AddDomain::class)($this->site, 'shop.example.com');

    $this->connections->disable($this->zone, deleteRecords: true);

    expect($this->cf->recordsOf($this->zoneId))->toBe([])
        ->and(CloudflareZone::query()->count())->toBe(0);
});

it('does not send trusted proxies to agents older than 0.3.0 (they reject unknown fields)', function () {
    app(AddDomain::class)($this->site, 'shop.example.com');
    cf_connect($this);
    app()->instance(AgentUpgrades::class, new class implements AgentUpgrades
    {
        public string $version = 'v0.2.8';

        public function versionsFor(array $serverIds): array
        {
            return array_combine($serverIds, array_map(fn ($id) => new AgentVersionInfo($id, $this->version, 'v0.3.0', true), $serverIds));
        }

        public function upgrade(string $serverId, ?string $userId = null): AgentUpgradeData
        {
            throw new RuntimeException('unused');
        }

        public function upgradeOrganization(string $organizationId, ?string $userId = null, ?array $serverIds = null): array
        {
            return [];
        }

        public function outdatedCount(?string $organizationId = null): int
        {
            return 0;
        }
    });

    expect(edge_compile($this->web1->id))->not->toHaveKey('trusted_proxies');

    app(AgentUpgrades::class)->version = 'v0.3.0';
    expect(edge_compile($this->web1->id))->toHaveKey('trusted_proxies');
});

it('makes a compose site’s public service domains domain rows with records, and removes them with the site', function () {
    cf_connect($this);
    $compose = new ComposeConfig(
        ComposeSource::Inline, null,
        [new PublicService('web', 80, 'draw.example.com', 3000), new PublicService('api', 9000, 'api.example.com', 3001)],
    );
    $site = edge_site($this->sites, $this->org, [$this->web1->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'draw', 'runtime' => SiteRuntime::Compose, 'compose' => $compose]);

    SiteCreated::dispatch($site->id, $this->org, $site->slug, 'compose', [$this->web1->id]);

    // The primary service's domain is the site's (service null), the other one the service's: both get cache modes,
    // proxy overrides and records like any domain.
    expect(Domain::query()->where('site_id', $site->id)->orderBy('name')->get(['name', 'compose_service', 'is_primary'])->toArray())->toBe([
        ['name' => 'api.example.com', 'compose_service' => 'api', 'is_primary' => true],
        ['name' => 'draw.example.com', 'compose_service' => null, 'is_primary' => true],
    ]);
    $records = collect($this->cf->recordsOf($this->zoneId));
    expect($records->map(fn ($r) => "{$r['type']} {$r['name']}")->sort()->values()->all())->toBe(['A api.example.com', 'A draw.example.com', 'AAAA api.example.com', 'AAAA draw.example.com'])
        ->and($records->every(fn ($r) => ! str_starts_with($r['comment'], 'falak:site:')))->toBeTrue();

    SiteDeleted::dispatch($site->id, $this->org, $site->slug, [$this->web1->id]);

    expect($this->cf->recordsOf($this->zoneId))->toBe([]);
});

it('moves the records Falak created for a compose site to the imported domain row in place', function () {
    cf_connect($this);
    $site = edge_site($this->sites, $this->org, [$this->web1->id], [
        'id' => strtolower((string) Str::ulid()), 'slug' => 'draw', 'runtime' => SiteRuntime::Compose,
        'compose' => new ComposeConfig(ComposeSource::Inline, null, [new PublicService('web', 80, null, 3000), new PublicService('api', 9000, 'api.example.com', 3001)]),
    ]);
    // Before per-service domains: the name was the site's (records tagged falak:site:<id>).
    app(CloudflareDns::class)->syncSite($site->id);
    $before = collect($this->cf->recordsOf($this->zoneId));
    expect($before)->toHaveCount(2)->and($before->every(fn ($r) => str_starts_with($r['comment'], "falak:site:{$site->id}")))->toBeTrue();

    app(ComposeServiceDomains::class)->import($site->id);
    app(CloudflareDns::class)->syncSite($site->id);

    $domain = Domain::query()->where('name', 'api.example.com')->firstOrFail();
    $after = collect($this->cf->recordsOf($this->zoneId));
    expect($domain->compose_service)->toBe('api')
        // Same Cloudflare records (ids kept), now tagged with the domain row.
        ->and($after->pluck('id')->sort()->values()->all())->toBe($before->pluck('id')->sort()->values()->all())
        ->and($after->every(fn ($r) => str_starts_with($r['comment'], "falak:{$domain->id}")))->toBeTrue()
        ->and(DnsRecord::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('asks for HTTP-01 only for hosts in a managed zone', function () {
    app(AddDomain::class)($this->site, 'shop.example.com');
    app(AddDomain::class)($this->site, 'shop.other.org');
    cf_connect($this);

    $tls = collect(edge_compile($this->web1->id)['sites'])->mapWithKeys(fn ($s) => [$s['domains'][0] => $s['tls'] ?? null]);
    expect($tls['shop.example.com'])->toBe(['mode' => 'acme', 'http_challenge_only' => true])
        ->and($tls['shop.other.org'])->toBe(['mode' => 'acme']);
});

it('routes a server through a Cloudflare Tunnel and back', function () {
    ['agents' => $agents] = ['agents' => app(AgentGateway::class)];
    cf_connect($this);
    $this->web1 = $this->servers->put(new ServerData(...[...get_object_vars($this->web1), 'arch' => 'arm64']));
    app(AddDomain::class)($this->site, 'shop.example.com');
    $credential = $this->zone->credential;

    $tunnel = app(CloudflareTunnels::class)->enable($this->web1->id, $credential);

    // cloudflared: the arm64 build of the pinned release, checksum and token passed to the agent (schema-validated).
    $install = $agents->ofType('net.tunnel.apply', $this->web1->id)[0]['payload'];
    expect($install)->toMatchArray(['state' => 'present', 'version' => config('edge.cloudflared.version'), 'sha256' => config('edge.cloudflared.sha256.arm64'), 'token' => "token-for-{$tunnel->tunnel_id}"])
        ->and($install['url'])->toEndWith('/cloudflared-linux-arm64');

    // DNS stays on the server's addresses until cloudflared reports it is running (a failed install cuts nothing).
    expect(collect($this->cf->recordsOf($this->zoneId))->pluck('type')->sort()->values()->all())->toBe(['A', 'AAAA']);

    // Routes: Let's Encrypt's HTTP-01 path to :80, everything else to Caddy on :443 with the name as SNI.
    expect($this->cf->tunnels[$tunnel->tunnel_id]['ingress'])->toBe([
        ['hostname' => 'shop.example.com', 'path' => '^/\.well-known/acme-challenge/', 'service' => 'http://localhost:80'],
        ['hostname' => 'shop.example.com', 'service' => 'https://localhost:443', 'originRequest' => ['originServerName' => 'shop.example.com', 'httpHostHeader' => 'shop.example.com']],
        ['service' => 'http_status:404'],
    ])
        ->and(edge_compile($this->web1->id)['trusted_proxies'])->toContain('127.0.0.1/32');

    CommandFinished::dispatch($tunnel->refresh()->command_id, $this->org, $this->web1->id, 'net.tunnel.apply', 'k', 0, ['changed' => true, 'active' => true, 'version' => '2026.9.3']);

    // Running: one proxied CNAME to the tunnel instead of the A / AAAA records.
    expect(collect($this->cf->recordsOf($this->zoneId))->map(fn ($r) => "{$r['type']} {$r['name']} {$r['content']} ".($r['proxied'] ? 'proxied' : 'dns-only'))->all())
        ->toBe(["CNAME shop.example.com {$tunnel->tunnel_id}.cfargotunnel.com proxied"]);
    expect($tunnel->refresh()->status)->toBe(CloudflareTunnel::ACTIVE)
        ->and(app(CloudflareTunnels::class)->health($tunnel))->toBe(['status' => 'healthy', 'connections' => 4]);

    app(CloudflareTunnels::class)->disable($tunnel);

    expect(collect($agents->ofType('net.tunnel.apply', $this->web1->id))->last()['payload'])->toBe(['state' => 'absent'])
        ->and($this->cf->tunnels)->toBe([])
        ->and(collect($this->cf->recordsOf($this->zoneId))->pluck('type')->sort()->values()->all())->toBe(['A', 'AAAA'])
        ->and(edge_compile($this->web1->id)['trusted_proxies'])->not->toContain('127.0.0.1/32');
});

it('sets a domain’s cache mode with Cache Rules, keeping the zone’s other rules', function () {
    cf_connect($this);
    $this->cf->cacheRules[$this->zoneId] = [['id' => 'theirs', 'description' => 'their rule', 'expression' => '(http.request.uri.path contains "/api")', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false], 'enabled' => true]];
    $domain = app(AddDomain::class)($this->site, 'shop.example.com', www: WwwRedirect::ToApex);
    $controls = app(CloudflareEdgeControls::class);

    $controls->setCacheMode($domain, 'everything');
    $rules = $this->cf->cacheRules[$this->zoneId];
    expect(array_column($rules, 'description'))->toBe(['their rule', "falak:cache:{$domain->id} shop.example.com"])
        ->and($rules[1]['expression'])->toBe('(http.host in {"shop.example.com" "www.shop.example.com"})')
        ->and($rules[1]['action_parameters']['cache'])->toBeTrue()
        ->and($rules[1]['action_parameters']['edge_ttl']['mode'])->toBe('override_origin');

    $controls->setCacheMode($domain->refresh(), 'bypass');
    expect($this->cf->cacheRules[$this->zoneId][1]['action_parameters'])->toBe(['cache' => false]);

    $controls->setCacheMode($domain->refresh(), 'standard');
    expect(array_column($this->cf->cacheRules[$this->zoneId], 'description'))->toBe(['their rule']);
});

it('purges a site’s names at Cloudflare after a deploy or a rollback', function () {
    cf_connect($this);
    app(AddDomain::class)($this->site, 'shop.example.com', www: WwwRedirect::ToWww);
    app(AddDomain::class)($this->site, 'shop.other.org');

    DeploymentSucceeded::dispatch('dep-1', $this->org, $this->site->id, 'manual', null, 'rel-1', [$this->web1->id], 1000);

    expect($this->cf->purges)->toHaveCount(1)
        ->and($this->cf->purges[0]['hosts'])->toEqualCanonicalizing(['shop.example.com', 'www.shop.example.com']);
});

it('turns Under Attack mode on and restores the previous level', function () {
    cf_connect($this);
    $this->cf->settings[$this->zoneId]['security_level'] = 'high';
    $controls = app(CloudflareEdgeControls::class);

    $controls->underAttack($this->zone, true);
    expect($this->cf->settings[$this->zoneId]['security_level'])->toBe('under_attack')
        ->and($this->zone->refresh()->security_level_before)->toBe('high');

    $controls->underAttack($this->zone, false);
    expect($this->cf->settings[$this->zoneId]['security_level'])->toBe('high')
        ->and($this->zone->refresh()->security_level_before)->toBeNull();
});

it('locks a server’s web ports to Cloudflare, or closes them behind a running tunnel', function () {
    cf_connect($this);
    foreach ([['SSH', '22', 'tcp'], ['HTTP', '80', 'tcp'], ['HTTPS', '443', 'tcp'], ['Anything from the office', null, 'any']] as $i => [$name, $port, $protocol]) {
        FirewallRule::query()->create(['organization_id' => $this->org, 'server_id' => $this->web1->id, 'name' => $name, 'action' => 'allow', 'protocol' => $protocol, 'port' => $port, 'source' => $port === null ? '198.51.100.0/24' : null, 'position' => $i, 'is_default' => $port !== null]);
    }
    // [id-or-port => rule]; explicit web rules come first, before the server's own rules.
    $firewall = fn () => collect(app(FirewallCompiler::class)->compile($this->web1->id)['rules'])
        ->map(fn ($r) => ['key' => str_starts_with((string) $r['id'], 'web-origin') ? $r['id'] : ($r['ports'][0] ?? 'all'), ...$r])->values();
    $keys = fn () => $firewall()->pluck('key')->all();
    $controls = app(CloudflareEdgeControls::class);

    $controls->lock($this->org, $this->web1->id, 'cloudflare');
    expect($keys())->toBe(['web-origin-allow', 'web-origin-drop', '22', 'all'])
        ->and($firewall()[0]['sources'])->toBe(CloudflareRanges::RANGES)
        ->and($firewall()[1])->toMatchArray(['action' => 'drop', 'ports' => ['80', '443']]);

    // Closing needs a running tunnel; then even the all-ports rule cannot reach 80 / 443 (the drop comes first).
    expect(fn () => $controls->lock($this->org, $this->web1->id, 'closed'))->toThrow(ValidationException::class);
    $tunnel = app(CloudflareTunnels::class)->enable($this->web1->id, $this->zone->credential);
    $tunnel->forceFill(['status' => CloudflareTunnel::ACTIVE])->save();
    $controls->lock($this->org, $this->web1->id, 'closed');
    expect($keys())->toBe(['web-origin-drop', '22', 'all']);

    // The tunnel stops: closed falls back to Cloudflare-only.
    $tunnel->forceFill(['status' => CloudflareTunnel::ERROR])->save();
    expect($keys())->toBe(['web-origin-allow', 'web-origin-drop', '22', 'all']);

    // Leaving the tunnel opens the web ports again.
    app(CloudflareTunnels::class)->disable($tunnel->refresh());
    expect($keys())->toBe(['22', '80', '443', 'all']);
});

it('marks a tunnel Cloudflare reports down, and brings it back when healthy', function () {
    cf_connect($this);
    $tunnel = app(CloudflareTunnels::class)->enable($this->web1->id, $this->zone->credential);
    $tunnel->forceFill(['status' => CloudflareTunnel::ACTIVE])->save();
    app(CloudflareEdgeControls::class)->lock($this->org, $this->web1->id, 'closed');

    $this->cf->tunnelStatus = 'down';
    (new ReconcileCloudflareTunnels)->handle(app(CloudflareTunnels::class), app(Firewalls::class));
    expect($tunnel->refresh()->status)->toBe(CloudflareTunnel::ERROR)
        ->and($tunnel->error)->toContain('down')
        ->and(app(WebOriginPolicy::class)->for($this->web1->id)['mode'])->toBe('only');

    $this->cf->tunnelStatus = 'healthy';
    (new ReconcileCloudflareTunnels)->handle(app(CloudflareTunnels::class), app(Firewalls::class));
    expect($tunnel->refresh()->status)->toBe(CloudflareTunnel::ACTIVE)
        ->and(app(WebOriginPolicy::class)->for($this->web1->id)['mode'])->toBe('closed');
});
