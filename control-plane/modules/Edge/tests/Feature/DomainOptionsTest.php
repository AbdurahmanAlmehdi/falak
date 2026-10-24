<?php

use Falak\Edge\Application\DnsInstructions;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Contracts\Data\DnsTarget;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\OrganizationSetting;
use Falak\Edge\Infrastructure\Dns\DnsAnswer;
use Falak\Edge\Infrastructure\Dns\DnsLookupFailed;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Falak\Edge\Infrastructure\Dns\DohResolver;
use Falak\Edge\Infrastructure\Dns\TlsProbe;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
 * Domain choices for new sites (generated / test / custom), DNS instructions and the live DNS check. Real Sites +
 * Edge; the DNS resolver and the TLS probe are faked.
 */

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, DnsAnswer|DnsLookupFailed> */
    public array $answers = [];

    /** @var list<string> */
    public array $queries = [];

    public function resolve(string $name): DnsAnswer
    {
        $this->queries[] = $name;
        $answer = $this->answers[$name] ?? new DnsAnswer;

        if ($answer instanceof DnsLookupFailed) {
            throw $answer;
        }

        return $answer;
    }

    public function txt(string $name): array
    {
        return [];
    }
}

final class FakeTlsProbe implements TlsProbe
{
    /** @var list<array{string, string}> */
    public array $probed = [];

    public function probe(string $name, string $address): array
    {
        $this->probed[] = [$name, $address];

        return ['status' => 'issued', 'message' => 'Certificate issued by Let\'s Encrypt.', 'issuer' => "Let's Encrypt", 'expires_at' => '2026-12-27T00:00:00+00:00'];
    }
}

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    config(['sites.test_domain' => null, 'edge.generated_domain_suffix' => 'sslip.io']);
    $this->dns = new FakeDnsResolver;
    $this->tls = new FakeTlsProbe;
    app()->instance(DnsResolver::class, $this->dns);
    app()->instance(TlsProbe::class, $this->tls);
    $this->app1 = sites_server($this->organization->id, ['name' => 'app-1', 'ipv4' => '63.182.218.246', 'ipv6' => null]);
    $this->app2 = sites_server($this->organization->id, ['name' => 'app-2', 'ipv4' => '63.182.218.247', 'ipv6' => '2a05:d014::7']);
});

function check_dns(string $name, array $query = []): array
{
    return test()->getJson('/dns/check?'.http_build_query(['name' => $name, ...$query]))->assertOk()->json('data');
}

// ─── Generated names ────────────────────────────────────────────────────────────────────────────────────────────

it('builds generated names from a label, the IPv4 address and the suffix', function () {
    expect(GeneratedDomains::name('minio-files', '63.182.218.247', 'sslip.io'))->toBe('minio-files.63-182-218-247.sslip.io')
        ->and(GeneratedDomains::name('My_Console!!', '10.0.0.1', 'nip.io'))->toBe('my-console.10-0-0-1.nip.io')
        ->and(GeneratedDomains::label(str_repeat('a', 70).'-x'))->toHaveLength(63)
        ->and(GeneratedDomains::label('---'))->toBe('app')
        ->and(GeneratedDomains::normalizeSuffix('off'))->toBeNull()
        ->and(GeneratedDomains::normalizeSuffix('.IP.Example.COM.'))->toBe('ip.example.com')
        ->and(GeneratedDomains::normalizeSuffix('not a domain'))->toBeNull();
});

it('uses the organization provider over the server default, or none when off', function () {
    $generated = app(GeneratedDomains::class);
    expect($generated->suffix($this->organization->id))->toBe('sslip.io');

    OrganizationSetting::for($this->organization->id)->forceFill(['generated_domain_provider' => 'nip.io'])->save();
    expect($generated->suffix($this->organization->id))->toBe('nip.io');

    OrganizationSetting::for($this->organization->id)->forceFill(['generated_domain_provider' => 'off'])->save();
    expect($generated->suffix($this->organization->id))->toBeNull();

    config(['edge.generated_domain_suffix' => 'off']);
    OrganizationSetting::query()->delete();
    expect($generated->suffix($this->organization->id))->toBeNull();
});

