<?php

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Infrastructure\Config\EdgeReconciler;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

// falak.yaml's domains, redirects and headers (Edge's Reconcilable): current → desired → ChangeSet tables, apply,
// ownership, and the dashboard's read-only signal.

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->org = $this->organization->id;
    $this->web = edge_server($this->servers, $this->org);
    $this->site = edge_site($this->sites, $this->org, [$this->web->id]);
    $project = Project::query()->where('organization_id', $this->org)->where('is_default', true)->firstOrFail();
    $environment = $project->production();
    ConfigSource::query()->create([
        'organization_id' => $this->org, 'project_id' => $project->id, 'source_connection_id' => strtolower((string) Str::ulid()),
        'repository' => 'acme/shop', 'path' => 'falak.yaml',
    ]);
    $run = ConfigRun::query()->create([
        'organization_id' => $this->org, 'project_id' => $project->id, 'environment_id' => $environment->id, 'environment' => $environment->slug,
        'commit' => str_repeat('b', 40), 'trigger' => 'push', 'status' => 'applying', 'file' => '',
    ]);
    $this->scope = new ConfigScope($this->org, $project->id, $environment->id, $environment->slug, true, str_repeat('b', 40),
        ['web' => ['kind' => 'site', 'ref_id' => $this->site->id]], [$this->web->name => $this->web->id], [$this->web->id], credentials: ['cf']);
    $this->reconciler = app(EdgeReconciler::class);
    $this->ctx = new ApplyContext($run->id, ApplyPhase::WithRelease, str_repeat('b', 40), unit: 'web');
});

/**
 * @param  array<string, mixed>  $web
 * @return list<array{0: string, 1: string}>
 */
function edge_config_apply(array $web): array
{
    $r = test()->reconciler;
    $changes = $r->diff(test()->scope, ['units' => ['web' => ['type' => 'app', ...$web]]], $r->current(test()->scope));
    $r->apply(test()->scope, $changes, test()->ctx);

    return array_map(fn (Change $c) => [$c->op->value, $c->path], $changes->actionable());
}

function edge_config_domain(string $name, array $attributes = []): Domain
{
    return Domain::query()->create(['organization_id' => test()->org, 'site_id' => test()->site->id, 'name' => $name, 'is_primary' => false,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto, ...$attributes]);
}

it('reads today\'s routes in the file\'s shape', function () {
    edge_config_domain('shop.example.com', ['is_primary' => true, 'www_redirect' => WwwRedirect::ToWww]);
    edge_config_domain('api.example.com');
    Redirect::query()->create(['site_id' => $this->site->id, 'from' => '/old', 'to' => '/new', 'status' => 302, 'position' => 1]);
    Header::query()->create(['site_id' => $this->site->id, 'name' => 'X-Frame-Options', 'value' => 'DENY']);

    expect($this->reconciler->current($this->scope)['units']['web'])->toBe([
        'type' => 'app',
        'domains' => [['name' => 'shop.example.com', 'www' => 'to_www', 'tls' => 'auto'], 'api.example.com'],
        'redirects' => [['from' => '/old', 'to' => '/new', 'status' => 302]],
        'headers' => ['X-Frame-Options' => 'DENY'],
    ]);
});

it('plans domains by host, redirects by from and headers by name', function () {
    edge_config_domain('shop.example.com', ['is_primary' => true]);
    edge_config_domain('manual.example.com');
    Redirect::query()->create(['site_id' => $this->site->id, 'from' => '/old', 'to' => '/new', 'status' => 301, 'position' => 1]);

    $changes = $this->reconciler->diff($this->scope, ['units' => ['web' => ['type' => 'app',
        'domains' => ['shop.example.com', ['name' => 'www.example.com', 'tls' => 'internal']],
        'redirects' => [['from' => '/old', 'to' => '/newer']],
        'headers' => ['X-Robots-Tag' => 'noindex'],
    ]]], $this->reconciler->current($this->scope));

    expect(array_map(fn (Change $c) => [$c->op->value, $c->path], $changes->changes))->toEqualCanonicalizing([
        ['noop', '/units/web/domains/shop.example.com/primary'],
        ['noop', '/units/web/domains/shop.example.com/www'],
        ['noop', '/units/web/domains/shop.example.com/tls'],
        ['create', '/units/web/domains/www.example.com'],
        ['update', '/units/web/redirects/~1old/to'],
        ['noop', '/units/web/redirects/~1old/status'],
        ['create', '/units/web/headers/X-Robots-Tag'],
    ]);
});

