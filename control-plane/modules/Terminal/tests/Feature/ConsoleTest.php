<?php

use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Terminal\Application\Actions\CloseSession;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Events\TerminalSessionOpened;
use Falak\Terminal\Infrastructure\RelayTokens;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization, ['name' => 'main-1']);
    Http::fake(['relay:8080/*' => Http::response(null, 204)]);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'app.url' => 'https://panel.example.com', 'terminal.relay.enabled' => false, 'terminal.relay.url' => null]);
});

/**
 * @param  list<Server>  $servers  the first is the leader
 * @param  array<string, mixed>  $attributes
 */
function console_site(Organization $organization, array $servers, array $attributes = []): Site
{
    $site = Site::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel,
        'php_version' => '8.4',
        'web_directory' => 'public',
        'unix_user' => 'shop',
        'isolated' => true,
        'deploy_script' => '$FALAK_FETCH',
        'laravel' => new LaravelSettings,
        ...$attributes,
    ]);

    foreach (array_values($servers) as $index => $server) {
        SiteTarget::query()->create([
            'site_id' => $site->id,
            'server_id' => $server->id,
            'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member,
            'status' => TargetStatus::Ready,
        ]);
    }

    return $site->load('targets');
}

/**
 * @param  list<string>  $services
 */
function console_compose_site(Organization $organization, Server $server, array $services, array $decisions = []): Site
{
    $yaml = "services:\n".implode('', array_map(fn (string $s) => "  {$s}:\n    image: nginx:1.27\n", $services));

    return console_site($organization, [$server], [
        'runtime' => SiteRuntime::Compose,
        'build_mode' => BuildMode::Docker,
        'framework' => Framework::Docker,
        'php_version' => null,
        'compose_source' => ComposeSource::Repo,
        'compose_snapshot' => $yaml,
        'compose_services' => $decisions ?: null,
    ]);
}

/** The agent on $server reports feature terminal.v2 (or none). */
function console_agent(Server $server, bool $v2 = true): void
{
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $server->organization_id, 'facts' => ['features' => $v2 ? ['terminal.v2'] : []]]);
}

function console_session(): TerminalSession
{
    return TerminalSession::query()->latest('id')->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function console_payload(FakeAgentGateway $agents): array
{
    return $agents->last('terminal.open')['payload'];
}

/** The claims of a relay token, after checking its signature. */
function console_claims(string $token): array
{
    [$body, $signature] = explode('.', $token);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, app(RelayTokens::class)->key(), true)), '+/', '-_'), '=');
    expect($signature)->toBe($expected);

    return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
}

function console_api_token(User $user, string $organizationId, array $abilities = ['*']): string
{
    $token = $user->createToken('cli', $abilities);
    $token->accessToken->forceFill(['organization_id' => $organizationId])->save();
    auth()->forgetGuards();

    return $token->plainTextToken;
}

// ---- opening -----------------------------------------------------------------------------------------------

it('opens a native site console as the site user in its current release with its variables', function () {
    Event::fake([TerminalSessionOpened::class]);
    $site = console_site($this->organization, [$this->server]);

    $this->post("/sites/{$site->id}/console")->assertRedirect(route('terminal.sessions.show', $session = console_session()));

    $payload = console_payload($this->agents);
    expect($payload)->toMatchArray([
        'session_id' => $session->id,
        'user' => 'shop',
        'shell' => '/bin/bash',
        'cwd' => '/srv/falak/sites/shop/current',
    ])
        ->and($payload)->not->toHaveKeys(['container', 'relay_token'])
        ->and($payload['env'])->toMatchArray(['FALAK_SITE' => 'shop', 'FALAK_CURRENT_DIR' => '/srv/falak/sites/shop/current', 'FALAK_SERVER_ID' => strtoupper($this->server->id), 'TERM' => 'xterm-256color'])
        ->and($session->only(['target_type', 'target_id', 'target_name', 'container', 'relayed', 'unix_user', 'server_id', 'server_name']))->toBe([
            'target_type' => 'site', 'target_id' => $site->id, 'target_name' => 'Shop', 'container' => null, 'relayed' => false,
            'unix_user' => 'shop', 'server_id' => $this->server->id, 'server_name' => 'main-1',
        ]);

    $audit = AuditEntry::query()->where('action', 'terminal.session_opened')->where('subject_id', $session->id)->sole();
    expect($audit->context)->toMatchArray(['target_type' => 'site', 'target_id' => $site->id, 'target_name' => 'Shop']);
    Event::assertDispatched(TerminalSessionOpened::class, fn ($e) => $e->targetType === 'site' && $e->targetId === $site->id && $e->targetName === 'Shop');

    // The index and the session page show the target.
    $this->get('/terminal')->assertOk()->assertInertia(fn ($page) => $page->where('sessions.0.target_type', 'site')->where('sessions.0.target_name', 'Shop')->where('sessions.0.relayed', false));
    $this->get("/terminal/sessions/{$session->id}")->assertOk()->assertInertia(fn ($page) => $page->where('session.target_name', 'Shop')->where('session.unix_user', 'shop'));
});

