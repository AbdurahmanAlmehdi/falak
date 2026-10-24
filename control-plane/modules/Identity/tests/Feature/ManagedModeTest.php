<?php

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Application\Notifications\QueuedVerifyEmail;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\User;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => Notification::fake());

function unverifiedMember(): array
{
    [$user, $organization] = memberOf(null, Role::Developer);
    $user->forceFill(['email_verified_at' => null])->save();

    return [$user->refresh(), $organization];
}

it('leaves unverified accounts alone on a self-hosted install', function () {
    [$user, $organization] = unverifiedMember();
    $token = app(CreateApiToken::class)($user, $organization->id, 'ci', ['*']);

    $this->actingAs($user)->get('/projects')->assertOk();
    $this->get('/cli/authorize')->assertOk();
    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();
});

it('sends no verification mail on a self-hosted sign-up', function () {
    $this->post('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect('/projects');
    Notification::assertNothingSent();
});

it('keeps unverified accounts on the verification notice when managed', function () {
    config(['controlplane.managed' => true]);
    [$user, $organization] = unverifiedMember();
    $token = app(CreateApiToken::class)($user, $organization->id, 'ci', ['*']);

    $this->actingAs($user)->get('/projects')->assertRedirect('/verify-email');
    $this->get('/cli/authorize')->assertRedirect('/verify-email');
    $this->get('/settings/api-tokens')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertOk();
    $this->get('/settings/profile')->assertOk();
    $this->getJson('/projects')->assertForbidden();
    $this->post('/logout')->assertRedirect();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertForbidden()
        ->assertJson(['message' => 'Verify your email address to continue.']);
});

it('lets a managed account in once the link is opened', function () {
    config(['controlplane.managed' => true]);
    [$user] = unverifiedMember();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url)->assertRedirect('/projects?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->actingAs($user->fresh())->get('/projects')->assertOk();
});

it('mails a queued verification link on managed sign-up and throttles resends', function () {
    config(['controlplane.managed' => true]);
    User::factory()->create();

    $this->post('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password']);
    $user = User::query()->where('email', 'new@example.com')->sole();

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
    expect($user->hasVerifiedEmail())->toBeFalse();
    $this->get('/projects')->assertRedirect('/verify-email');

    foreach (range(1, 6) as $ignored) {
        $this->post('/email/verification-notification')->assertRedirect();
    }
    $this->post('/email/verification-notification')->assertTooManyRequests();
});

it('marks accounts from before verification as verified', function () {
    $user = User::factory()->unverified()->create();
    $verified = User::factory()->create(['email_verified_at' => now()->subYear()]);

    (require base_path('modules/Identity/database/migrations/2027_01_28_100001_mark_existing_identity_users_verified.php'))->up();

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($verified->fresh()->email_verified_at->isSameDay(now()->subYear()))->toBeTrue();
});

it('resolves the Resend mailer from RESEND_API_KEY (services.resend.key)', function () {
    config(['services.resend.key' => 're_test_not_a_real_key']);

    expect(Mail::mailer('resend')->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});
