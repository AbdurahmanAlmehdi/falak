<?php

use Falak\Edge\Application\CloudflareAnalytics;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\Role;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Cloudflare traffic analytics (docs/plans/network/tools.md part 2): GraphQL Analytics API with the zone's connection
 * token, degraded per dataset, cached. Cloudflare is faked; nothing here reaches the real API.
 */

require_once __DIR__.'/../Support/helpers.php';

const CF_ANALYTICS_TOKEN = 'cf-analytics-secret-token-0123456789';

const CF_OTHER_TOKEN = 'cf-other-organization-token-987654321';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->org = $this->organization->id;
    $this->web = edge_server($this->servers, $this->org);
    $this->site = edge_site($this->sites, $this->org, [$this->web->id]);
    $this->url = "/sites/{$this->site->id}/cloudflare/analytics";

    $this->credential = DnsCredential::query()->create([
        'organization_id' => $this->org, 'provider' => 'cloudflare', 'name' => 'Main account', 'api_token' => CF_ANALYTICS_TOKEN, 'account_id' => 'acc-1', 'verified_at' => now(),
    ]);
    $this->zone = CloudflareZone::query()->create([
        'organization_id' => $this->org, 'dns_credential_id' => $this->credential->id, 'zone_id' => 'zone-abc', 'name' => 'example.com', 'proxied' => true,
    ]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop.example.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    // What the fake answers, per query (operation name) — a closure gets the request.
    $this->settings = [
        'httpRequestsAdaptiveGroups' => ['enabled' => true, 'maxDuration' => 86400, 'notOlderThan' => 2678400],
        'firewallEventsAdaptiveGroups' => ['enabled' => true, 'maxDuration' => 259200, 'notOlderThan' => 2678400],
        'httpRequests1dGroups' => ['enabled' => true, 'maxDuration' => 31536000, 'notOlderThan' => 31536000],
    ];
    $this->answers = [
        'FalakAnalyticsSettings' => fn () => cf_data(['settings' => $this->settings]),
        'FalakTraffic' => fn () => cf_data([
            'cache' => [
                ['count' => 600, 'sum' => ['edgeResponseBytes' => 6000, 'visits' => 50], 'dimensions' => ['cacheStatus' => 'hit']],
                ['count' => 300, 'sum' => ['edgeResponseBytes' => 9000, 'visits' => 40], 'dimensions' => ['cacheStatus' => 'dynamic']],
                ['count' => 100, 'sum' => ['edgeResponseBytes' => 1000, 'visits' => 10], 'dimensions' => ['cacheStatus' => 'stale']],
            ],
            'countries' => [
                ['count' => 700, 'dimensions' => ['clientCountryName' => 'DE']],
                ['count' => 300, 'dimensions' => ['clientCountryName' => 'LY']],
            ],
            'statuses' => [
                ['count' => 900, 'dimensions' => ['edgeResponseStatus' => 200]],
                ['count' => 60, 'dimensions' => ['edgeResponseStatus' => 404]],
                ['count' => 30, 'dimensions' => ['edgeResponseStatus' => 301]],
                ['count' => 10, 'dimensions' => ['edgeResponseStatus' => 502]],
            ],
            'series' => [
                ['count' => 400, 'dimensions' => ['datetimeHour' => '2026-10-15T10:00:00Z', 'cacheStatus' => 'hit']],
                ['count' => 100, 'dimensions' => ['datetimeHour' => '2026-10-15T10:00:00Z', 'cacheStatus' => 'miss']],
                ['count' => 300, 'dimensions' => ['datetimeHour' => '2026-10-15T11:00:00Z', 'cacheStatus' => 'stale']],
                ['count' => 200, 'dimensions' => ['datetimeHour' => '2026-10-15T11:00:00Z', 'cacheStatus' => 'dynamic']],
            ],
        ]),
        'FalakThreats' => fn () => cf_data([
            'actions' => [
                ['count' => 40, 'dimensions' => ['action' => 'block']],
                ['count' => 10, 'dimensions' => ['action' => 'managed_challenge']],
                ['count' => 5, 'dimensions' => ['action' => 'log']],
            ],
            'countries' => [['count' => 50, 'dimensions' => ['clientCountryName' => 'CN']]],
        ]),
        'FalakDaily' => fn () => cf_data(['days' => [
            ['dimensions' => ['date' => '2026-10-14'], 'sum' => ['requests' => 2000, 'cachedRequests' => 1500, 'bytes' => 90000, 'cachedBytes' => 70000, 'threats' => 3]],
        ]]),
    ];
    $this->graphql = [];

    Http::fake(['api.cloudflare.com/*' => function (Request $request) {
        expect($request->url())->toBe('https://api.cloudflare.com/client/v4/graphql')
            ->and($request->header('Authorization')[0])->toBeIn(['Bearer '.CF_ANALYTICS_TOKEN, 'Bearer '.CF_OTHER_TOKEN]);
        preg_match('/query (\w+)/', (string) $request['query'], $m);
        $this->graphql[] = ['op' => $m[1], 'variables' => $request['variables']];

        return ($this->answers[$m[1]])($request);
    }]);
});

