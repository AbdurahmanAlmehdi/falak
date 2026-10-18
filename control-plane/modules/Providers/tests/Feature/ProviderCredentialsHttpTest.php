<?php

use Falak\Identity\Contracts\Role;
use Falak\Providers\Domain\Models\ProviderCredential;
use Falak\Providers\Events\ProviderCredentialAdded;
use Falak\Providers\Events\ProviderCredentialRemoved;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['providers.http.retry_sleep_ms' => 0]);
});

function auditRows(): array
{
    return DB::table('identity_audit_log')->get()->map(fn ($row) => (array) $row)->all();
}

it('shows credentials without secrets to viewers', function () {
    [, $org] = actingAsMember(Role::Viewer);
    ProviderCredential::factory()->forOrganization($org->id)->create(['name' => 'Prod', 'credentials' => ['token' => 'never-show-me']]);
    ProviderCredential::factory()->forOrganization('01JOTHERORGAAAAAAAAAAAAAAA')->create(['name' => 'Other org']);

    $response = $this->get('/settings/cloud-providers')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Providers/Index', false)
        ->has('credentials', 1)
        ->where('credentials.0.name', 'Prod')
        ->where('credentials.0.provider', 'hetzner')
        ->missing('credentials.0.credentials')
        ->where('can.manage', false)
        ->has('providers', 6)
        ->where('providers.4.value', 'aws')
        ->has('providers.4.fields', 3));

    expect($response->getContent())->not->toContain('never-show-me')
        ->and(base_path('modules/Providers/resources/js/pages/Index.tsx'))->toBeFile();
});

it('requires authentication and an organization permission', function () {
    $this->get('/settings/cloud-providers')->assertRedirect('/login');
});

it('lets admins add a verified credential and records an audit entry without secrets', function () {
    Event::fake([ProviderCredentialAdded::class]);
    Http::fake(['api.hetzner.cloud/v1/locations*' => Http::response(['locations' => []])]);
    [$user, $org] = actingAsMember(Role::Admin);

    $this->post('/providers', [
        'provider' => 'hetzner',
        'name' => 'Production',
        'credentials' => ['token' => 'hcloud-top-secret', 'unexpected' => 'dropped'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $credential = ProviderCredential::query()->firstOrFail();

    expect($credential->organization_id)->toBe($org->id)
        ->and($credential->credentials)->toBe(['token' => 'hcloud-top-secret'])
        ->and($credential->created_by)->toBe($user->id);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer hcloud-top-secret'));
    Event::assertDispatched(ProviderCredentialAdded::class, fn ($e) => $e->credentialId === $credential->id && $e->organizationId === $org->id && $e->provider === 'hetzner');

    $audit = collect(auditRows())->firstWhere('action', 'provider_credential.created');
    expect($audit)->not->toBeNull()
        ->and($audit['organization_id'])->toBe($org->id)
        ->and(json_encode(auditRows()))->not->toContain('hcloud-top-secret');
});

it('adds a Contabo account from one pasted cntb command, and explains what is missing', function () {
    Http::fake([
        'auth.contabo.com/*' => Http::response(['access_token' => 'tok']),
        'api.contabo.com/v1/compute/instances*' => Http::response(['data' => [], '_pagination' => ['totalPages' => 1]]),
    ]);
    actingAsMember(Role::Admin);
    $pasted = "cntb config set-credentials --oauth2-clientid=INT-1 --oauth2-client-secret=sec --oauth2-user=me@example.com --oauth2-password='Pa ss!1'";

    $this->post('/providers', ['provider' => 'contabo', 'name' => 'Contabo', 'credentials' => ['api_details' => $pasted]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ProviderCredential::query()->firstOrFail()->credentials)->toBe(['api_details' => $pasted]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'auth.contabo.com') && $r['password'] === 'Pa ss!1');

    $this->post('/providers', ['provider' => 'contabo', 'name' => 'Half', 'credentials' => ['api_details' => 'ClientId: INT-1']])
        ->assertSessionHasErrors(['credentials' => 'Could not verify the credential: Missing Client Secret, API User, API Password. On my.contabo.com → Account → API, set an API password, then paste the whole `cntb config set-credentials …` line (with your password filled in), or the four values.']);
});

it('rejects credentials the provider does not accept', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'unable to authenticate']], 401)]);
    actingAsMember(Role::Admin);

    $this->post('/providers', ['provider' => 'hetzner', 'name' => 'Bad', 'credentials' => ['token' => 'nope']])
        ->assertSessionHasErrors(['credentials' => 'Could not verify the credential: Hetzner Cloud: unable to authenticate']);

    expect(ProviderCredential::query()->count())->toBe(0);
});

