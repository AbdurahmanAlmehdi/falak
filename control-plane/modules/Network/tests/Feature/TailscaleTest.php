<?php

use Falak\Edge\Contracts\TailnetSites;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Contracts\Tailnet;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\TailscaleNode;
use Falak\Network\Domain\Models\TailscaleSetting;
use Falak\Network\Infrastructure\FirewallCompiler;
use Falak\Servers\Events\ServerDeleted;
use Tests\Support\FakeAgentGateway;

/*
 * Tailscale (docs/plans/TAILSCALE.md): the organization's auth key, servers joining / leaving the tailnet through
 * net.tailscale.apply, the outcome on the node, and the firewall rules a node implies.
 */

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

const TS_KEY = 'tskey-auth-kAbCdEf1CNTRL-0123456789abcdef';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'Main 1', 'ipv4' => '62.171.130.153', 'ipv6' => '2a02:c207::1']);
});

function ts_agent(object $test, array $features = ['net.tailscale']): void
{
    $enrolled = fleet_enroll($test->organization->id, $test->server->id, ['features' => $features]);
    Certificate::query()->where('fingerprint', $enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);
}

function ts_join(object $test): TailscaleNode
{
    ts_agent($test);
    $test->put('/settings/tailscale', ['auth_key' => TS_KEY])->assertSessionHasNoErrors();
    $test->post("/network/servers/{$test->server->id}/tailscale")->assertSessionHasNoErrors();

    return TailscaleNode::query()->findOrFail($test->server->id);
}

it('saves the auth key encrypted and never shows it again', function () {
    $this->put('/settings/tailscale', ['auth_key' => 'not-a-key'])->assertSessionHasErrors('auth_key');
    $this->put('/settings/tailscale', ['auth_key' => TS_KEY])->assertSessionHasNoErrors();

    $setting = TailscaleSetting::query()->sole();
    expect($setting->auth_key)->toBe(TS_KEY)
        ->and($setting->getRawOriginal('auth_key'))->not->toContain('tskey-')
        ->and(app(Tailnet::class)->configured($this->organization->id))->toBeTrue();

    $this->get('/settings/tailscale')->assertOk()
        ->assertDontSee(TS_KEY)
        ->assertInertia(fn ($page) => $page->component('Network/Tailscale', false)->has('saved_key.saved_at')->where('can.manage', true));
});

it('joins a server: schema-valid payload with its routes, the key only in the payload', function () {
    $node = ts_join($this);

    $command = $this->agents->last('net.tailscale.apply', $this->server->id);
    expect(network_schema_errors('net.tailscale.apply', $command['payload']))->toBe([])
        ->and($command['payload'])->toMatchArray([
            'state' => 'present',
            'version' => '1.104.1',
            'url' => 'https://pkgs.tailscale.com/stable/tailscale_1.104.1_amd64.tgz',
            'sha256' => '108d1d96ecf410d305571e173516f27038f870919a9b89c914a167c6d33a4528',
            'auth_key' => TS_KEY,
            'hostname' => 'falak-main-1',
            'advertise_routes' => ['62.171.130.153/32', '2a02:c207::1/128'],
        ])
        ->and($node->status)->toBe(TailscaleNode::INSTALLING)
        ->and($node->command_id)->toBe($command['handle']->id)
        ->and(json_encode(AuditEntry::query()->get()->map->context))->not->toContain('tskey-')
        ->and(AuditEntry::query()->where('action', 'network.tailscale_joined')->sole()->context)->toMatchArray(['hostname' => 'falak-main-1']);
});

it('refuses to join without a key, or when the agent lacks net.tailscale', function () {
    ts_agent($this, []);
    $this->post("/network/servers/{$this->server->id}/tailscale")->assertSessionHasErrors(['server' => 'Save a Tailscale auth key first (Settings → Tailscale).']);

    $this->put('/settings/tailscale', ['auth_key' => TS_KEY]);
    $this->post("/network/servers/{$this->server->id}/tailscale")
        ->assertSessionHasErrors(['server' => 'The agent on Main 1 does not support Tailscale yet: upgrade the agent first.']);

    expect(TailscaleNode::query()->count())->toBe(0);
    $this->agents->assertNothingDispatched('net.tailscale.apply');
});

it('settles the node from the result, and records failures without the key', function () {
    $node = ts_join($this);
    $command = $this->agents->last('net.tailscale.apply', $this->server->id);

    $this->agents->succeed($command['handle'], ['changed' => true, 'active' => true, 'backend_state' => 'Running', 'version' => '1.104.1',
        'hostname' => 'falak-main-1', 'ipv4' => '100.101.102.103', 'ipv6' => 'fd7a:115c:a1e0::1', 'routes_approved' => true]);

    expect($node->refresh())->status->toBe(TailscaleNode::ACTIVE)
        ->ipv4->toBe('100.101.102.103')->ipv6->toBe('fd7a:115c:a1e0::1')->routes_approved->toBeTrue()->version->toBe('1.104.1')->error->toBeNull()
        ->and(app(Tailnet::class)->joined($this->server->id))->toBeTrue()
        ->and($this->agents->last('net.tailscale.apply')['payload']['auth_key'])->toBe('[forgotten]');

    // Active, but the route awaits approval in the admin console: not reachable yet.
    $node->forceFill(['routes_approved' => false])->save();
    expect(app(Tailnet::class)->joined($this->server->id))->toBeFalse()
        ->and(app(Tailnet::class)->routesPending($this->server->id))->toBeTrue();

    // Refresh: the key was refused this time.
    $this->post("/network/servers/{$this->server->id}/tailscale")->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('net.tailscale.apply')['handle'], ['changed' => false, 'active' => false, 'backend_state' => 'NeedsLogin', 'routes_approved' => false]);
    expect($node->refresh()->status)->toBe(TailscaleNode::ERROR)->and($node->error)->toContain('refused the auth key');

    $this->post("/network/servers/{$this->server->id}/tailscale");
    $this->agents->fail($this->agents->last('net.tailscale.apply')['handle'], 'tailscale up --auth-key '.TS_KEY.': exit status 1');
    expect($node->refresh()->status)->toBe(TailscaleNode::ERROR)
        ->and($node->error)->not->toContain(TS_KEY)
        ->and(app(Tailnet::class)->joined($this->server->id))->toBeFalse()
        ->and($this->agents->last('net.tailscale.apply')['payload']['auth_key'])->toBe('[forgotten]');

    // `tailscale up` refusing the key reads like the refused state.
    $this->post("/network/servers/{$this->server->id}/tailscale");
    $this->agents->fail($this->agents->last('net.tailscale.apply')['handle'], 'tailscale up: exit 1: backend error: invalid key: unable to validate API key');
    expect($node->refresh()->error)->toContain('refused the auth key');
});

