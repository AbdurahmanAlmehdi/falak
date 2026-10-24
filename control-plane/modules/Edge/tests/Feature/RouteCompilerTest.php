<?php

use Falak\Edge\Application\PathMounts;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\InstallStatus;
use Falak\Edge\Domain\Enums\LbPolicy;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\CertificateInstall;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\Mount;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Edge\Domain\Models\ServiceSetting;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\Data\PublicService;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    $this->org = (string) Str::ulid();
    $this->web = edge_server($this->servers, $this->org, ['privateIpv4' => '10.0.0.2']);
});

it('compiles every runtime kind', function (SiteRuntime $runtime, array $expected) {
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['slug' => 'app', 'runtime' => $runtime, 'appPort' => 3000, 'healthCheckPath' => '/up']);
    edge_domain($this->org, $site->id, 'app.example.com', ['is_primary' => true]);

    $entry = edge_entry(edge_compile($this->web->id), strtolower($site->id));

    expect($entry)->toMatchArray(['domains' => ['app.example.com'], 'tls' => ['mode' => 'acme'], 'access_log' => 'app', ...$expected]);
})->with([
    'frankenphp' => [SiteRuntime::FrankenPhp, ['kind' => 'frankenphp', 'root' => '/srv/falak/sites/app/current/public']],
    'php-fpm' => [SiteRuntime::PhpFpm, ['kind' => 'php_fpm', 'root' => '/srv/falak/sites/app/current/public', 'php_fpm_socket' => '/run/php/falak-app-8.4.sock']],
    'static' => [SiteRuntime::Static, ['kind' => 'static', 'root' => '/srv/falak/sites/app/current/public']],
    'node' => [SiteRuntime::Node, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']], 'health_uri' => '/up']],
    'bun' => [SiteRuntime::Bun, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']]]],
    'deno' => [SiteRuntime::Deno, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']]]],
    'docker' => [SiteRuntime::Docker, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']]]],
    'compose' => [SiteRuntime::Compose, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']]]],
    // Functions go to the server's function gateway, which starts instances on demand (no health check).
    'function' => [SiteRuntime::Function, ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:7070']], 'request_headers' => ['X-Falak-Function' => 'app', 'X-Falak-Client-IP' => '{http.vars.client_ip}']]],
]);

it('uses the recorded container upstream for docker sites', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['runtime' => SiteRuntime::Docker, 'appPort' => 8080]);
    edge_domain($this->org, $site->id, 'api.example.com', ['is_primary' => true]);

    app(EdgeRoutes::class)->recordUpstream($site->id, $this->web->id, '127.0.0.1:9002');

    $entry = edge_entry(edge_compile($this->web->id), app(EdgeRoutes::class)->routeId($site->id));
    expect($entry['upstreams'])->toBe([['dial' => '127.0.0.1:9002']]);
});

it('routes every public compose service: primary on the site domains, others on their own domains', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], [
        'runtime' => SiteRuntime::Compose, 'appPort' => 3000, 'testDomain' => 'stack.sites.falak.test', 'healthCheckPath' => '/',
        'compose' => new ComposeConfig(ComposeSource::Inline, null, [
            new PublicService('app', 8080, 'app.example.com', 3000, 'stack.sites.falak.test'),
            new PublicService('grafana_ui', 3000, null, 3001, 'grafana-ui-stack.sites.falak.test'),
            new PublicService('api', 9000, 'api.example.com', 3002, 'api-stack.sites.falak.test'),
            new PublicService('pending', 1, null, null, 'pending-stack.sites.falak.test'),
        ]),
    ]);
    edge_domain($this->org, $site->id, 'www.example.com', ['is_primary' => true]);
    $routeId = app(EdgeRoutes::class)->routeId($site->id);
    $payload = edge_compile($this->web->id);

    $primary = collect($payload['sites'])->filter(fn ($e) => str_starts_with($e['id'], $routeId) && ! str_contains($e['id'], '-svc-'));
    expect($primary->pluck('upstreams')->unique()->values()->all())->toBe([[['dial' => '127.0.0.1:3000']]])
        // No Caddy active health check: `up --wait` covers container health and apps may redirect `/`.
        ->and($primary->every(fn ($entry) => ! array_key_exists('health_uri', $entry)))->toBeTrue()
        ->and($primary->pluck('domains')->flatten()->all())->toContain('www.example.com', 'stack.sites.falak.test');

    // Domains still only kept in public_services (not imported as rows yet) are routed as before.
    expect(edge_entry($payload, "{$routeId}-svc-app"))->toMatchArray(['domains' => ['app.example.com'], 'upstreams' => [['dial' => '127.0.0.1:3000']], 'tls' => ['mode' => 'acme']])
        ->and(edge_entry($payload, "{$routeId}-svc-grafana-ui"))->toMatchArray(['domains' => ['grafana-ui-stack.sites.falak.test'], 'upstreams' => [['dial' => '127.0.0.1:3001']]])
        ->and(edge_entry($payload, "{$routeId}-svc-api"))->toMatchArray(['domains' => ['api.example.com', 'api-stack.sites.falak.test'], 'upstreams' => [['dial' => '127.0.0.1:3002']]])
        ->and(collect($payload['sites'])->pluck('id')->filter(fn ($id) => str_contains($id, 'pending'))->all())->toBe([]);
});

it('routes each public compose service on its own domain rows with its own rules', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], [
        'slug' => 'stack', 'runtime' => SiteRuntime::Compose, 'appPort' => 3000, 'testDomain' => 'stack.falak.test',
        'compose' => new ComposeConfig(ComposeSource::Inline, null, [
            new PublicService('web', 8080, null, 3000, null),
            new PublicService('admin', 9000, null, 3001, 'admin-stack.sites.falak.test'),
        ]),
    ]);
    edge_domain($this->org, $site->id, 'example.com', ['is_primary' => true]);
    edge_domain($this->org, $site->id, 'admin.example.com', ['compose_service' => 'admin', 'is_primary' => true, 'www_redirect' => WwwRedirect::ToApex]);
    edge_domain($this->org, $site->id, 'ops.example.com', ['compose_service' => 'admin', 'tls_mode' => TlsMode::Internal]);
    // Rules: the whole site, the admin service only, the primary service by name.
    Header::query()->create(['site_id' => $site->id, 'name' => 'X-Frame-Options', 'value' => 'DENY']);
    Header::query()->create(['site_id' => $site->id, 'compose_service' => 'admin', 'name' => 'X-Frame-Options', 'value' => 'SAMEORIGIN']);
    SecurityRule::query()->create(['site_id' => $site->id, 'compose_service' => 'admin', 'username' => 'ops', 'password_hash' => 'h']);
    Redirect::query()->create(['site_id' => $site->id, 'compose_service' => 'web', 'from' => '/old', 'to' => '/new', 'status' => 301]);
    SiteSetting::query()->create(['site_id' => $site->id, 'allow_ips' => ['10.0.0.0/8'], 'deny_ips' => ['10.9.9.9/32']]);
    ServiceSetting::query()->create(['site_id' => $site->id, 'service' => 'admin', 'allow_ips' => ['192.0.2.0/24'], 'deny_ips' => ['192.0.2.7/32']]);

    $routeId = app(EdgeRoutes::class)->routeId($site->id);
    $payload = edge_compile($this->web->id);
    $site_ = edge_entry($payload, $routeId);
    $admin = edge_entry($payload, "{$routeId}-svc-admin");
    $adminInternal = edge_entry($payload, "{$routeId}-svc-admin-1");

    expect($site_)->toMatchArray(['domains' => ['example.com', 'stack.falak.test'], 'upstreams' => [['dial' => '127.0.0.1:3000']]])
        ->and($site_['headers'])->toBe(['X-Frame-Options' => 'DENY'])
        ->and($site_)->not->toHaveKey('basic_auth')
        ->and($site_['redirects'])->toBe([['from' => '/old', 'to' => '/new', 'status' => 301]])
        ->and($site_['allow_ips'])->toBe(['10.0.0.0/8'])
        // The admin service: its rows (www redirect included) and its test domain, its header wins, its auth, its
        // allow list instead of the site's, both deny lists; not the primary service's redirect.
        ->and($admin)->toMatchArray(['domains' => ['admin.example.com', 'admin-stack.sites.falak.test'], 'redirect_domains' => ['www.admin.example.com'], 'upstreams' => [['dial' => '127.0.0.1:3001']], 'tls' => ['mode' => 'acme']])
        ->and($admin['headers'])->toBe(['X-Frame-Options' => 'SAMEORIGIN'])
        ->and($admin['basic_auth'])->toBe([['username' => 'ops', 'password_hash' => 'h']])
        ->and($admin)->not->toHaveKey('redirects')
        ->and($admin['allow_ips'])->toBe(['192.0.2.0/24'])
        ->and($admin['deny_ips'])->toBe(['10.9.9.9/32', '192.0.2.7/32'])
        ->and($admin['access_log'])->toBe('stack')
        ->and($adminInternal)->toMatchArray(['domains' => ['ops.example.com'], 'tls' => ['mode' => 'internal'], 'upstreams' => [['dial' => '127.0.0.1:3001']]]);
});

