<?php

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Network\Contracts\Firewalls;
use Falak\Servers\Application\Actions\CreateServer;
use Falak\Servers\Application\ServerFacts;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../ControlPlane/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'app.url' => 'https://panel.falak.test']);
    $this->dir = host_channel();
    [$this->owner, $this->organization] = actingAsMember(Role::Owner);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

function this_machine_offer(object $test): mixed
{
    $offer = false;
    $test->get('/servers/create')->assertOk()->assertInertia(function (Assert $page) use (&$offer) {
        $offer = $page->toArray()['props']['thisMachine'];
    });

    return $offer;
}

/** @return array<string, mixed> */
function this_machine_input(array $overrides = []): array
{
    return ['name' => 'this-machine', 'type' => 'app', 'provider' => 'custom', 'control_plane_host' => true, ...$overrides];
}

it('adds the control plane\'s machine as a server through the host channel', function () {
    config(['servers.control_plane_reserve_mb' => 2048]);
    expect(this_machine_offer($this))->toBe(['reserve_mb' => 2048, 'allowed' => true, 'hint' => null]);

    $this->post('/servers', this_machine_input())->assertSessionHasNoErrors()->assertRedirect();

    $server = Server::query()->where('name', 'this-machine')->sole();
    $request = json_decode((string) file_get_contents("{$this->dir}/request.json"), true);

    expect($server->control_plane_host)->toBeTrue()
        ->and($server->provider)->toBe('custom')
        ->and($request)->toMatchArray(['action' => 'local-server', 'requested_by' => $this->owner->id])
        ->and($request['install_url'])->toStartWith('https://panel.falak.test/install/')
        ->and($server->install_command)->toContain($request['install_url'])
        ->and(AuditEntry::query()->where('action', 'server.created')->sole()->context)->toMatchArray(['control_plane_host' => true])
        ->and(app(ServerDirectory::class)->find($server->id))->toMatchArray(['controlPlaneHost' => true, 'reservedMemoryMb' => 2048]);

    // Once per install.
    expect(this_machine_offer($this))->toBeNull();
    $this->post('/servers', this_machine_input(['name' => 'again']))->assertForbidden();

    $this->get("/servers/{$server->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('server.control_plane_host', true)
        ->where('server.reserved_memory_mb', 2048));
});

it('stays disabled until the operator allowed it on the host', function () {
    $channel = json_decode((string) file_get_contents("{$this->dir}/channel.json"), true);
    file_put_contents("{$this->dir}/channel.json", json_encode([...$channel, 'local_server_allowed' => false]));

    expect(this_machine_offer($this))->toBe(['reserve_mb' => 1536, 'allowed' => false, 'hint' => CreateServer::RUN_ALLOW])
        ->and(CreateServer::RUN_ALLOW)->toContain('sudo falak-ctl local-server allow');

    $this->post('/servers', this_machine_input())->assertSessionHasErrors(['control_plane_host' => CreateServer::RUN_ALLOW]);
    expect(Server::query()->count())->toBe(0)->and(file_exists("{$this->dir}/request.json"))->toBeFalse();
});

it('tells the operator to run falak-ctl up when the units are not installed', function () {
    unlink("{$this->dir}/channel.json");

    expect(fn () => app(CreateServer::class)($this->organization->id, $this->owner->id, this_machine_input()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['control_plane_host'][0])->toBe(CreateServer::RUN_UP));
    expect(CreateServer::RUN_UP)->toContain('sudo falak-ctl up');
});