it('offers the picker options for the leader server, the load balancer of a balanced site, or explains what is missing', function () {
    $options = $this->getJson('/domains/options?'.http_build_query(['server_ids' => [$this->app2->id, $this->app1->id]]))->assertOk()->json('data');

    expect($options['default'])->toBe('generated')
        ->and($options['test_domain'])->toBeNull()
        ->and($options['generated'])->toMatchArray(['suffix' => 'sslip.io', 'ipv4' => '63.182.218.247', 'available' => true, 'reason' => null])
        ->and(collect($options['targets'])->pluck('name')->all())->toBe(['app-2', 'app-1']);

    config(['sites.test_domain' => 'falak.test']);
    expect($this->getJson('/domains/options?server_ids[]='.$this->app1->id)->json('data.default'))->toBe('test');

    $bare = sites_server($this->organization->id, ['name' => 'fresh', 'ipv4' => null]);
    expect($this->getJson('/domains/options?server_ids[]='.$bare->id)->json('data.generated'))
        ->toMatchArray(['available' => false, 'reason' => 'fresh has no public IPv4 address yet.']);

    // A load-balanced site: everything points at the balancer.
    $this->post('/sites', sites_input([$this->app1->id, $this->app2->id]))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    $lb = sites_server($this->organization->id, ['name' => 'lb-1', 'type' => ServerType::LoadBalancer, 'ipv4' => '198.51.100.20']);
    LoadBalancer::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);

    $options = $this->getJson("/domains/options?site={$site->id}")->assertOk()->json('data');
    expect($options['generated']['ipv4'])->toBe('198.51.100.20')
        ->and($options['targets'])->toBe([['server_id' => $lb->id, 'name' => 'lb-1', 'ipv4' => '198.51.100.20', 'ipv6' => null, 'load_balancer' => true]]);
});

// ─── Creating sites with a domain choice ────────────────────────────────────────────────────────────────────────

it('creates a site with a generated domain for its leader and routes it with automatic TLS', function () {
    $this->post('/sites', sites_input([$this->app1->id, $this->app2->id], ['leader_server_id' => $this->app2->id, 'domain' => ['type' => 'generated']]))
        ->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    $domain = Domain::query()->where('site_id', $site->id)->firstOrFail();

    expect($domain->name)->toBe('shop.63-182-218-247.sslip.io')
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->tls_mode->value)->toBe('auto')
        ->and(EnvironmentVersion::query()->where('site_id', $site->id)->firstOrFail()->variables['APP_URL'])->toBe('https://shop.63-182-218-247.sslip.io');
});

it('creates a site with a custom domain through the API', function () {
    $this->postJson('/api/v1/sites', sites_input([$this->app1->id], ['domain' => ['type' => 'custom', 'name' => 'Shop.Example.com']]))->assertCreated();

    expect(Domain::query()->pluck('name')->all())->toBe(['shop.example.com']);

    // A plain string is a custom domain too; a name in use elsewhere is refused before anything is created.
    $this->postJson('/api/v1/sites', sites_input([$this->app1->id], ['name' => 'Other', 'domain' => 'shop.example.com']))
        ->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'already used by another site']);
    $this->postJson('/api/v1/sites', sites_input([$this->app1->id], ['name' => 'Other', 'domain' => ['type' => 'test']]))
        ->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'No test domain is configured']);
    $this->postJson('/api/v1/sites', sites_input([$this->app1->id], ['name' => 'Other', 'domain' => ['type' => 'custom', 'name' => 'not a domain']]))
        ->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'Enter a domain name']);
    $this->postJson('/api/v1/sites', sites_input([$this->app1->id], ['name' => 'Other', 'domain' => ['type' => 'magic']]))
        ->assertUnprocessable()->assertJsonValidationErrors('domain');

    expect(Site::query()->count())->toBe(1);
});