it('skips sites that cannot be routed and sites without hosts', function () {
    edge_site($this->sites, $this->org, [$this->web->id], ['runtime' => SiteRuntime::Node, 'appPort' => null, 'testDomain' => 'a.falak.test']);
    edge_site($this->sites, $this->org, [$this->web->id]); // no domains

    expect(edge_compile($this->web->id)['sites'])->toBe([]);
});

it('routes the test domain and www redirects', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['testDomain' => 'shop.falak.test']);
    edge_domain($this->org, $site->id, 'shop.com', ['is_primary' => true, 'www_redirect' => WwwRedirect::ToWww]);
    edge_domain($this->org, $site->id, 'shop.de', ['www_redirect' => WwwRedirect::ToApex]);

    $entry = edge_entry(edge_compile($this->web->id), strtolower($site->id));

    expect($entry['domains'])->toBe(['www.shop.com', 'shop.de', 'shop.falak.test'])
        ->and($entry['redirect_domains'])->toBe(['shop.com', 'www.shop.de']);
});

it('issues internal certificates for test domains when configured', function () {
    config(['edge.test_domain_tls' => 'internal']);
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['testDomain' => 'shop.falak.test']);
    edge_domain($this->org, $site->id, 'shop.com', ['is_primary' => true]);

    $payload = edge_compile($this->web->id);
    $id = strtolower($site->id);

    expect(edge_entry($payload, $id))->toMatchArray(['domains' => ['shop.com'], 'tls' => ['mode' => 'acme']])
        ->and(edge_entry($payload, "{$id}-1"))->toMatchArray(['domains' => ['shop.falak.test'], 'tls' => ['mode' => 'internal'], 'kind' => 'frankenphp']);
});

