<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['app.url' => 'https://falak.example.com', 'source_control.github.app' => ['id' => null, 'slug' => null, 'private_key' => null, 'webhook_secret' => null]]);
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

function app_state(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

/** Fakes GitHub for an installation: details, token and a one-page repository list. */
function fake_installation(string $id = '555', string $account = 'acme', array $extra = []): void
{
    Http::fake(array_merge([
        "api.github.com/app/installations/{$id}/access_tokens" => Http::response(['token' => 'ghs_install', 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        "api.github.com/app/installations/{$id}" => Http::response(['id' => (int) $id, 'account' => ['login' => $account], 'target_type' => 'Organization']),
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => "{$account}/shop", 'default_branch' => 'main', 'private' => true, 'ssh_url' => '', 'clone_url' => "https://github.com/{$account}/shop.git"]]]),
    ], $extra));
}

/** Runs the manifest flow up to the callback, GitHub converting it into an app owned by $owner. */
function register_app(string $owner, string $type, ?string $organization = null): TestResponse
{
    $body = test()->postJson('/source-control/github-app/manifest', ['organization' => $organization])->assertOk()->json('data');
    Http::fake(["api.github.com/app-manifests/c-{$owner}/conversions" => Http::response([
        'id' => crc32($owner), 'slug' => "falak-{$owner}", 'name' => "Falak ({$owner})", 'owner' => ['login' => $owner, 'type' => $type],
        'html_url' => "https://github.com/apps/falak-{$owner}", 'client_id' => 'Iv1.abc', 'client_secret' => 'cs',
        'webhook_secret' => 'wh', 'pem' => sc_rsa_pem(),
    ], 201)]);

    return test()->get("/source-control/github-app/manifest/callback?code=c-{$owner}&state=".app_state($body['action']));
}