it('opens the console on a chosen site server, by id or name, and refuses others', function () {
    $second = terminal_server($this->organization, ['name' => 'main-2']);
    $stranger = terminal_server($this->organization, ['name' => 'elsewhere']);
    $site = console_site($this->organization, [$this->server, $second]);

    $this->post("/sites/{$site->id}/console", ['server_id' => $second->id])->assertRedirect();
    expect(console_session()->server_id)->toBe($second->id)
        ->and(console_payload($this->agents)['env']['FALAK_ROLE'])->toBe('member');

    $this->post("/sites/{$site->id}/console", ['server_id' => 'main-2'])->assertRedirect();
    expect(console_session()->server_id)->toBe($second->id);

    $this->post("/sites/{$site->id}/console", ['server_id' => $stranger->id])->assertSessionHasErrors('server_id');
});

it('opens a docker site console in its container by the falak.site label', function () {
    console_agent($this->server);
    $site = console_site($this->organization, [$this->server], ['runtime' => SiteRuntime::Docker, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null]);

    $this->post("/sites/{$site->id}/console")->assertRedirect();

    $payload = console_payload($this->agents);
    expect($payload['container'])->toBe(['labels' => ['falak.site' => 'shop']])
        ->and($payload)->not->toHaveKeys(['user', 'shell', 'cwd', 'relay_token'])
        ->and($payload['env'])->toBe(['TERM' => 'xterm-256color'])
        ->and(console_session()->container)->toBe('docker:shop')
        ->and(console_session()->unix_user)->toBe('');
});

it('opens a compose console in a service; the service is required when the stack has several', function () {
    console_agent($this->server);
    $site = console_compose_site($this->organization, $this->server, ['web', 'worker', 'db'], ['db' => ['mode' => 'database', 'database_id' => 'x']]);

    $this->post("/sites/{$site->id}/console")->assertSessionHasErrors(['service' => 'Pick a service: web, worker.']);
    $this->post("/sites/{$site->id}/console", ['service' => 'db'])->assertSessionHasErrors('service');
    $this->post("/sites/{$site->id}/console", ['service' => 'worker'])->assertRedirect();

    expect(console_payload($this->agents)['container'])->toBe(['labels' => ['com.docker.compose.project' => 'shop', 'com.docker.compose.service' => 'worker']])
        ->and(console_session()->container)->toBe('compose:worker');

    $this->getJson("/sites/{$site->id}/console")->assertOk()->assertExactJson(['data' => [
        'servers' => [['id' => $this->server->id, 'name' => 'main-1', 'leader' => true]],
        'services' => ['web', 'worker'],
        'runtime' => 'compose',
    ]]);
});

it('picks the only service of a compose stack', function () {
    console_agent($this->server);
    $site = console_compose_site($this->organization, $this->server, ['app']);

    $this->post("/sites/{$site->id}/console")->assertRedirect();

    expect(console_payload($this->agents)['container']['labels']['com.docker.compose.service'])->toBe('app');
});

it('refuses function consoles', function () {
    console_agent($this->server);
    $site = console_site($this->organization, [$this->server], ['runtime' => SiteRuntime::Function, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null]);

    $this->post("/sites/{$site->id}/console")->assertSessionHasErrors(['site' => 'Functions run on demand; they have no console.']);
    $this->postJson("/sites/{$site->id}/console")->assertUnprocessable();
    $this->agents->assertNothingDispatched('terminal.open');
});

it('opens a database console in the instance container on its server', function () {
    console_agent($this->server);
    $instance = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-db']);

    $this->post("/databases/instances/{$instance->id}/console")->assertRedirect(route('terminal.sessions.show', $session = console_session()));

    expect(console_payload($this->agents)['container'])->toBe(['name' => "falak-db-{$instance->id}"])
        ->and($this->agents->last('terminal.open')['handle']->serverId)->toBe($this->server->id)
        ->and($session->only(['target_type', 'target_id', 'target_name', 'container']))->toBe([
            'target_type' => 'database', 'target_id' => $instance->id, 'target_name' => 'main-db', 'container' => "docker:falak-db-{$instance->id}",
        ]);

    $instance->forceFill(['status' => InstanceStatus::Pending])->save();
    $this->post("/databases/instances/{$instance->id}/console")->assertSessionHasErrors('instance');
});