it('retries the install on this host while the agent has not enrolled', function () {
    $this->post('/servers', this_machine_input())->assertSessionHasNoErrors();
    $server = Server::query()->sole();
    $first = json_decode((string) file_get_contents("{$this->dir}/request.json"), true);

    // The host ran it and it failed.
    unlink("{$this->dir}/request.json");
    host_status($this->dir, ['id' => $first['id'], 'action' => 'local-server', 'state' => 'failed', 'exit_code' => 1]);

    $this->get("/servers/{$server->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('server.local_server.can_retry', true)
        ->where('server.local_server.manual_command', 'sudo falak-ctl local-server enable --install-url '.$first['install_url']));

    $this->post("/servers/{$server->id}/local-server/retry")->assertSessionHasNoErrors()->assertRedirect();

    $second = json_decode((string) file_get_contents("{$this->dir}/request.json"), true);
    expect($second)->toMatchArray(['action' => 'local-server'])
        ->and($second['install_url'])->not->toBe($first['install_url'])
        ->and($server->refresh()->install_command)->toContain($second['install_url'])
        ->and(AuditEntry::query()->where('action', 'server.local_server_retried')->sole()->context)->toBe(['request_id' => $second['id']]);

    // Pending now: the button waits.
    $this->post("/servers/{$server->id}/local-server/retry")->assertSessionHasErrors('local_server');
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page) => $page->where('server.local_server.can_retry', false));

    // Not for developers, not for other servers.
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    $this->post("/servers/{$server->id}/local-server/retry")->assertForbidden();
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page) => $page->where('server.local_server', null));

    $this->actingAs($this->owner);
    $other = Server::factory()->create(['organization_id' => $this->organization->id]);
    $this->post("/servers/{$other->id}/local-server/retry")->assertForbidden();
});

it('is only for the operator organization\'s owners and admins, with the host channel', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    expect(this_machine_offer($this))->toBeNull();
    $this->post('/servers', this_machine_input())->assertForbidden();

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin);
    expect(this_machine_offer($this))->not->toBeNull();

    // No host channel (falak-ctl older than v0.23).
    $this->actingAs($this->owner);
    $dir = config('controlplane.host_channel_dir');
    config(['controlplane.host_channel_dir' => $this->dir.'/missing']);
    expect(this_machine_offer($this))->toBeNull();
    $this->post('/servers', this_machine_input())->assertForbidden();

    config(['controlplane.host_channel_dir' => $dir]);
    // Another organization on a shared install.
    config(['recovery.operator_organization' => $this->organization->id]);
    [$other] = memberOf(null, Role::Owner);
    $this->actingAs($other);
    expect(this_machine_offer($this))->toBeNull();
    $this->post('/servers', this_machine_input())->assertForbidden();

    expect(Server::query()->count())->toBe(0);
});

it('creates nothing while the host is busy', function () {
    host_status($this->dir, ['state' => 'running']);

    $this->post('/servers', this_machine_input())->assertSessionHasErrors(['control_plane_host' => 'The control plane\'s host is busy with another request; try again once it finishes.']);

    expect(Server::query()->count())->toBe(0)->and(file_exists("{$this->dir}/request.json"))->toBeFalse();
});

it('is a custom server only', function () {
    $this->post('/servers', this_machine_input(['provider' => 'hetzner']))->assertSessionHasErrors('control_plane_host');
    expect(Server::query()->count())->toBe(0);
});

it('re-sends the edge and firewall once the control plane machine\'s agent reports its local edge', function () {
    $edge = Mockery::mock(EdgeRoutes::class);
    $firewalls = Mockery::mock(Firewalls::class);
    app()->instance(EdgeRoutes::class, $edge);
    app()->instance(Firewalls::class, $firewalls);
    $server = Server::factory()->create(['organization_id' => $this->organization->id, 'status' => ServerStatus::Active, 'control_plane_host' => true]);
    $other = Server::factory()->create(['organization_id' => $this->organization->id, 'status' => ServerStatus::Active]);

    $edge->shouldReceive('apply')->once()->with($server->id, true);
    $firewalls->shouldReceive('reapply')->once()->with($server->id);

    app(ServerFacts::class)->record($server, ['hostname' => 'cp']);
    app(ServerFacts::class)->record($server->refresh(), ['hostname' => 'cp', 'local_edge' => true]);
    // Already on: nothing again. Other servers never.
    app(ServerFacts::class)->record($server->refresh(), ['hostname' => 'cp', 'local_edge' => true]);
    app(ServerFacts::class)->record($other, ['hostname' => 'web', 'local_edge' => true]);
});