/** A GraphQL answer for one zone. */
function cf_data(array $zone, array $errors = [])
{
    return Http::response(['data' => ['viewer' => ['zones' => [$zone]]], 'errors' => $errors === [] ? null : $errors]);
}

it('reports the traffic of the site’s proxied names: cached share, countries, status classes, hourly series', function () {
    $zone = $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.error', null)
        ->assertJsonCount(1, 'data.zones')
        ->json('data.zones.0');

    expect($zone['zone'])->toBe('example.com')
        ->and($zone['connection'])->toBe('Main account')
        ->and($zone['hosts'])->toBe(['shop.example.com'])
        ->and($zone['permission_missing'])->toBeFalse()
        ->and($zone['notes'])->toBe([])
        ->and($zone['traffic']['requests'])->toBe(1000)
        ->and($zone['traffic']['cached_requests'])->toBe(700) // hit + stale
        ->and($zone['traffic']['cached_ratio'])->toEqual(0.7)
        ->and($zone['traffic']['bytes'])->toBe(16000)
        ->and($zone['traffic']['cached_bytes'])->toBe(7000)
        ->and($zone['traffic']['visits'])->toBe(100)
        ->and($zone['traffic']['countries'])->toBe([['country' => 'DE', 'requests' => 700], ['country' => 'LY', 'requests' => 300]])
        ->and($zone['traffic']['status_classes'])->toBe(['2xx' => 900, '3xx' => 30, '4xx' => 60, '5xx' => 10, 'other' => 0])
        ->and($zone['traffic']['series'])->toBe([
            ['t' => '2026-10-15T10:00:00+00:00', 'cached' => 400, 'origin' => 100],
            ['t' => '2026-10-15T11:00:00+00:00', 'cached' => 300, 'origin' => 200],
        ])
        ->and($zone['threats']['mitigated'])->toBe(50)
        ->and($zone['threats']['events'])->toBe(55)
        ->and($zone['threats']['countries'])->toBe([['country' => 'CN', 'events' => 50]])
        ->and($zone['daily'])->toBe([['date' => '2026-10-14', 'requests' => 2000, 'cached_requests' => 1500, 'bytes' => 90000, 'cached_bytes' => 70000, 'threats' => 3]]);

    // The hosts filter and a 24 h window went to Cloudflare.
    $traffic = (array) collect($this->graphql)->firstWhere('op', 'FalakTraffic')['variables'];
    expect($traffic['zoneTag'])->toBe('zone-abc')
        ->and($traffic['hosts'])->toBe(['shop.example.com'])
        ->and(strtotime($traffic['until']) - strtotime($traffic['since']))->toBe(86400);
});