it('refuses container consoles on agents without terminal.v2 but opens native ones there', function () {
    console_agent($this->server, v2: false);
    config(['terminal.relay.enabled' => true]);
    $docker = console_site($this->organization, [$this->server], ['runtime' => SiteRuntime::Docker, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null]);
    $instance = databases_instance($this->organization, 'postgresql', $this->server);

    $this->post("/sites/{$docker->id}/console")->assertSessionHasErrors(['server' => 'The Falak agent on this server is too old for consoles; update it first.']);
    $this->post("/databases/instances/{$instance->id}/console")->assertSessionHasErrors('server');
    $this->agents->assertNothingDispatched('terminal.open');

    $native = console_site($this->organization, [$this->server], ['slug' => 'blog', 'unix_user' => 'blog', 'name' => 'Blog']);
    $this->post("/sites/{$native->id}/console")->assertRedirect();

    expect(console_payload($this->agents))->not->toHaveKey('relay_token')
        ->and(console_session()->relayed)->toBeFalse();
});

it('gives terminal.v2 agents a relay token when the relay is on', function () {
    console_agent($this->server);
    config(['terminal.relay.enabled' => true, 'terminal.max_duration' => 3600]);

    $session = terminal_open($this->server);

    $claims = console_claims(console_payload($this->agents)['relay_token']);
    expect($session->relayed)->toBeTrue()
        ->and($session->target_type)->toBe('server')
        ->and($claims)->toMatchArray(['v' => 1, 'sid' => $session->id, 'aud' => 'agent', 'sub' => $this->server->id, 'w' => true])
        ->and($claims['exp'])->toBeGreaterThanOrEqual(now()->getTimestamp() + 3900);

    // Relay off: no token even for terminal.v2 agents.
    config(['terminal.relay.enabled' => false]);
    terminal_open($this->server);
    expect(console_payload($this->agents))->not->toHaveKey('relay_token');
});

it('needs terminal.open and sight of the site or database', function () {
    $site = console_site($this->organization, [$this->server]);
    $instance = databases_instance($this->organization, 'postgresql', $this->server);

    [, $other] = memberOf();
    $foreign = console_site($other, [terminal_server($other)], ['slug' => 'foreign']);
    $this->post("/sites/{$foreign->id}/console")->assertNotFound();

    actingAsMember(Role::Developer, $this->organization);
    $this->post("/sites/{$site->id}/console")->assertForbidden();
    $this->post("/databases/instances/{$instance->id}/console")->assertForbidden();
    $this->getJson("/sites/{$site->id}/console")->assertForbidden();

    $this->agents->assertNothingDispatched('terminal.open');
});

// ---- tickets -------------------------------------------------------------------------------------------------

it('issues relay tickets for live relayed sessions the user may view', function () {
    console_agent($this->server);
    config(['terminal.relay.enabled' => true]);
    $session = terminal_open($this->server);

    $body = $this->postJson("/terminal/sessions/{$session->id}/ticket")->assertOk()->json();
    expect($body['writable'])->toBeTrue()
        ->and($body['url'])->toStartWith("wss://panel.example.com/terminal/v1/sessions/{$session->id}?ticket=");

    $claims = console_claims(substr($body['url'], strpos($body['url'], 'ticket=') + 7));
    expect($claims)->toMatchArray(['sid' => $session->id, 'aud' => 'client', 'sub' => $this->user->id, 'w' => true]);

    // A member who can attach but not type gets a read-only ticket once the session is shared.
    [$watcher] = memberOf($this->organization, Role::Developer);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    $watcher->givePermissionTo('terminal.attach');
    app(OrganizationAccess::class)->flush();
    $this->actingAs($watcher->refresh());

    $this->postJson("/terminal/sessions/{$session->id}/ticket")->assertForbidden();
    $session->forceFill(['shared' => true])->save();
    expect($this->postJson("/terminal/sessions/{$session->id}/ticket")->assertOk()->json('writable'))->toBeFalse();

    // Closed: no more tickets.
    app(CloseSession::class)($session->refresh());
    $this->actingAs($this->user);
    $this->postJson("/terminal/sessions/{$session->id}/ticket")->assertNotFound();
});

it('answers 404 for tickets of sessions without a relay', function () {
    $session = terminal_open($this->server);

    $this->postJson("/terminal/sessions/{$session->id}/ticket")->assertNotFound();
});