it('groups domains by tls and gates custom certificates on installation', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id]);
    $pem = edge_self_signed(['secure.example.com']);
    $certificate = Certificate::query()->create([
        'organization_id' => $this->org, 'site_id' => $site->id, 'name' => 'falak-cert1', 'domains' => ['secure.example.com'],
        'cert_pem' => $pem['cert'], 'key_pem' => $pem['key'], 'fingerprint' => str_repeat('a', 64),
    ]);
    edge_domain($this->org, $site->id, 'example.com', ['is_primary' => true]);
    edge_domain($this->org, $site->id, 'secure.example.com', ['tls_mode' => TlsMode::Custom, 'certificate_id' => $certificate->id]);
    edge_domain($this->org, $site->id, 'plain.example.com', ['tls_mode' => TlsMode::Off]);
    $id = strtolower($site->id);

    $before = edge_compile($this->web->id);
    expect(collect($before['sites'])->pluck('domains')->all())->toBe([['example.com'], ['plain.example.com']]);

    CertificateInstall::query()->create(['certificate_id' => $certificate->id, 'server_id' => $this->web->id, 'status' => InstallStatus::Installed]);

    $after = edge_compile($this->web->id);
    expect(edge_entry($after, $id)['domains'])->toBe(['example.com'])
        ->and(edge_entry($after, "{$id}-1"))->toMatchArray(['domains' => ['plain.example.com'], 'tls' => ['mode' => 'off']])
        ->and(edge_entry($after, "{$id}-2"))->toMatchArray(['domains' => ['secure.example.com'], 'tls' => ['mode' => 'custom', 'cert_name' => 'falak-cert1']]);
});