it('keeps sites without a domain choice on the test domain only', function () {
    config(['sites.test_domain' => 'falak.test']);

    $this->post('/sites', sites_input([$this->app1->id]))->assertSessionHasNoErrors();
    $this->post('/sites', sites_input([$this->app1->id], ['name' => 'Blog', 'domain' => ['type' => 'test']]))->assertSessionHasNoErrors();

    expect(Domain::query()->count())->toBe(0);
});

it('resolves domain choices of compose public services', function () {
    $server = sites_server($this->organization->id, ['name' => 'docker-1', 'ipv4' => '203.0.113.5'], docker: true);

    $this->post('/sites', [
        'name' => 'Files',
        'runtime' => 'compose',
        'server_ids' => [$server->id],
        'compose_source' => 'inline',
        'compose_content' => "services:\n  minio:\n    image: minio/minio\n  console:\n    image: minio/console\n",
        'public_services' => [
            ['service' => 'minio', 'port' => 9000, 'domain' => ['type' => 'generated']],
            ['service' => 'console', 'port' => 8080, 'domain' => ['type' => 'custom', 'name' => 'console.example.com']],
        ],
    ])->assertSessionHasNoErrors();

    expect(collect(Site::query()->firstOrFail()->public_services)->pluck('domain', 'service')->all())->toBe([
        'minio' => 'minio-files.203-0-113-5.sslip.io',
        'console' => 'console.example.com',
    ]);
});

// ─── DNS instructions ───────────────────────────────────────────────────────────────────────────────────────────

it('lists the records to add for subdomains and apex domains', function () {
    $targets = [new DnsTarget('s1', 'app-2', '63.182.218.247', '2a05:d014::7')];
    $sub = DnsInstructions::for('shop.example.co.uk', $targets, 'shop.63-182-218-247.sslip.io');

    expect($sub)->toMatchArray(['zone' => 'example.co.uk', 'host' => 'shop', 'apex' => false, 'ttl' => 300])
        ->and($sub['records'])->toBe([
            ['type' => 'A', 'name' => 'shop.example.co.uk', 'host' => 'shop', 'value' => '63.182.218.247', 'target' => 'app-2'],
            ['type' => 'AAAA', 'name' => 'shop.example.co.uk', 'host' => 'shop', 'value' => '2a05:d014::7', 'target' => 'app-2'],
        ])
        ->and($sub['alternative'])->toBe(['type' => 'CNAME', 'name' => 'shop.example.co.uk', 'host' => 'shop', 'value' => 'shop.63-182-218-247.sslip.io']);

    $apex = DnsInstructions::for('example.com', $targets, 'shop.63-182-218-247.sslip.io');
    expect($apex)->toMatchArray(['zone' => 'example.com', 'host' => '@', 'apex' => true, 'alternative' => null])
        ->and(implode(' ', $apex['notes']))->toContain('zone apex');

    $roundRobin = DnsInstructions::for('app.example.com', [new DnsTarget('s1', 'app-1', '192.0.2.1', null), new DnsTarget('s2', 'app-2', '192.0.2.2', null)]);
    expect(collect($roundRobin['records'])->pluck('value')->all())->toBe(['192.0.2.1', '192.0.2.2'])
        ->and($roundRobin['alternative'])->toBeNull()
        ->and(implode(' ', $roundRobin['notes']))->toContain('round-robin')->toContain('Remove any existing AAAA record');
});

// ─── Live DNS check ─────────────────────────────────────────────────────────────────────────────────────────────

