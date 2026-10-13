<?php

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Application\CliLogins;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\CliLogin;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{device_code: string, user_code: string, verification_uri_complete: string} */
function startCliLogin(string $client = 'ada-laptop'): array
{
    return test()->postJson('/api/v1/cli/login', ['client_name' => $client])->assertCreated()->json('data');
}

function pollCliLogin(string $deviceCode): TestResponse
{
    Carbon::setTestNow(Carbon::now()->addSeconds(CliLogins::INTERVAL_SECONDS)); // stay under slow_down

    return test()->postJson('/api/v1/cli/login/token', ['device_code' => $deviceCode]);
}

it('logs the CLI in through the browser and hands the token over exactly once', function () {
    $started = startCliLogin();
    expect($started['user_code'])->toMatch('/^[B-DF-HJ-NP-TV-XZ]{4}-[B-DF-HJ-NP-TV-XZ]{4}$/')
        ->and($started['verification_uri_complete'])->toEndWith('/cli/authorize/'.$started['user_code'])
        ->and(CliLogin::query()->sole()->device_code_hash)->not->toBe($started['device_code']);

    pollCliLogin($started['device_code'])->assertStatus(400)->assertJson(['error' => 'authorization_pending']);

    [$user, $organization] = actingAsMember(Role::Developer);
    // The code may be typed in any case, with or without the dash.
    $this->get('/cli/authorize/'.strtolower(str_replace('-', '', $started['user_code'])))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Identity/cli/authorize', false)->where('login.client_name', 'ada-laptop'));

    $this->withSession(['identity.reauthenticated_at' => time()])
        ->post('/cli/authorize/'.$started['user_code'], ['organization_id' => $organization->id])
        ->assertRedirect('/cli/authorize')->assertSessionHas('cliLoginAnswered', 'approved');

    $response = pollCliLogin($started['device_code'])->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.organization.id', $organization->id);
    $plain = $response->json('data.token');

    $token = PersonalAccessToken::query()->sole();
    expect($token->name)->toBe('CLI — ada-laptop')
        ->and($token->organization_id)->toBe($organization->id)
        ->and($token->abilities)->toBe(['*'])
        ->and($token->idle_expiry_days)->toBe(CliLogins::IDLE_EXPIRY_DAYS);

    // The token works, and a second collection gets nothing.
    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email', $user->email);
    pollCliLogin($started['device_code'])->assertStatus(400)->assertJson(['error' => 'expired_token']);
    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'cli_login.approved')->exists())->toBeTrue();
});

it('needs a fresh password confirmation to approve and comes back to the same page', function () {
    $started = startCliLogin();
    [, $organization] = actingAsMember(Role::Viewer);

    $this->get('/cli/authorize/'.$started['user_code'])->assertOk(); // the page the browser posts from
    $this->post('/cli/authorize/'.$started['user_code'], ['organization_id' => $organization->id])->assertRedirect('/confirm-password');
    expect(session('url.intended'))->toEndWith('/cli/authorize/'.$started['user_code'])
        ->and(CliLogin::query()->sole()->status)->toBe('pending');
});

it('refuses an organization the user is not a member of', function () {
    $started = startCliLogin();
    actingAsMember(Role::Owner);
    [$stranger] = memberOf(null, Role::Owner);
    $other = app(CreateOrganization::class)($stranger, 'Not yours');

    $this->withSession(['identity.reauthenticated_at' => time()])
        ->post('/cli/authorize/'.$started['user_code'], ['organization_id' => $other->id])
        ->assertSessionHasErrors('organization_id');
    expect(CliLogin::query()->sole()->status)->toBe('pending');
});

it('stops the CLI when the login is denied', function () {
    $started = startCliLogin();
    actingAsMember(Role::Owner);

    $this->post('/cli/authorize/'.$started['user_code'].'/deny')->assertSessionHas('cliLoginAnswered', 'denied');
    pollCliLogin($started['device_code'])->assertStatus(400)->assertJson(['error' => 'access_denied']);
    // A denied code can't be approved afterwards.
    $this->get('/cli/authorize/'.$started['user_code'])->assertInertia(fn (Assert $page) => $page->where('login', null));
});

it('expires unanswered logins and unknown device codes', function () {
    $started = startCliLogin();
    Carbon::setTestNow(Carbon::now()->addSeconds(CliLogins::TTL_SECONDS + 1));
    pollCliLogin($started['device_code'])->assertStatus(400)->assertJson(['error' => 'expired_token']);
    pollCliLogin('not-a-device-code')->assertStatus(400)->assertJson(['error' => 'expired_token']);

    [, $organization] = actingAsMember(Role::Owner);
    $this->withSession(['identity.reauthenticated_at' => time()])
        ->post('/cli/authorize/'.$started['user_code'], ['organization_id' => $organization->id])
        ->assertSessionHasErrors('code');
});

it('asks a CLI that polls too fast to slow down', function () {
    $started = startCliLogin();
    $this->postJson('/api/v1/cli/login/token', ['device_code' => $started['device_code']])->assertJson(['error' => 'authorization_pending']);
    $this->postJson('/api/v1/cli/login/token', ['device_code' => $started['device_code']])->assertJson(['error' => 'slow_down']);
});

it('slides a CLI token\'s expiry on use and revokes it on logout', function () {
    [$user, $organization] = memberOf(null, Role::Developer);
    $token = app(CreateApiToken::class)($user, $organization->id, 'CLI — x', ['*'], Carbon::now()->addDays(90));
    $token->accessToken->forceFill(['idle_expiry_days' => 90])->save();

    Carbon::setTestNow(Carbon::now()->addDays(60));
    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();
    expect($token->accessToken->fresh()->expires_at->isAfter(Carbon::now()->addDays(89)))->toBeTrue();

    $this->withToken($token->plainTextToken)->deleteJson('/api/v1/me/token')->assertNoContent();
    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'api_token.revoked')->exists())->toBeTrue();
});

it('never slides a CLI token past a year after it was created', function () {
    [$user, $organization] = memberOf(null, Role::Developer);
    $token = app(CreateApiToken::class)($user, $organization->id, 'CLI — x', ['*'], Carbon::now()->addDays(90));
    $token->accessToken->forceFill(['idle_expiry_days' => 90])->save();
    $created = $token->accessToken->created_at;

    foreach ([80, 160, 240, 320] as $day) {
        Carbon::setTestNow($created->copy()->addDays($day));
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();
        auth()->forgetGuards();
    }
    expect($token->accessToken->fresh()->expires_at->equalTo($created->copy()->addYear()))->toBeTrue();
    Carbon::setTestNow($created->copy()->addYear()->addDay());
    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('expires a CLI token left unused', function () {
    [$user, $organization] = memberOf(null, Role::Developer);
    $token = app(CreateApiToken::class)($user, $organization->id, 'CLI — x', ['*'], Carbon::now()->addDays(90));
    $token->accessToken->forceFill(['idle_expiry_days' => 90])->save();

    Carbon::setTestNow(Carbon::now()->addDays(91));
    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});