describe('manifest', function () {
    it('builds a least-privilege manifest for the personal account', function () {
        $body = $this->postJson('/source-control/github-app/manifest')->assertOk()->json('data');
        $manifest = $body['manifest'];

        expect($body['action'])->toStartWith('https://github.com/settings/apps/new?state=')
            ->and($manifest['name'])->toStartWith('Falak (falak.example.com) ')
            ->and(mb_strlen($manifest['name']))->toBeLessThanOrEqual(34)
            ->and($manifest['url'])->toBe('https://falak.example.com')
            ->and($manifest['hook_attributes']['url'])->toMatch('#^https://falak\.example\.com/api/webhooks/source-control/github-app/[0-9a-z]{26}$#')
            ->and($manifest['hook_attributes']['active'])->toBeTrue()
            ->and($manifest['redirect_url'])->toBe(route('source-control.github-app.manifest.callback'))
            ->and($manifest['setup_url'])->toBe(route('source-control.github-app.setup'))
            ->and($manifest['setup_on_update'])->toBeTrue()
            ->and($manifest['public'])->toBeFalse()
            ->and($manifest['default_permissions'])->toBe(['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'write', 'issues' => 'write', 'statuses' => 'write'])
            ->and($manifest['default_events'])->toBe(['push', 'pull_request', 'issue_comment']);
    });

    it('targets a GitHub organization and uses the webhook URL override', function () {
        config(['source_control.webhook_url' => 'https://hooks.example.com']);

        $body = $this->postJson('/source-control/github-app/manifest', ['organization' => 'acme-inc'])->assertOk()->json('data');

        expect($body['action'])->toStartWith('https://github.com/organizations/acme-inc/settings/apps/new?state=')
            ->and($body['manifest']['hook_attributes']['url'])->toStartWith('https://hooks.example.com/api/webhooks/source-control/github-app/');
    });

    it('validates the organization name', function () {
        $this->postJson('/source-control/github-app/manifest', ['organization' => 'bad name/..'])->assertJsonValidationErrors('organization');
    });

    it('refuses when the operator configured an env app or the organization has one for that GitHub organization', function () {
        sc_github_app($this->organization->id);
        $this->postJson('/source-control/github-app/manifest', ['organization' => 'ACME'])->assertStatus(409)->assertJson(['message' => 'This organization already has a GitHub App for ACME.']);
        $this->postJson('/source-control/github-app/manifest', ['organization' => 'acme-labs'])->assertOk();
        $this->postJson('/source-control/github-app/manifest')->assertOk();

        GitHubApp::query()->delete();
        config(['source_control.github.app' => ['id' => '7', 'slug' => 'ops', 'private_key' => sc_rsa_pem()]]);
        $this->postJson('/source-control/github-app/manifest')->assertStatus(409)->assertJson(['message' => 'This Falak instance uses the GitHub App configured by its operator.']);
    });

    it('is only for owners and admins', function (Role $role) {
        actingAsMember($role, $this->organization);

        $this->postJson('/source-control/github-app/manifest')->assertForbidden();
        $this->get('/source-control/github-app/manifest/callback?code=x&state=y')->assertForbidden();
        $this->delete('/source-control/github-app/'.sc_github_app($this->organization->id)->id, ['name' => 'x'])->assertForbidden();
    })->with([Role::Developer, Role::Viewer]);
});

describe('manifest callback', function () {
    it('stores the converted app encrypted and continues to the installation', function () {
        $body = $this->postJson('/source-control/github-app/manifest', ['organization' => 'acme'])->json('data');
        $key = basename($body['manifest']['hook_attributes']['url']);

        Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response([
            'id' => 4242, 'slug' => 'falak-acme', 'name' => 'Falak (acme)', 'owner' => ['login' => 'acme', 'type' => 'Organization'],
            'html_url' => 'https://github.com/apps/falak-acme', 'client_id' => 'Iv1.abc', 'client_secret' => 'cs_secret',
            'webhook_secret' => 'wh_secret', 'pem' => sc_rsa_pem(),
        ], 201)]);

        $location = $this->get('/source-control/github-app/manifest/callback?code=abc123&state='.app_state($body['action']))
            ->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith('https://github.com/apps/falak-acme/installations/new?state=');

        $app = GitHubApp::query()->sole();
        expect($app->id)->toBe($key)
            ->and($app->organization_id)->toBe($this->organization->id)
            ->and($app->app_id)->toBe('4242')
            ->and($app->owner_type)->toBe('Organization')
            ->and($app->private_key)->toBe(sc_rsa_pem())
            ->and($app->webhook_secret)->toBe('wh_secret')
            ->and($app->client_secret)->toBe('cs_secret');

        $raw = DB::table('source_control_github_apps')->first();
        expect($raw->private_key)->not->toContain('PRIVATE KEY')
            ->and($raw->webhook_secret)->not->toBe('wh_secret')
            ->and($raw->client_secret)->not->toBe('cs_secret');

        // GitHub rejects a `[]` JSON body (what Http::post() sends by default) with 422.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! $r->hasHeader('Authorization') && $r->body() === '');

        // GitHub → setup URL with the installation state: the installation becomes a connection.
        fake_installation();
        $this->get('/source-control/github-app/setup?installation_id=555&setup_action=install&state='.app_state($location))
            ->assertRedirect('/settings/source-control')->assertSessionHasNoErrors();

        $connection = Connection::query()->sole();
        expect($connection->auth_type)->toBe('app')
            ->and($connection->github_app_id)->toBe($app->id)
            ->and($connection->installation_id)->toBe('555')
            ->and($connection->status)->toBe('active')
            ->and($connection->account)->toBe('acme')
            ->and($connection->name)->toBe('GitHub (acme)')
            ->and(GitHubClient::knownRepositoryCount($connection->id))->toBe(1);
    });

    it('rejects forged, replayed and foreign state', function () {
        $body = $this->postJson('/source-control/github-app/manifest')->json('data');
        Http::fake();

        $this->get('/source-control/github-app/manifest/callback?code=x&state=forged')->assertForbidden();
        $this->get('/source-control/github-app/manifest/callback?code=x&state='.app_state($body['action']))->assertForbidden();

        // State started by another admin of the same organization in this session.
        $body = $this->postJson('/source-control/github-app/manifest')->json('data');
        [$other] = memberOf($this->organization, Role::Admin);
        $this->actingAs($other)->get('/source-control/github-app/manifest/callback?code=x&state='.app_state($body['action']))->assertForbidden();

        Http::assertNothingSent();
        expect(GitHubApp::query()->count())->toBe(0);
    });

    it('reports an expired code', function () {
        $body = $this->postJson('/source-control/github-app/manifest')->json('data');
        Http::fake(['api.github.com/app-manifests/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->get('/source-control/github-app/manifest/callback?code=old&state='.app_state($body['action']))
            ->assertRedirect('/settings/source-control')->assertSessionHasErrors('github_app');
        expect(GitHubApp::query()->count())->toBe(0);
    });

    it("passes GitHub's validation message through", function () {
        $body = $this->postJson('/source-control/github-app/manifest')->json('data');
        Http::fake(['api.github.com/app-manifests/*' => Http::response(['message' => 'Name has already been taken'], 422)]);

        $this->get('/source-control/github-app/manifest/callback?code=c&state='.app_state($body['action']))
            ->assertRedirect('/settings/source-control')->assertSessionHasErrors('github_app');

        expect(session('errors')->first('github_app'))->toContain('GitHub said: Name has already been taken');
    });
});

describe('installation setup', function () {
    beforeEach(function () {
        $this->githubApp = sc_github_app($this->organization->id);
    });

    it('requires a state for new installations', function () {
        Http::fake();

        $this->get('/source-control/github-app/setup?installation_id=555')->assertForbidden();
        $this->get('/source-control/github-app/setup?installation_id=555&state=forged')->assertForbidden();
        Http::assertNothingSent();
    });

    it('refreshes an existing installation when access changes on GitHub (setup_on_update, no state)', function () {
        $connection = sc_app_connection($this->organization->id, $this->githubApp);
        fake_installation();

        $this->get('/source-control/github-app/setup?installation_id=555&setup_action=update')
            ->assertRedirect('/settings/source-control')->assertSessionHas('success', 'GitHub repository access updated.');

        expect(Connection::query()->count())->toBe(1)
            ->and(GitHubClient::knownRepositoryCount($connection->id))->toBe(1);
    });

    it('returns to a same-site page after installing', function () {
        $location = $this->get('/source-control/connect/github-app?return_to=/projects')->headers->get('Location');
        expect($location)->toStartWith('https://github.com/apps/falak-acme/installations/new?state=');
        fake_installation();

        $this->get('/source-control/github-app/setup?installation_id=555&state='.app_state($location))->assertRedirect('/projects');

        $location = $this->get('/source-control/connect/github-app?return_to=//evil.example')->headers->get('Location');
        fake_installation('556');
        $this->get('/source-control/github-app/setup?installation_id=556&state='.app_state($location))->assertRedirect('/settings/source-control');
    });

    it('does not let another organization claim an installation', function () {
        [, $other] = memberOf(null, Role::Owner);
        sc_app_connection($other->id, 'env', '555');
        config(['source_control.github.app' => ['id' => '7', 'slug' => 'ops', 'private_key' => sc_rsa_pem()]]);

        $location = $this->get('/source-control/connect/github-app')->headers->get('Location');
        fake_installation();

        $this->get('/source-control/github-app/setup?installation_id=555&state='.app_state($location))->assertSessionHasErrors('github_app');
        expect(Connection::query()->where('organization_id', $this->organization->id)->count())->toBe(0);
    });

    it('revives a disconnected connection when the app is installed again on the same account', function () {
        $connection = sc_app_connection($this->organization->id, $this->githubApp, '555', ['status' => 'disconnected']);

        $location = $this->get('/source-control/connect/github-app')->headers->get('Location');
        fake_installation('777');
        $this->get('/source-control/github-app/setup?installation_id=777&state='.app_state($location))->assertSessionHasNoErrors();

        expect(Connection::query()->sole()->id)->toBe($connection->id)
            ->and($connection->refresh()->installation_id)->toBe('777')
            ->and($connection->status)->toBe('active');
    });

    it('acknowledges installation requests on organizations the user cannot administer', function () {
        $location = $this->get('/source-control/connect/github-app')->headers->get('Location');

        $this->get('/source-control/github-app/setup?setup_action=request&state='.app_state($location))
            ->assertRedirect('/settings/source-control')->assertSessionHas('success');
        expect(Connection::query()->count())->toBe(0);
    });

    it('prefers the env app over the registered one for new installations', function () {
        config(['source_control.github.app' => ['id' => '7', 'slug' => 'ops-app', 'private_key' => sc_rsa_pem()]]);

        $location = $this->get('/source-control/connect/github-app')->headers->get('Location');
        expect($location)->toStartWith('https://github.com/apps/ops-app/installations/new?');

        fake_installation();
        $this->get('/source-control/github-app/setup?installation_id=555&state='.app_state($location));
        expect(Connection::query()->sole()->github_app_id)->toBe('env');
    });
});

describe('app connections', function () {
    beforeEach(function () {
        $this->githubApp = sc_github_app($this->organization->id);
        $this->connection = sc_app_connection($this->organization->id, $this->githubApp);
        $this->gateway = app(SourceControlGateway::class);
    });

    it('lists every installation repository across pages, cached for searches', function () {
        Http::fake([
            'api.github.com/app/installations/555/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
            'api.github.com/installation/repositories?page=2' => Http::response(['repositories' => [['full_name' => 'acme/blog', 'default_branch' => 'trunk']]]),
            'api.github.com/installation/repositories*' => Http::response(
                ['repositories' => [['full_name' => 'acme/shop', 'default_branch' => 'main']]],
                200,
                ['Link' => '<https://api.github.com/installation/repositories?page=2>; rel="next"'],
            ),
        ]);

        expect(array_map(fn ($r) => $r->fullName, $this->gateway->repositories($this->connection->id)))->toBe(['acme/shop', 'acme/blog'])
            ->and(array_map(fn ($r) => $r->fullName, $this->gateway->repositories($this->connection->id, 'BLO')))->toBe(['acme/blog']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'installation/repositories') && $r->hasHeader('Authorization', 'Bearer ghs_x'));

        $this->getJson("/source-control/connections/{$this->connection->id}/repositories?search=shop")->assertOk()->assertJsonPath('data.0.full_name', 'acme/shop')->assertJsonCount(1, 'data');
    });

    it('clones over HTTPS with an installation token, never a deploy key', function () {
        fake_installation();
        $key = $this->gateway->installDeployKey($this->connection->id, 'acme/shop', 'Falak');

        $credentials = $this->gateway->checkoutCredentials($this->connection->id, 'acme/shop', $key->id);

        expect($credentials->usesSsh())->toBeFalse()
            ->and($credentials->url)->toBe('https://github.com/acme/shop.git')
            ->and($credentials->httpsUsername)->toBe('x-access-token')
            ->and($credentials->httpsPassword)->toBe('ghs_install')
            ->and($key->installed)->toBeFalse();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/keys'));
    });

    it('needs no per-repository webhook', function () {
        Http::fake();

        $webhook = $this->gateway->ensureWebhook($this->connection->id, 'acme/shop');

        expect($webhook->installed)->toBeTrue()
            ->and($webhook->url)->toBe('https://falak.example.com/api/webhooks/source-control/github-app/'.$this->githubApp->id)
            ->and(Webhook::query()->sole()->provider_hook_id)->toBeNull();

        $this->gateway->removeWebhook($this->connection->id, 'acme/shop');
        Http::assertNothingSent();
    });

    it('refuses tokens for suspended or uninstalled installations', function (string $status) {
        Http::fake();
        $this->connection->forceFill(['status' => $status])->save();

        expect(fn () => $this->gateway->checkoutCredentials($this->connection->id, 'acme/shop'))->toThrow(SourceControlException::class);
        Http::assertNothingSent();
    })->with(['suspended', 'disconnected']);

    it('keeps legacy env-app connections working', function () {
        config(['source_control.github.app' => ['id' => '7', 'slug' => 'ops', 'private_key' => sc_rsa_pem()]]);
        $legacy = sc_connection($this->organization->id, ProviderType::GitHub, 'app', ['installation_id' => '999']);
        Http::fake(['api.github.com/app/installations/999/access_tokens' => Http::response(['token' => 'ghs_legacy'], 201)]);

        expect($this->gateway->checkoutCredentials($legacy->id, 'acme/shop')->httpsPassword)->toBe('ghs_legacy');
    });

    it('uninstalls the app when the connection is disconnected', function () {
        Http::fake(['api.github.com/app/installations/555' => Http::response(null, 204)]);

        $this->delete("/source-control/connections/{$this->connection->id}", ['name' => $this->connection->name])->assertRedirect();

        expect(Connection::query()->count())->toBe(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/app/installations/555'));
    });

    it('deletes the app with its installations after confirmation', function () {
        Http::fake(['api.github.com/app/installations/555' => Http::response(null, 204)]);

        $this->delete("/source-control/github-app/{$this->githubApp->id}", ['name' => 'wrong'])->assertSessionHasErrors('name');
        $this->delete("/source-control/github-app/{$this->githubApp->id}", ['name' => 'Falak (acme)'])->assertRedirect('/settings/source-control');

        expect(GitHubApp::query()->count())->toBe(0)->and(Connection::query()->count())->toBe(0);
    });

    it('shows the app and its installations on the settings page', function () {
        $this->get('/settings/source-control')->assertInertia(fn ($page) => $page
            ->where('githubApp.apps.0.source', 'registered')
            ->where('githubApp.apps.0.name', 'Falak (acme)')
            ->where('githubApp.apps.0.settings_url', 'https://github.com/organizations/acme/settings/apps/falak-acme')
            ->where('githubApp.installations.0.account', 'acme')
            ->where('githubApp.installations.0.status', 'active')
            ->where('githubApp.installations.0.manage_url', 'https://github.com/organizations/acme/settings/installations/555')
            ->missing('githubApp.apps.0.webhook_secret'));
    });
});

describe('several apps', function () {
    it('registers one app per GitHub owner', function () {
        register_app('jane', 'User')->assertRedirect();
        register_app('falak-sh', 'Organization', 'falak-sh')->assertRedirect();

        expect(GitHubApp::query()->where('organization_id', $this->organization->id)->orderBy('owner_login')->pluck('owner_login')->all())->toBe(['falak-sh', 'jane']);

        // Same personal account again: only known after the conversion.
        register_app('Jane', 'User')->assertRedirect('/settings/source-control')->assertSessionHasErrors('github_app');
        expect(session('errors')->first('github_app'))->toBe('This organization already has a GitHub App owned by Jane.')
            ->and(GitHubApp::query()->count())->toBe(2);
    });

    it('installs the requested app, defaulting to the oldest', function () {
        sc_github_app($this->organization->id, ['created_at' => now()->subDay()]);
        $new = sc_github_app($this->organization->id, ['slug' => 'falak-jane', 'name' => 'Falak (jane)', 'owner_login' => 'jane', 'owner_type' => 'User']);
        [, $other] = memberOf(null, Role::Owner);
        $foreign = sc_github_app($other->id, ['slug' => 'falak-other']);

        expect($this->get("/source-control/connect/github-app?app={$new->id}")->headers->get('Location'))->toStartWith('https://github.com/apps/falak-jane/installations/new?state=')
            ->and($this->get('/source-control/connect/github-app')->headers->get('Location'))->toStartWith('https://github.com/apps/falak-acme/installations/new?state=');
        $this->get("/source-control/connect/github-app?app={$foreign->id}")->assertNotFound();
    });

    it('connects a new installation through the app its state was issued for', function () {
        sc_github_app($this->organization->id, ['created_at' => now()->subDay()]);
        $b = sc_github_app($this->organization->id, ['slug' => 'falak-jane', 'name' => 'Falak (jane)', 'owner_login' => 'jane', 'owner_type' => 'User']);

        $location = $this->get("/source-control/connect/github-app?app={$b->id}")->headers->get('Location');
        fake_installation('555', 'jane');
        $this->get('/source-control/github-app/setup?installation_id=555&state='.app_state($location))->assertSessionHasNoErrors();

        expect(Connection::query()->sole()->github_app_id)->toBe($b->id);
    });

    it('deletes only the addressed app', function () {
        $a = sc_github_app($this->organization->id);
        $b = sc_github_app($this->organization->id, ['slug' => 'falak-jane', 'name' => 'Falak (jane)', 'owner_login' => 'jane', 'owner_type' => 'User']);
        [, $other] = memberOf(null, Role::Owner);
        $foreign = sc_github_app($other->id, ['name' => 'Falak (jane)']);

        $this->delete("/source-control/github-app/{$foreign->id}", ['name' => 'Falak (jane)'])->assertNotFound();
        $this->delete("/source-control/github-app/{$b->id}", ['name' => 'Falak (jane)'])->assertRedirect('/settings/source-control');

        expect(GitHubApp::query()->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $foreign->id])->sort()->values()->all());
    });

    it('lists every app with its installations on the settings page', function () {
        $a = sc_github_app($this->organization->id, ['created_at' => now()->subDay()]);
        $b = sc_github_app($this->organization->id, ['slug' => 'falak-jane', 'name' => 'Falak (jane)', 'owner_login' => 'jane', 'owner_type' => 'User']);
        sc_app_connection($this->organization->id, $a);
        sc_app_connection($this->organization->id, $b, '556', ['account' => 'jane']);

        $this->get('/settings/source-control')->assertInertia(fn ($page) => $page
            ->has('githubApp.apps', 2)
            ->where('githubApp.apps.0.id', $a->id)
            ->where('githubApp.apps.0.install_url', route('source-control.github-app', ['app' => $a->id]))
            ->where('githubApp.apps.1.id', $b->id)
            ->where('githubApp.apps.1.owner', 'jane')
            ->where('githubApp.apps.1.settings_url', 'https://github.com/settings/apps/falak-jane')
            ->has('githubApp.installations', 2)
            ->where('githubApp.installations', fn ($installations) => collect($installations)->pluck('app_id', 'account')->sortKeys()->all() === ['acme' => $a->id, 'jane' => $b->id]));
    });
});