it('reports a domain that points at the server', function () {
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['63.182.218.247'], ['2a05:d014:0:0::7']);

    $result = check_dns('App.Example.com', ['server_ids' => [$this->app2->id], 'label' => 'shop']);

    expect($result['status'])->toBe('ok')
        ->and($result['message'])->toBe('Points to app-2 (63.182.218.247, 2a05:d014::7)')
        ->and(collect($result['matched'])->pluck('name')->all())->toBe(['app-2'])
        ->and($result['instructions']['alternative']['value'])->toBe('shop.63-182-218-247.sslip.io')
        ->and($result['certificate'])->toBeNull();
});

it('reports records that point elsewhere', function () {
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['1.2.3.4']);
    expect(check_dns('app.example.com', ['server_ids' => [$this->app2->id]]))
        ->toMatchArray(['status' => 'mismatch', 'message' => 'Resolves to 1.2.3.4 — expected 63.182.218.247, 2a05:d014::7.']);

    // An old record left next to the right one.
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['63.182.218.246', '1.2.3.4']);
    expect(check_dns('app.example.com', ['server_ids' => [$this->app1->id]]))
        ->toMatchArray(['status' => 'mismatch', 'message' => 'Also resolves to 1.2.3.4 — remove those records (expected 63.182.218.246).']);
});

it('reports names that do not resolve yet, including a dangling CNAME', function () {
    expect(check_dns('new.example.com', ['server_ids' => [$this->app1->id]]))
        ->toMatchArray(['status' => 'missing', 'message' => 'Not found yet (DNS can take a few minutes).']);

    $this->dns->answers['www.example.com'] = new DnsAnswer(['shop.example.net']);
    expect(check_dns('www.example.com', ['server_ids' => [$this->app1->id]])['message'])->toContain('CNAME to shop.example.net does not resolve');
});

it('warns when Cloudflare proxies the name', function () {
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['104.21.3.4', '172.67.1.2'], ['2606:4700:3030::6815:304']);

    $result = check_dns('app.example.com', ['server_ids' => [$this->app1->id]]);

    expect($result['status'])->toBe('proxied')
        ->and($result['message'])->toContain('Proxied by Cloudflare')->toContain('DNS only');
});

it('follows a CNAME to the generated name', function () {
    $this->dns->answers['app.example.com'] = new DnsAnswer(['shop.63-182-218-246.sslip.io'], ['63.182.218.246']);

    $result = check_dns('app.example.com', ['server_ids' => [$this->app1->id]]);

    expect($result)->toMatchArray(['status' => 'ok', 'cnames' => ['shop.63-182-218-246.sslip.io']]);
});

it('reports lookup failures and invalid names without resolving', function () {
    $this->dns->answers['app.example.com'] = new DnsLookupFailed('The DNS resolver did not answer: timeout.');

    expect(check_dns('app.example.com', ['server_ids' => [$this->app1->id]]))->toMatchArray(['status' => 'error'])
        ->and(check_dns('app.example.com', ['server_ids' => [$this->app1->id]])['message'])->toContain('Could not look up app.example.com');

    $this->dns->queries = [];
    expect(check_dns('*.example.com')['status'])->toBe('error')
        ->and(check_dns('not a name')['message'])->toBe('Enter a domain name like app.example.com.')
        ->and($this->dns->queries)->toBe([]);
});

it('expects the load balancer of a balanced site and reports its certificate', function () {
    $this->post('/sites', sites_input([$this->app1->id, $this->app2->id]))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    $lb = sites_server($this->organization->id, ['name' => 'lb-1', 'type' => ServerType::LoadBalancer, 'ipv4' => '198.51.100.20']);
    LoadBalancer::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);

    $this->dns->answers['shop.example.com'] = new DnsAnswer([], ['63.182.218.246']);
    expect(check_dns('shop.example.com', ['site' => $site->id])['message'])->toBe('Resolves to 63.182.218.246 — expected 198.51.100.20.');

    $this->dns->answers['shop.example.com'] = new DnsAnswer([], ['198.51.100.20']);
    $result = check_dns('shop.example.com', ['site' => $site->id, 'tls' => 1]);

    expect($result['status'])->toBe('ok')
        ->and($result['message'])->toBe('Points to lb-1 (198.51.100.20)')
        ->and($result['certificate']['status'])->toBe('issued')
        ->and($this->tls->probed)->toBe([['shop.example.com', '198.51.100.20']]);
});

