<?php

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\VerifyDomain;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\RecheckDomainVerification;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Infrastructure\Dns\DnsAnswer;
use Falak\Edge\Infrastructure\Dns\DnsLookupFailed;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Falak\Identity\Contracts\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

/** Inline fake DnsResolver: txt() returns $txtValues, resolve() returns an empty answer. */
function fake_dns(array $txtValues = [], bool $fail = false): DnsResolver
{
    return new class($txtValues, $fail) implements DnsResolver
    {
        public function __construct(
            private array $txtValues,
            private bool $fail,
        ) {}

        public function resolve(string $name): DnsAnswer
        {
            return new DnsAnswer;
        }

        public function txt(string $name): array
        {
            if ($this->fail) {
                throw new DnsLookupFailed('DNS lookup failed');
            }

            return $this->txtValues;
        }
    };
}

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->org = $this->organization->id;
    $this->web = edge_server($this->servers, $this->org);
    $this->site = edge_site($this->sites, $this->org, [$this->web->id]);
    $this->base = "/sites/{$this->site->id}";
});

// ── AddDomain ──────────────────────────────────────────────────────────────────

it('adds domain as immediately verified in self-hosted mode', function () {
    Event::fake([DomainAdded::class]);
    config(['controlplane.managed' => false]);

    app(AddDomain::class)($this->site, 'shop.example.com');

    $domain = Domain::query()->where('name', 'shop.example.com')->firstOrFail();
    expect($domain->verified_at)->not->toBeNull()
        ->and($domain->verification_token)->toBeNull();

    Event::assertDispatched(DomainAdded::class);
});

it('adds domain as unverified in managed mode and withholds edge push', function () {
    Event::fake([DomainAdded::class]);
    config(['controlplane.managed' => true]);

    app(AddDomain::class)($this->site, 'shop.example.com');

    $domain = Domain::query()->where('name', 'shop.example.com')->firstOrFail();
    expect($domain->verified_at)->toBeNull()
        ->and($domain->verification_token)->toHaveLength(48);

    Event::assertNotDispatched(DomainAdded::class);
});

it('adds preview domain as immediately verified even in managed mode', function () {
    Event::fake([DomainAdded::class]);
    config(['controlplane.managed' => true]);

    app(AddDomain::class)($this->site, 'pr-1.preview.test', preview: true);

    $domain = Domain::query()->where('name', 'pr-1.preview.test')->firstOrFail();
    expect($domain->verified_at)->not->toBeNull()
        ->and($domain->verification_token)->toBeNull();

    Event::assertDispatched(DomainAdded::class);
});

// ── VerifyDomain ───────────────────────────────────────────────────────────────

it('verifies a domain when the correct TXT record is present', function () {
    Event::fake([DomainAdded::class]);
    config(['controlplane.managed' => true]);

    $domain = Domain::query()->create([
        'organization_id' => $this->org,
        'site_id' => $this->site->id,
        'name' => 'shop.example.com',
        'is_primary' => true,
        'www_redirect' => WwwRedirect::None,
        'tls_mode' => TlsMode::Auto,
        'verification_token' => 'tok123',
        'verified_at' => null,
    ]);

    app()->instance(DnsResolver::class, fake_dns(['tok123']));

    $result = app(VerifyDomain::class)($this->site, $domain);

    expect($result->verified_at)->not->toBeNull();
    Event::assertDispatched(DomainAdded::class);
});

it('rejects verification when the TXT record is missing', function () {
    config(['controlplane.managed' => true]);

    $domain = Domain::query()->create([
        'organization_id' => $this->org,
        'site_id' => $this->site->id,
        'name' => 'shop.example.com',
        'is_primary' => true,
        'www_redirect' => WwwRedirect::None,
        'tls_mode' => TlsMode::Auto,
        'verification_token' => 'tok123',
        'verified_at' => null,
    ]);

    app()->instance(DnsResolver::class, fake_dns(['wrong-value']));

    expect(fn () => app(VerifyDomain::class)($this->site, $domain))
        ->toThrow(ValidationException::class);
    expect($domain->refresh()->verified_at)->toBeNull();
});

it('verify endpoint returns 200 on success and 422 when TXT is missing', function () {
    config(['controlplane.managed' => true]);
    Event::fake([DomainAdded::class]);

    $domain = Domain::query()->create([
        'organization_id' => $this->org,
        'site_id' => $this->site->id,
        'name' => 'shop.example.com',
        'is_primary' => true,
        'www_redirect' => WwwRedirect::None,
        'tls_mode' => TlsMode::Auto,
        'verification_token' => 'tok_abc',
        'verified_at' => null,
    ]);

    app()->instance(DnsResolver::class, fake_dns(['wrong']));
    $this->post("{$this->base}/domains/{$domain->id}/verify")->assertSessionHasErrors('domain');

    app()->instance(DnsResolver::class, fake_dns(['tok_abc']));
    $this->post("{$this->base}/domains/{$domain->id}/verify")->assertSessionHasNoErrors();

    expect($domain->refresh()->verified_at)->not->toBeNull();
});

// ── Clash semantics ────────────────────────────────────────────────────────────