it('uses DNS-01 for wildcard domains', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id]);
    $credential = DnsCredential::query()->create(['organization_id' => $this->org, 'provider' => 'cloudflare', 'name' => 'CF', 'api_token' => 'cf-token-123']);
    edge_domain($this->org, $site->id, '*.shop.com', ['is_primary' => true, 'tls_mode' => TlsMode::Dns, 'dns_credential_id' => $credential->id]);
    edge_domain($this->org, $site->id, 'auto.shop.com', ['tls_mode' => TlsMode::Auto]);
    edge_domain($this->org, $site->id, '*.nope.com', ['tls_mode' => TlsMode::Auto]); // invalid combination is never emitted

    $payload = edge_compile($this->web->id);

    expect(edge_entry($payload, strtolower($site->id)))->toMatchArray([
        'domains' => ['*.shop.com'],
        'tls' => ['mode' => 'acme', 'dns' => ['provider' => 'cloudflare', 'api_token' => 'cf-token-123']],
    ])->and(edge_entry($payload, strtolower($site->id).'-1')['domains'])->toBe(['auto.shop.com'])
        ->and($payload['sites'])->toHaveCount(2);
});

it('compiles redirects, headers, basic auth and site settings', function () {
    config(['edge.acme_email' => 'ops@example.com']);
    $site = edge_site($this->sites, $this->org, [$this->web->id]);
    edge_domain($this->org, $site->id, 'example.com', ['is_primary' => true]);
    Redirect::query()->create(['site_id' => $site->id, 'from' => '/old', 'to' => '/new', 'status' => 302, 'position' => 2]);
    Redirect::query()->create(['site_id' => $site->id, 'from' => '/blog/*', 'to' => 'https://blog.example.com', 'status' => 301, 'position' => 1]);
    Header::query()->create(['site_id' => $site->id, 'name' => 'X-Frame-Options', 'value' => 'DENY']);
    SecurityRule::query()->create(['site_id' => $site->id, 'path' => '/admin/*', 'username' => 'ops', 'password_hash' => '$2y$10$abc']);
    SecurityRule::query()->create(['site_id' => $site->id, 'path' => null, 'username' => 'all', 'password_hash' => '$2y$10$def']);
    SiteSetting::query()->create(['site_id' => $site->id, 'allow_ips' => ['10.0.0.0/8'], 'deny_ips' => ['203.0.113.7'], 'max_body_bytes' => 1048576, 'encode' => false]);

    $payload = edge_compile($this->web->id);

    expect($payload['acme_email'])->toBe('ops@example.com')
        ->and(edge_entry($payload, strtolower($site->id)))->toMatchArray([
            'redirects' => [['from' => '/blog/*', 'to' => 'https://blog.example.com', 'status' => 301], ['from' => '/old', 'to' => '/new', 'status' => 302]],
            'headers' => ['X-Frame-Options' => 'DENY'],
            'basic_auth' => [['username' => 'all', 'password_hash' => '$2y$10$def'], ['username' => 'ops', 'password_hash' => '$2y$10$abc', 'path' => '/admin/*']],
            'allow_ips' => ['10.0.0.0/8'],
            'deny_ips' => ['203.0.113.7'],
            'max_body_bytes' => 1048576,
            'encode' => false,
        ]);
});