it('does not check sites or servers of another organization', function () {
    [, $other] = memberOf();
    $foreign = sites_server($other->id, ['name' => 'theirs', 'ipv4' => '192.0.2.99']);
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['192.0.2.99']);

    expect(check_dns('app.example.com', ['server_ids' => [$foreign->id]]))->toMatchArray(['status' => 'error', 'targets' => []]);
    $this->getJson('/dns/check?name=app.example.com&site='.str_repeat('0', 26))->assertUnprocessable();
});

it('serves the DNS check and options on the API', function () {
    $this->dns->answers['app.example.com'] = new DnsAnswer([], ['63.182.218.247']);

    $this->getJson("/api/v1/dns/check?name=app.example.com&server={$this->app2->id}")
        ->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.instructions.records.0.value', '63.182.218.247');
    $this->getJson("/api/v1/domains/options?server={$this->app1->id},{$this->app2->id}")
        ->assertOk()->assertJsonPath('data.generated.ipv4', '63.182.218.246')->assertJsonCount(2, 'data.targets');
});

it('queries DNS-over-HTTPS for A and AAAA records', function () {
    Http::fake([
        'dns.test/*' => fn ($request) => Http::response(match ($request['type']) {
            'A' => ['Status' => 0, 'Answer' => [
                ['name' => 'www.example.com.', 'type' => 5, 'data' => 'app.example.com.'],
                ['name' => 'app.example.com.', 'type' => 1, 'data' => '192.0.2.10'],
            ]],
            default => ['Status' => 0, 'Answer' => [['name' => 'www.example.com.', 'type' => 5, 'data' => 'app.example.com.']]],
        }),
    ]);

    $answer = (new DohResolver(app(Factory::class), 'https://dns.test/dns-query'))->resolve('www.example.com');

    expect($answer->cnames)->toBe(['app.example.com'])->and($answer->ipv4)->toBe(['192.0.2.10'])->and($answer->ipv6)->toBe([]);

    Http::fake(['nx.test/*' => Http::response(['Status' => 3])]);
    expect((new DohResolver(app(Factory::class), 'https://nx.test/dns-query'))->resolve('nope.example.com')->addresses())->toBe([]);

    Http::fake(['servfail.test/*' => Http::response(['Status' => 2])]);
    expect(fn () => (new DohResolver(app(Factory::class), 'https://servfail.test/dns-query'))->resolve('x.example.com'))->toThrow(DnsLookupFailed::class);
});

// ─── Settings → Domains ─────────────────────────────────────────────────────────────────────────────────────────

it('lets admins pick the generated domain provider', function () {
    $this->get('/settings/domains')->assertOk()->assertInertia(fn ($page) => $page->component('Edge/DomainSettings', false)
        ->where('settings.provider', 'default')->where('settings.effective_suffix', 'sslip.io')->where('can.manage', true));

    $this->put('/settings/domains', ['provider' => 'nip.io'])->assertSessionHasNoErrors();
    expect(OrganizationSetting::for($this->organization->id)->generated_domain_provider)->toBe('nip.io');

    $this->put('/settings/domains', ['provider' => 'evil.example'])->assertSessionHasErrors('provider');
    $this->put('/settings/domains', ['provider' => 'default'])->assertSessionHasNoErrors();
    expect(OrganizationSetting::for($this->organization->id)->generated_domain_provider)->toBeNull();

    actingAsMember(Role::Developer, $this->organization);
    $this->put('/settings/domains', ['provider' => 'off'])->assertForbidden();
});