it('leaves: dispatches absent, forgets the node and closes the firewall again', function () {
    ts_join($this);
    $rules = fn () => array_column(app(FirewallCompiler::class)->compile($this->server->id)['rules'], null, 'id');

    expect($rules()['tailscale-interface'])->toMatchArray(['action' => 'accept', 'protocol' => 'any', 'interface' => 'tailscale0'])
        ->and($rules()['tailscale-direct'])->toMatchArray(['action' => 'accept', 'protocol' => 'udp', 'ports' => ['41641']])
        ->and($rules()['tailscale-direct'])->not->toHaveKey('sources')
        ->and(network_schema_errors('net.firewall.apply', app(FirewallCompiler::class)->compile($this->server->id)))->toBe([]);

    $this->delete("/network/servers/{$this->server->id}/tailscale")->assertSessionHasNoErrors();

    expect($this->agents->last('net.tailscale.apply', $this->server->id)['payload'])->toBe(['state' => 'absent'])
        ->and(TailscaleNode::query()->count())->toBe(0)
        ->and($rules())->not->toHaveKey('tailscale-interface')
        ->and($rules())->not->toHaveKey('tailscale-direct')
        ->and($this->agents->last('net.firewall.apply', $this->server->id)['payload']['rules'])->not->toContain(fn ($r) => str_starts_with($r['id'], 'tailscale-'));
});

it('refuses to leave while the server serves a tailnet-only site', function () {
    ts_join($this);
    app()->instance(TailnetSites::class, new class implements TailnetSites
    {
        public function onServer(string $serverId): array
        {
            return ['intranet'];
        }
    });

    $this->delete("/network/servers/{$this->server->id}/tailscale")->assertSessionHasErrors(['server' => 'Turn off "Tailscale only" on intranet first: nobody could reach it.']);
    expect(TailscaleNode::query()->count())->toBe(1);
});

it('keeps the Tailscale port open: expected, never closed by a deny rule', function () {
    ts_join($this);
    $before = FirewallRule::query()->count();

    expect(app(Firewalls::class)->expectedPorts($this->server->id))->toContain('udp/41641')
        ->and(fn () => app(Firewalls::class)->denyPort($this->server->id, 'udp', 41641, 'close it'))
        ->toThrow(InvalidArgumentException::class, 'Tailscale uses it.')
        ->and(FirewallRule::query()->count())->toBe($before);
});

it('refuses to remove the key while servers are on the tailnet', function () {
    ts_join($this);

    $this->delete('/settings/tailscale')->assertSessionHasErrors('auth_key');
    expect(TailscaleSetting::query()->count())->toBe(1);

    $this->delete("/network/servers/{$this->server->id}/tailscale");
    $this->delete('/settings/tailscale')->assertSessionHasNoErrors();
    expect(TailscaleSetting::query()->count())->toBe(0);
});

it('needs network.tailscale to change anything', function () {
    actingAsMember(Role::Developer, $this->organization);

    $this->put('/settings/tailscale', ['auth_key' => TS_KEY])->assertForbidden();
    $this->post("/network/servers/{$this->server->id}/tailscale")->assertForbidden();
    $this->get('/settings/tailscale')->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
});

it('forgets nodes of deleted servers and the key of a deleted organization', function () {
    ts_join($this);

    ServerDeleted::dispatch($this->server->id, $this->organization->id, 'web', 'Main 1');
    expect(TailscaleNode::query()->count())->toBe(0);

    OrganizationDeleted::dispatch($this->organization->id);
    expect(TailscaleSetting::query()->count())->toBe(0);
});

it('lists the organization’s control-plane machine among a tailnet-only site’s sources, nothing else', function () {
    // Resolved panel / API hosts (maybe Cloudflare's) and another organization's control-plane machine stay out.
    config(['network.protection.control_plane_addresses' => ['198.51.100.200']]);
    network_server($this->organization, ['name' => 'This machine', 'ipv4' => '198.51.100.9', 'ipv6' => null, 'control_plane_host' => true]);
    network_server(Organization::factory()->create(), ['name' => 'Other machine', 'ipv4' => '198.51.100.10', 'ipv6' => null, 'control_plane_host' => true]);

    expect(app(Tailnet::class)->controlPlaneAddresses($this->organization->id))->toBe(['198.51.100.9']);
});

it('shows the node on the server network tab', function () {
    ts_join($this);

    $this->get("/servers/{$this->server->id}/network")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/ServerNetwork', false)
        ->where('tailscale.configured', true)
        ->where('tailscale.node.status', 'installing')
        ->where('tailscale.node.hostname', 'falak-main-1')
        ->where('tailscale.can.manage', true));
});