it('displaces an unverified claim from another org when AddDomain is called in managed mode', function () {
    config(['controlplane.managed' => true]);
    Event::fake([DomainAdded::class]);

    $otherOrg = (string) Str::ulid();
    $otherSite = edge_site($this->sites, $otherOrg, [$this->web->id]);

    // Another org has the domain but unverified.
    $unverified = Domain::query()->create([
        'organization_id' => $otherOrg,
        'site_id' => $otherSite->id,
        'name' => 'shared.example.com',
        'is_primary' => true,
        'www_redirect' => WwwRedirect::None,
        'tls_mode' => TlsMode::Auto,
        'verification_token' => 'old_token',
        'verified_at' => null,
    ]);

    // Adding the same name for this org should remove the other org's unverified record.
    app(AddDomain::class)($this->site, 'shared.example.com');

    expect(Domain::query()->find($unverified->id))->toBeNull();
    $mine = Domain::query()->where('name', 'shared.example.com')->where('organization_id', $this->org)->first();
    expect($mine)->not->toBeNull()
        ->and($mine->verified_at)->toBeNull(); // still needs verification
    Event::assertNotDispatched(DomainAdded::class); // not pushed to edge yet
});

it('blocks a verified claim when the domain is already verified by another org', function () {
    config(['controlplane.managed' => true]);

    $otherOrg = (string) Str::ulid();
    $otherSite = edge_site($this->sites, $otherOrg, [$this->web->id]);

    Domain::query()->create([
        'organization_id' => $otherOrg,
        'site_id' => $otherSite->id,
        'name' => 'taken.example.com',
        'is_primary' => true,
        'www_redirect' => WwwRedirect::None,
        'tls_mode' => TlsMode::Auto,
        'verification_token' => null,
        'verified_at' => now(),
    ]);

    expect(fn () => app(AddDomain::class)($this->site, 'taken.example.com'))
        ->toThrow(ValidationException::class);
});

// ── RouteCompiler ──────────────────────────────────────────────────────────────

it('RouteCompiler excludes unverified domains in managed mode', function () {
    config(['controlplane.managed' => true]);

    edge_domain($this->org, $this->site->id, 'verified.example.com', [
        'is_primary' => true, 'verified_at' => now(), 'verification_token' => null,
    ]);
    edge_domain($this->org, $this->site->id, 'unverified.example.com', [
        'verification_token' => 'pending', 'verified_at' => null,
    ]);

    $payload = edge_compile($this->web->id);
    $entry = edge_entry($payload, app(EdgeRoutes::class)->routeId($this->site->id));

    expect($entry['domains'])->toContain('verified.example.com')
        ->and($entry['domains'])->not->toContain('unverified.example.com');
});

it('RouteCompiler includes all domains in self-hosted mode regardless of verified_at', function () {
    config(['controlplane.managed' => false]);

    edge_domain($this->org, $this->site->id, 'no-token.example.com', [
        'is_primary' => true, 'verified_at' => null, 'verification_token' => null,
    ]);

    $payload = edge_compile($this->web->id);
    $entry = edge_entry($payload, app(EdgeRoutes::class)->routeId($this->site->id));

    expect($entry['domains'])->toContain('no-token.example.com');
});

// ── RecheckDomainVerification job ─────────────────────────────────────────────

it('recheck job verifies domains whose TXT record has appeared', function () {
    config(['controlplane.managed' => true]);
    Event::fake([DomainAdded::class]);

    $good = Domain::query()->create([
        'organization_id' => $this->org, 'site_id' => $this->site->id,
        'name' => 'good.example.com', 'is_primary' => true,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto,
        'verification_token' => 'correct_token', 'verified_at' => null,
    ]);
    $bad = Domain::query()->create([
        'organization_id' => $this->org, 'site_id' => $this->site->id,
        'name' => 'bad.example.com', 'is_primary' => false,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto,
        'verification_token' => 'wrong_token', 'verified_at' => null,
    ]);

    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $name): DnsAnswer
        {
            return new DnsAnswer;
        }

        public function txt(string $name): array
        {
            return match (true) {
                str_contains($name, 'good') => ['correct_token'],
                default => [],
            };
        }
    });

    app(RecheckDomainVerification::class)->handle(app(DnsResolver::class), app(EdgeChanges::class));

    expect($good->refresh()->verified_at)->not->toBeNull()
        ->and($bad->refresh()->verified_at)->toBeNull();
    Event::assertDispatched(DomainAdded::class, 1);
});

it('recheck job is a no-op in self-hosted mode', function () {
    config(['controlplane.managed' => false]);
    Event::fake([DomainAdded::class]);

    Domain::query()->create([
        'organization_id' => $this->org, 'site_id' => $this->site->id,
        'name' => 'pending.example.com', 'is_primary' => true,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto,
        'verification_token' => 'tok', 'verified_at' => null,
    ]);

    app()->instance(DnsResolver::class, fake_dns(['tok']));
    app(RecheckDomainVerification::class)->handle(app(DnsResolver::class), app(EdgeChanges::class));

    Event::assertNotDispatched(DomainAdded::class);
});

// ── Verify endpoint throttle ───────────────────────────────────────────────────

it('verify endpoint returns 429 after 10 requests per minute', function () {
    config(['controlplane.managed' => true]);
    app()->instance(DnsResolver::class, fake_dns([]));

    $domain = Domain::query()->create([
        'organization_id' => $this->org, 'site_id' => $this->site->id,
        'name' => 'throttle.example.com', 'is_primary' => true,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto,
        'verification_token' => 'tok', 'verified_at' => null,
    ]);

    for ($i = 0; $i < 10; $i++) {
        $this->post("{$this->base}/domains/{$domain->id}/verify");
    }

    $this->post("{$this->base}/domains/{$domain->id}/verify")->assertStatus(429);
})->skip(fn () => ! config('cache.default') || config('cache.default') === 'null', 'throttle requires a non-null cache driver');