// ---- API -------------------------------------------------------------------------------------------------------

it('opens consoles through the API and answers relay URLs', function () {
    console_agent($this->server);
    config(['terminal.relay.enabled' => true]);
    $token = console_api_token($this->user, $this->organization->id);
    $site = console_compose_site($this->organization, $this->server, ['web', 'worker']);
    $instance = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-db']);

    $this->withToken($token)->getJson('/api/v1/sites/shop/console')->assertOk()
        ->assertJsonPath('data.services', ['web', 'worker'])
        ->assertJsonPath('data.runtime', 'compose')
        ->assertJsonPath('data.servers.0.leader', true);

    $this->withToken($token)->postJson('/api/v1/sites/shop/console', ['cols' => 120, 'rows' => 40])->assertUnprocessable()->assertJsonValidationErrors('service');

    $response = $this->withToken($token)->postJson('/api/v1/sites/shop/console', ['service' => 'web', 'server_id' => 'main-1', 'cols' => 120, 'rows' => 40])->assertCreated();
    $session = console_session();
    expect($response->json('data'))->toMatchArray([
        'session_id' => $session->id,
        'writable' => true,
        'server_id' => $this->server->id,
        'server_name' => 'main-1',
        'target_type' => 'site',
        'target_name' => 'Shop',
        'container' => 'compose:web',
    ])
        ->and($response->json('data.url'))->toStartWith("wss://panel.example.com/terminal/v1/sessions/{$session->id}?ticket=")
        ->and(console_payload($this->agents))->toMatchArray(['cols' => 120, 'rows' => 40]);

    $this->withToken($token)->postJson('/api/v1/databases/instances/main-db/console')->assertCreated()
        ->assertJsonPath('data.target_type', 'database')
        ->assertJsonPath('data.target_name', 'main-db')
        ->assertJsonPath('data.container', "docker:falak-db-{$instance->id}");

    $this->withToken($token)->postJson('/api/v1/servers/main-1/console', ['user' => 'falak'])->assertCreated()
        ->assertJsonPath('data.target_type', 'server')
        ->assertJsonPath('data.target_name', null)
        ->assertJsonPath('data.container', null);
    expect(console_payload($this->agents)['user'])->toBe('falak');

    $server = console_session();
    $this->withToken($token)->postJson("/api/v1/terminal/sessions/{$server->id}/ticket")->assertOk()
        ->assertJsonPath('data.writable', true)
        ->assertJsonStructure(['data' => ['url', 'writable']]);

    $this->withToken($token)->deleteJson("/api/v1/terminal/sessions/{$server->id}")->assertNoContent();
    expect($server->refresh()->isLive())->toBeFalse()
        ->and($this->agents->last('terminal.close')['payload'])->toBe(['session_id' => $server->id]);
    $this->withToken($token)->postJson("/api/v1/terminal/sessions/{$server->id}/ticket")->assertNotFound();
});

it('answers 409 on the API while the relay is off', function () {
    console_agent($this->server);
    console_site($this->organization, [$this->server]);
    $session = terminal_open($this->server);
    $token = console_api_token($this->user, $this->organization->id);

    foreach ([
        ['get', '/api/v1/sites/shop/console'],
        ['post', '/api/v1/sites/shop/console'],
        ['post', '/api/v1/servers/main-1/console'],
        ['post', "/api/v1/terminal/sessions/{$session->id}/ticket"],
        ['delete', "/api/v1/terminal/sessions/{$session->id}"],
    ] as [$method, $url]) {
        $this->withToken($token)->json($method, $url)->assertStatus(409)->assertJsonPath('message', 'The terminal relay is off on this installation.');
    }
});

it('refuses API consoles on agents without terminal.v2 and tokens without terminal.open', function () {
    config(['terminal.relay.enabled' => true]);
    console_site($this->organization, [$this->server]);

    $this->withToken(console_api_token($this->user, $this->organization->id))->postJson('/api/v1/sites/shop/console')
        ->assertUnprocessable()->assertJsonPath('errors.server.0', 'The Falak agent on this server is too old for consoles; update it first.');

    console_agent($this->server);
    $this->withToken(console_api_token($this->user, $this->organization->id, ['sites.view']))->postJson('/api/v1/sites/shop/console')->assertForbidden();
    $this->withToken(console_api_token($this->user, $this->organization->id, ['terminal.open']))->postJson('/api/v1/sites/shop/console')->assertNotFound();
    $this->withToken(console_api_token($this->user, $this->organization->id, ['terminal.open', 'sites.view']))->postJson('/api/v1/sites/shop/console')->assertCreated();
});