it('never puts the token in the answer', function () {
    $this->answers['FalakAnalyticsSettings'] = fn () => Http::response(['errors' => [['code' => 10000, 'message' => 'Authentication error']], 'data' => null], 403);

    $body = $this->getJson($this->url)->assertOk()->getContent();
    $this->answers['FalakAnalyticsSettings'] = fn () => cf_data(['settings' => [
        'httpRequestsAdaptiveGroups' => ['enabled' => true, 'maxDuration' => 86400, 'notOlderThan' => 86400],
        'firewallEventsAdaptiveGroups' => null,
        'httpRequests1dGroups' => null,
    ]]);
    $body .= $this->getJson($this->url.'?refresh=1')->assertOk()->getContent();

    expect($body)->not->toContain(CF_ANALYTICS_TOKEN)->not->toContain('api_token');
});

it('explains how to add Analytics:Read when the token lacks it (GraphQL authz)', function () {
    $this->answers['FalakAnalyticsSettings'] = fn () => Http::response(['data' => null, 'errors' => [
        ['message' => 'not authorized for that zone', 'path' => ['viewer', 'zones', 0, 'settings'], 'extensions' => ['code' => 'authz']],
    ]]);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.zones.0.permission_missing', true)
        ->assertJsonPath('data.zones.0.traffic', null)
        ->assertJsonPath('data.error', "The Cloudflare token can't read analytics. In Cloudflare → My Profile → API Tokens, edit the token of the connection “Main account” and add Zone → Analytics → Read for example.com.");

    expect(collect($this->graphql)->pluck('op')->all())->toBe(['FalakAnalyticsSettings']);
});

it('explains the missing permission on a REST-style 403 (code 10000)', function () {
    $this->answers['FalakAnalyticsSettings'] = fn () => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.zones.0.permission_missing', true)
        ->assertJsonPath('data.zones.0.error', fn (string $message) => str_contains($message, 'Zone → Analytics → Read'));
});

it('leaves out datasets the zone cannot read, with a note', function () {
    $this->settings['firewallEventsAdaptiveGroups'] = ['enabled' => false, 'maxDuration' => 0, 'notOlderThan' => 0];
    $this->settings['httpRequestsAdaptiveGroups']['maxDuration'] = 21600; // 6 h on this plan

    $zone = $this->getJson($this->url)->assertOk()->json('data.zones.0');

    expect($zone['threats'])->toBeNull()
        ->and($zone['traffic']['requests'])->toBe(1000)
        ->and($zone['notes'])->toContain('Firewall events are not available on this zone’s plan.')
        ->and($zone['notes'])->toContain('Cloudflare allows 6 h of analytics on this zone: the figures cover that.')
        ->and(collect($this->graphql)->pluck('op')->all())->not->toContain('FalakThreats')
        ->and(strtotime($zone['window']['until']) - strtotime($zone['window']['since']))->toBe(21600);
});

it('keeps the partial results of a dataset that answered with errors', function () {
    $this->answers['FalakTraffic'] = fn () => cf_data([
        'cache' => [['count' => 10, 'sum' => ['edgeResponseBytes' => 100, 'visits' => 1], 'dimensions' => ['cacheStatus' => 'miss']]],
        'countries' => [],
        'statuses' => [],
        'series' => null,
    ], [['message' => 'cannot request data older than 86400s', 'path' => ['viewer', 'zones', 0, 'series']]]);
    $this->answers['FalakThreats'] = fn () => Http::response(['data' => null, 'errors' => [
        ['message' => 'zone does not have access to the path', 'path' => ['viewer', 'zones', 0, 'actions'], 'extensions' => ['code' => 'authz']],
    ]]);

    $zone = $this->getJson($this->url)->assertOk()->json('data.zones.0');

    expect($zone['error'])->toBeNull()
        ->and($zone['traffic']['requests'])->toBe(10)
        ->and($zone['traffic']['cached_ratio'])->toEqual(0)
        ->and($zone['traffic']['series'])->toBe([])
        ->and($zone['threats'])->toBeNull()
        ->and($zone['daily'])->toHaveCount(1)
        ->and($zone['notes'])->toContain('HTTP traffic failed: Cloudflare: cannot request data older than 86400s.')
        ->and($zone['notes'])->toContain('Firewall events not available with this token or on this zone’s plan.');
});