it('validates provider-specific fields', function () {
    actingAsMember(Role::Admin);

    $this->post('/providers', ['provider' => 'aws', 'name' => 'AWS', 'credentials' => ['access_key_id' => 'AKIA', 'region' => 'not a region']])
        ->assertSessionHasErrors(['credentials.secret_access_key', 'credentials.region']);

    $this->post('/providers', ['provider' => 'custom', 'name' => 'Custom', 'credentials' => []])
        ->assertSessionHasErrors(['provider']);
});

it('enforces unique names per organization', function () {
    [, $org] = actingAsMember(Role::Admin);
    ProviderCredential::factory()->forOrganization($org->id)->create(['name' => 'Prod']);

    $this->post('/providers', ['provider' => 'hetzner', 'name' => 'Prod', 'credentials' => ['token' => 'x']])
        ->assertSessionHasErrors(['name']);
});

it('forbids developers and viewers from managing credentials', function (Role $role) {
    [, $org] = actingAsMember($role);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create();

    $this->post('/providers', ['provider' => 'hetzner', 'name' => 'x', 'credentials' => ['token' => 'x']])->assertForbidden();
    $this->patch("/providers/{$credential->id}", ['name' => 'y'])->assertForbidden();
    $this->post("/providers/{$credential->id}/verify")->assertForbidden();
    $this->delete("/providers/{$credential->id}")->assertForbidden();

    expect($credential->fresh())->not->toBeNull();
})->with([Role::Developer, Role::Viewer]);

it('renames and rotates secrets after re-verifying', function () {
    [, $org] = actingAsMember(Role::Admin);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create(['name' => 'Old', 'credentials' => ['token' => 'old-token']]);
    Http::fake(['api.hetzner.cloud/v1/locations*' => Http::response(['locations' => []])]);

    $this->patch("/providers/{$credential->id}", ['name' => 'New', 'credentials' => ['token' => 'new-token']])
        ->assertSessionHasNoErrors();

    expect($credential->fresh()?->name)->toBe('New')
        ->and($credential->fresh()?->credentials)->toBe(['token' => 'new-token']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new-token'));
    expect(json_encode(auditRows()))->not->toContain('new-token')->toContain('provider_credential.updated');
});

it('keeps the old secret when rotation fails verification', function () {
    [, $org] = actingAsMember(Role::Admin);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create(['credentials' => ['token' => 'old-token']]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'unable to authenticate']], 401)]);

    $this->patch("/providers/{$credential->id}", ['credentials' => ['token' => 'bad']])->assertSessionHasErrors('credentials');

    expect($credential->fresh()?->credentials)->toBe(['token' => 'old-token']);
});

it('verifies a credential and records failures', function () {
    [, $org] = actingAsMember(Role::Admin);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create();
    Http::fake(['*' => Http::response(['error' => ['message' => 'token revoked']], 401)]);

    $this->post("/providers/{$credential->id}/verify")->assertRedirect();

    expect($credential->fresh()?->status->value)->toBe('invalid')
        ->and($credential->fresh()?->last_error)->toContain('token revoked');
});

it('does not invalidate a credential on transient outages', function () {
    [, $org] = actingAsMember(Role::Admin);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create();
    Http::fake(['*' => Http::response(['error' => ['message' => 'maintenance']], 503)]);

    $this->post("/providers/{$credential->id}/verify")->assertRedirect();

    expect($credential->fresh()?->status->value)->toBe('active')
        ->and($credential->fresh()?->last_error)->toContain('maintenance');
});

it('removes credentials and dispatches an event', function () {
    Event::fake([ProviderCredentialRemoved::class]);
    [, $org] = actingAsMember(Role::Owner);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create();

    $this->delete("/providers/{$credential->id}")->assertRedirect();

    expect(ProviderCredential::query()->count())->toBe(0);
    Event::assertDispatched(ProviderCredentialRemoved::class, fn ($e) => $e->credentialId === $credential->id);
    expect(collect(auditRows())->pluck('action'))->toContain('provider_credential.deleted');
});

it('404s on credentials of another organization', function () {
    actingAsMember(Role::Owner);
    $foreign = ProviderCredential::factory()->forOrganization('01JOTHERORGAAAAAAAAAAAAAAA')->create();

    $this->patch("/providers/{$foreign->id}", ['name' => 'hijack'])->assertNotFound();
    $this->delete("/providers/{$foreign->id}")->assertNotFound();
    $this->getJson("/providers/{$foreign->id}/regions")->assertNotFound();
});