it('load balances through an lb server and serves plain HTTP on the backends', function () {
    $second = edge_server($this->servers, $this->org, ['privateIpv4' => null, 'ipv4' => '198.51.100.9']);
    $lb = edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer]);
    $site = edge_site($this->sites, $this->org, [$this->web->id, $second->id], ['testDomain' => 'shop.falak.test']);
    edge_domain($this->org, $site->id, 'shop.com', ['is_primary' => true, 'www_redirect' => WwwRedirect::ToWww]);
    SecurityRule::query()->create(['site_id' => $site->id, 'username' => 'ops', 'password_hash' => '$2y$10$abc']);
    LoadBalancer::query()->create(['organization_id' => $this->org, 'site_id' => $site->id, 'server_id' => $lb->id, 'policy' => LbPolicy::LeastConn, 'health_uri' => '/up', 'backend_port' => 80, 'weights' => [$this->web->id => 2]]);
    $id = strtolower($site->id);

    $front = edge_entry(edge_compile($lb->id), $id);
    expect($front)->toMatchArray([
        'domains' => ['www.shop.com', 'shop.falak.test'],
        'redirect_domains' => ['shop.com'],
        'tls' => ['mode' => 'acme'],
        'kind' => 'reverse_proxy',
        'upstreams' => [['dial' => '10.0.0.2:80'], ['dial' => '10.0.0.2:80'], ['dial' => '198.51.100.9:80']],
        'lb_policy' => 'least_conn',
        'health_uri' => '/up',
        'basic_auth' => [['username' => 'ops', 'password_hash' => '$2y$10$abc']],
        // The balancer sees the real clients: it writes the site's access log.
        'access_log' => $site->slug,
    ]);

    $backend = edge_entry(edge_compile($this->web->id), $id);
    expect($backend)->toMatchArray(['kind' => 'frankenphp', 'tls' => ['mode' => 'off'], 'domains' => ['www.shop.com', 'shop.falak.test']])
        ->and($backend)->not->toHaveKey('basic_auth')
        ->and($backend)->not->toHaveKey('access_log');

    expect(app(EdgeRoutes::class)->compile($lb->id))->toBe(app(EdgeRoutes::class)->compile($lb->id));
});

it('compiles all sites of a server in a deterministic order', function () {
    $a = edge_site($this->sites, $this->org, [$this->web->id], ['id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']);
    $b = edge_site($this->sites, $this->org, [$this->web->id], ['id' => '01JAAAAAAAAAAAAAAAAAAAAAAA']);
    edge_domain($this->org, $a->id, 'a.example.com', ['is_primary' => true]);
    edge_domain($this->org, $b->id, 'b.example.com', ['is_primary' => true]);

    $payload = edge_compile($this->web->id);

    expect(array_column($payload['sites'], 'id'))->toBe(['01jaaaaaaaaaaaaaaaaaaaaaaa', '01jzzzzzzzzzzzzzzzzzzzzzzz'])
        ->and(json_encode($payload))->toBe(json_encode(edge_compile($this->web->id)));
});

it('exposes domains and route ids through the contract', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id]);
    edge_domain($this->org, $site->id, 'b.example.com');
    edge_domain($this->org, $site->id, 'a.example.com', ['is_primary' => true]);

    $domains = app(EdgeRoutes::class)->domainsFor($site->id);

    expect(array_map(fn ($d) => $d->name, $domains))->toBe(['a.example.com', 'b.example.com'])
        ->and($domains[0]->primary)->toBeTrue()
        ->and(app(EdgeRoutes::class)->routeId($site->id))->toBe(strtolower($site->id))
        ->and(app(SiteDomains::class)->primaryDomains([$site->id]))->toBe([$site->id => 'a.example.com']);
});