it('caches a zone’s report for five minutes; refresh skips the cache once a minute', function () {
    $this->getJson($this->url)->assertOk();
    $calls = count($this->graphql);
    expect($calls)->toBe(4);

    $this->getJson($this->url)->assertOk();
    expect($this->graphql)->toHaveCount($calls);

    $this->getJson($this->url.'?refresh=1')->assertOk();
    expect($this->graphql)->toHaveCount($calls * 2);

    $this->getJson($this->url.'?refresh=1')->assertOk(); // throttled: served from the cache
    expect($this->graphql)->toHaveCount($calls * 2);

    $this->travel(6)->minutes();
    $this->getJson($this->url)->assertOk();
    expect($this->graphql)->toHaveCount($calls * 3);
});

it('has nothing for a site without proxied names in a managed zone', function () {
    Domain::query()->update(['cloudflare_proxied' => false]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.zones', [])->assertJsonPath('data.error', null);

    Domain::query()->update(['cloudflare_proxied' => true, 'name' => 'shop.other.org']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.zones', []);

    expect($this->graphql)->toBe([]);
});

it('groups names by zone and leaves wildcards out', function () {
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'example.com', 'is_primary' => false, 'www_redirect' => WwwRedirect::ToWww, 'tls_mode' => TlsMode::Auto]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => '*.tenants.example.com', 'is_primary' => false, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Dns]);

    $groups = app(CloudflareAnalytics::class)->proxiedHosts($this->site);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['hosts'])->toBe(['example.com', 'shop.example.com', 'www.example.com'])
        ->and($groups[0]['skipped'])->toBe(['*.tenants.example.com']);
});

it('lets viewers see it and hides other organizations’ sites', function () {
    actingAsMember(Role::Viewer, $this->organization);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data.zones');

    actingAsMember(Role::Owner);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson('/sites/01HUNKNOWN0000000000000000/cloudflare/analytics')->assertNotFound();
});

afterEach(fn () => Cache::flush());

it('keeps two organizations managing the same zone apart (cache per organization and connection)', function () {
    [, $other] = memberOf(null, Role::Owner);
    $credential = DnsCredential::query()->create([
        'organization_id' => $other->id, 'provider' => 'cloudflare', 'name' => 'Their account', 'api_token' => CF_OTHER_TOKEN, 'account_id' => 'acc-2', 'verified_at' => now(),
    ]);
    $theirs = CloudflareZone::query()->create([
        'organization_id' => $other->id, 'dns_credential_id' => $credential->id, 'zone_id' => 'zone-abc', 'name' => 'example.com', 'proxied' => true,
    ]);
    // Their token lacks Analytics:Read; ours works.
    $settings = $this->answers['FalakAnalyticsSettings'];
    $this->answers['FalakAnalyticsSettings'] = fn (Request $request) => $request->header('Authorization')[0] === 'Bearer '.CF_OTHER_TOKEN
        ? Http::response(['data' => null, 'errors' => [['message' => 'not authorized', 'path' => ['viewer', 'zones', 0, 'settings'], 'extensions' => ['code' => 'authz']]]])
        : $settings();

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.zones.0.traffic.requests', 1000);

    $report = app(CloudflareAnalytics::class)->forZone($theirs, ['shop.example.com']);
    expect($report['traffic'])->toBeNull()
        ->and($report['permission_missing'])->toBeTrue()
        ->and($report['connection'])->toBe('Their account');

    // And our cached figures still are ours, not their error.
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.zones.0.traffic.requests', 1000)->assertJsonPath('data.error', null);
});