it('applies routes, records ownership, deletes what it created and detaches what it adopted', function () {
    $adopted = edge_config_domain('shop.example.com', ['is_primary' => true]);
    DnsCredential::query()->create(['organization_id' => $this->org, 'provider' => 'cloudflare', 'name' => 'cf', 'api_token' => 't']);

    edge_config_apply([
        'domains' => ['shop.example.com', ['name' => 'api.example.com', 'primary' => true, 'tls' => 'dns', 'dns_credential' => 'cf']],
        'redirects' => [['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => 'https://example.com', 'status' => 308]],
        'headers' => ['X-Frame-Options' => 'DENY'],
    ]);

    $api = Domain::query()->where('name', 'api.example.com')->sole();
    $managed = app(ManagedResources::class);

    expect($api->is_primary)->toBeTrue()->and($api->tls_mode)->toBe(TlsMode::Dns)->and($api->dns_credential_id)->not->toBeNull()
        ->and($adopted->refresh()->is_primary)->toBeFalse()
        ->and(Redirect::query()->orderBy('position')->pluck('from')->all())->toBe(['/a', '/c'])
        ->and(Redirect::query()->where('from', '/c')->value('status'))->toBe(308)
        ->and($managed->managed('domain', $api->id)->paths)->toBe(['/domains/api.example.com'])
        ->and($managed->createdByFile('domain', $api->id))->toBeTrue()
        ->and($managed->createdByFile('domain', $adopted->id))->toBeFalse()
        ->and($managed->managed('redirect', Redirect::query()->where('from', '/a')->value('id'))->paths)->toBe(['/redirects/~1a']);

    // The same file again: nothing to do.
    expect(edge_config_apply([
        'domains' => ['shop.example.com', ['name' => 'api.example.com', 'primary' => true, 'tls' => 'dns', 'dns_credential' => 'cf']],
        'redirects' => [['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => 'https://example.com', 'status' => 308]],
        'headers' => ['X-Frame-Options' => 'DENY'],
    ]))->toBe([]);

    expect(edge_config_apply([]))->toEqualCanonicalizing([
        ['detach', '/units/web/domains/shop.example.com'],
        ['delete', '/units/web/domains/api.example.com'],
        ['delete', '/units/web/redirects/~1a'],
        ['delete', '/units/web/redirects/~1c'],
        ['delete', '/units/web/headers/X-Frame-Options'],
    ])
        ->and(Domain::query()->pluck('name')->all())->toBe(['shop.example.com'])
        ->and($managed->managed('domain', $adopted->id)->any())->toBeFalse()
        ->and(Redirect::query()->count() + Header::query()->count())->toBe(0);
});

it('validates domain conflicts and names', function () {
    $other = edge_site($this->sites, $this->org, [$this->web->id]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $other->id, 'name' => 'taken.example.com', 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    $issues = $this->reconciler->validate($this->scope, ['units' => [
        'web' => ['type' => 'app', 'domains' => [
            ['name' => 'a.example.com', 'primary' => true],
            ['name' => 'b.example.com', 'primary' => true],
            ['name' => 'www.example.com', 'www' => 'to_www'],
            ['name' => 'd.example.com', 'tls' => 'dns', 'dns_credential' => 'nope'],
            ['name' => 'e.example.com', 'tls' => 'custom', 'certificate' => 'nope'],
            'taken.example.com',
        ]],
        'api' => ['type' => 'app', 'domains' => ['a.example.com']],
    ]]);

    expect(collect($issues)->map(fn ($i) => [$i->code, $i->pointer])->all())->toEqualCanonicalizing([
        ['value.conflict', '/units/web/domains/1/primary'],
        ['value.conflict', '/units/web/domains/2/www'],
        ['name.dns_credential', '/units/web/domains/3/dns_credential'],
        ['name.certificate', '/units/web/domains/4/certificate'],
        ['value.conflict', '/units/web/domains/5'],
        ['value.conflict', '/units/api/domains/0'],
    ]);
});

it('uses only certificates and DNS credentials the project already uses or an admin allowed', function () {
    $credential = DnsCredential::query()->create(['organization_id' => $this->org, 'provider' => 'cloudflare', 'name' => 'org-wide', 'api_token' => 'secret']);
    $certificate = Certificate::query()->create([
        'organization_id' => $this->org, 'name' => 'org-cert', 'domains' => ['*.example.com'], 'cert_pem' => 'x', 'key_pem' => 'y',
        'not_before' => now()->subDay(), 'not_after' => now()->addYear(), 'fingerprint' => str_repeat('f', 64),
    ]);
    $file = ['units' => ['web' => ['type' => 'app', 'domains' => [
        ['name' => 'd.example.com', 'tls' => 'dns', 'dns_credential' => 'org-wide'],
        ['name' => 'e.example.com', 'tls' => 'custom', 'certificate' => 'org-cert'],
    ]]]];
    $refused = $this->reconciler->validate($this->scope, $file);

    expect(collect($refused)->map(fn ($i) => [$i->code, $i->pointer])->all())->toEqualCanonicalizing([
        ['name.dns_credential', '/units/web/domains/0/dns_credential'],
        ['name.certificate', '/units/web/domains/1/certificate'],
    ])->and($refused[0]->message)->toContain('an admin must allow it');

    // Used by a domain of the project already: allowed.
    edge_config_domain('old.example.com', ['tls_mode' => TlsMode::Dns, 'dns_credential_id' => $credential->id]);
    // Allowed by an admin on the config source.
    $allowed = new ConfigScope($this->scope->organizationId, $this->scope->projectId, $this->scope->environmentId, $this->scope->environment, true, null,
        $this->scope->services, $this->scope->servers, $this->scope->defaultServers, credentials: ['org-cert']);

    expect($this->reconciler->validate($allowed, $file))->toBe([])
        ->and($certificate->exists)->toBeTrue();
});

it('says nothing about domains of other organizations', function () {
    [, $other] = memberOf();
    $site = edge_site($this->sites, $other->id, [$this->web->id]);
    Domain::query()->create(['organization_id' => $other->id, 'site_id' => $site->id, 'name' => 'theirs.example.com', 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    $issues = $this->reconciler->validate($this->scope, ['units' => ['web' => ['type' => 'app', 'domains' => ['theirs.example.com']]]]);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->code)->toBe('value.conflict')
        ->and($issues[0]->message)->toBe('This domain is not available.');
});

it('shows managed routes read-only and refuses edits of them', function () {
    edge_config_apply(['domains' => ['shop.example.com'], 'redirects' => [['from' => '/a', 'to' => '/b']], 'headers' => ['X-Frame-Options' => 'DENY']]);
    $free = edge_config_domain('manual.example.com');
    $domain = Domain::query()->where('name', 'shop.example.com')->sole();
    $base = "/sites/{$this->site->id}";

    $domains = collect($this->getJson("{$base}/domains")->assertOk()->json('data.domains'));
    $routing = $this->getJson("{$base}/routing")->assertOk();

    expect($domains->firstWhere('id', $domain->id)['managed']['badge'])->toBe('Managed by falak.yaml @ bbbbbbb')
        ->and($domains->firstWhere('id', $free->id)['managed'])->toBeNull()
        ->and($routing->json('data.redirects.0.managed.paths'))->toBe(['/redirects/~1a'])
        ->and($routing->json('data.headers.0.managed.paths'))->toBe(['/headers/X-Frame-Options']);

    $this->patchJson("{$base}/domains/{$domain->id}", ['tls_mode' => 'internal'])->assertStatus(422)->assertJsonValidationErrors('domain');
    $this->deleteJson("{$base}/domains/{$domain->id}")->assertStatus(422);
    $this->putJson("{$base}/domains/{$domain->id}/primary")->assertStatus(422);
    $this->deleteJson("{$base}/redirects/".Redirect::query()->value('id'))->assertStatus(422);
    $this->deleteJson("{$base}/headers/".Header::query()->value('id'))->assertStatus(422);
    $this->postJson("{$base}/headers", ['name' => 'X-Frame-Options', 'value' => 'SAMEORIGIN'])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->patch("{$base}/domains/{$free->id}", ['tls_mode' => 'internal'])->assertSessionHasNoErrors();

    expect($domain->refresh()->tls_mode)->toBe(TlsMode::Auto)->and($free->refresh()->tls_mode)->toBe(TlsMode::Internal)
        ->and(Header::query()->value('value'))->toBe('DENY');
});