it('routes a site path to a function: its local gateway on the same server, else its own domain over HTTPS', function () {
    $other = edge_server($this->servers, $this->org, ['privateIpv4' => '10.0.0.3']);
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['slug' => 'shop']);
    edge_domain($this->org, $site->id, 'shop.example.com', ['is_primary' => true]);
    $local = edge_site($this->sites, $this->org, [$this->web->id], ['slug' => 'api-fn', 'runtime' => SiteRuntime::Function]);
    $remote = edge_site($this->sites, $this->org, [$other->id], ['slug' => 'hooks-fn', 'runtime' => SiteRuntime::Function]);
    edge_domain($this->org, $remote->id, 'hooks-fn.example.com', ['is_primary' => true]);
    Mount::query()->create(['organization_id' => $this->org, 'site_id' => $site->id, 'function_site_id' => $local->id, 'path_prefix' => '/api', 'strip_prefix' => true]);
    Mount::query()->create(['organization_id' => $this->org, 'site_id' => $site->id, 'function_site_id' => $remote->id, 'path_prefix' => '/api/hooks', 'strip_prefix' => false]);

    $entry = edge_entry(edge_compile($this->web->id), strtolower($site->id));

    expect($entry['mounts'])->toBe([
        // longest prefix first
        ['path_prefix' => '/api/hooks', 'strip_prefix' => false, 'dial' => 'hooks-fn.example.com:443', 'tls_server_name' => 'hooks-fn.example.com', 'request_headers' => ['Host' => 'hooks-fn.example.com']],
        ['path_prefix' => '/api', 'strip_prefix' => true, 'dial' => '127.0.0.1:7070', 'request_headers' => ['X-Falak-Function' => 'api-fn', 'X-Falak-Client-IP' => '{http.vars.client_ip}']],
    ]);
});

it('serves a function path on one public service of a compose site', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], [
        'id' => strtolower((string) Str::ulid()), 'slug' => 'stack', 'runtime' => SiteRuntime::Compose, 'appPort' => 3000, 'testDomain' => 'stack.falak.test',
        'compose' => new ComposeConfig(ComposeSource::Inline, null, [new PublicService('web', 80, null, 3000, 'stack.falak.test'), new PublicService('admin', 9000, null, 3001, 'admin-stack.falak.test')]),
    ]);
    $function = edge_site($this->sites, $this->org, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'api-fn', 'runtime' => SiteRuntime::Function]);
    $mounts = app(PathMounts::class);

    $mounts->create($function, $site->id, '/hooks', true, 'admin');
    // The same path for the whole site is another mount; a service that is not public is refused.
    $mounts->create($function, $site->id, '/hooks', false);
    expect(fn () => $mounts->create($function, $site->id, '/x', false, 'redis'))->toThrow(ValidationException::class);

    $routeId = strtolower($site->id);
    $payload = edge_compile($this->web->id);
    $web = edge_entry($payload, $routeId);
    $admin = edge_entry($payload, "{$routeId}-svc-admin");

    // The service's own mount wins over the site-wide one on the same path.
    expect($admin['mounts'])->toHaveCount(1)
        ->and($admin['mounts'][0])->toMatchArray(['path_prefix' => '/hooks', 'strip_prefix' => true])
        ->and($web['mounts'][0])->toMatchArray(['path_prefix' => '/hooks', 'strip_prefix' => false]);
});

it('validates function paths and forgets them with their sites', function () {
    $site = edge_site($this->sites, $this->org, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'shop']);
    $function = edge_site($this->sites, $this->org, [$this->web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'api-fn', 'runtime' => SiteRuntime::Function]);
    $mounts = app(PathMounts::class);

    foreach (['/', '/../etc', 'no space/x y', '/a//b'] as $bad) {
        expect(fn () => $mounts->create($function, $site->id, $bad, false))->toThrow(ValidationException::class);
    }
    expect(fn () => $mounts->create($function, $function->id, '/api', false))->toThrow(ValidationException::class);

    $mount = $mounts->create($function, $site->id, 'api/', true);
    expect($mount->path_prefix)->toBe('/api')
        ->and(fn () => $mounts->create($function, $site->id, '/api', false))->toThrow(ValidationException::class);

    $mounts->siteDeleted($function->id);
    expect(Mount::query()->count())->toBe(0);
});
