<?php

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TailnetSites;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Identity\Contracts\Role;
use Falak\Network\Contracts\Tailnet;

/*
 * "Tailscale only" sites (docs/plans/TAILSCALE.md): the edge allowlist becomes the tailnet ranges, the site's own
 * entries and the control plane; turning it on needs every server of the site on the tailnet.
 */

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->web1 = edge_server($this->servers, $this->organization->id, ['name' => 'web-1']);
    $this->web2 = edge_server($this->servers, $this->organization->id, ['name' => 'web-2']);
    $this->site = edge_site($this->sites, $this->organization->id, [$this->web1->id, $this->web2->id]);
    Domain::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'intranet.example.com', 'is_primary' => true,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    $this->base = "/sites/{$this->site->id}";
    $this->tailnet = new class implements Tailnet
    {
        /** @var list<string> */
        public array $joined = [];

        /** @var list<string> */
        public array $pending = [];

        public function joined(string $serverId): bool
        {
            return in_array($serverId, $this->joined, true);
        }

        public function routesPending(string $serverId): bool
        {
            return in_array($serverId, $this->pending, true);
        }

        public function configured(string $organizationId): bool
        {
            return true;
        }

        public function controlPlaneAddresses(string $organizationId): array
        {
            return ['198.51.100.9'];
        }
    };
    app()->instance(Tailnet::class, $this->tailnet);
});

function tailnet_settings(array $data = []): array
{
    return ['allow_ips' => [], 'deny_ips' => [], 'encode' => true, 'tailnet_only' => true, ...$data];
}

it('refuses to turn on while a server of the site is not on the tailnet', function () {
    $this->tailnet->joined = [$this->web1->id];

    $this->put("{$this->base}/edge-settings", tailnet_settings())
        ->assertSessionHasErrors(['tailnet_only' => 'Put web-2 on the tailnet first (server → Network → Tailscale).']);
    expect(SiteSetting::for($this->site->id)->tailnet_only)->toBeFalse();

    // On the tailnet, but its route awaits approval.
    $this->tailnet->pending = [$this->web2->id];
    $this->put("{$this->base}/edge-settings", tailnet_settings())
        ->assertSessionHasErrors(['tailnet_only' => "Approve web-2's route in the Tailscale admin console first."]);
});

it('compiles the tailnet, the site’s own entries and the control plane into allow_ips', function () {
    $this->tailnet->joined = [$this->web1->id, $this->web2->id];

    $this->put("{$this->base}/edge-settings", tailnet_settings(['allow_ips' => ['203.0.113.0/24']]))->assertSessionHasNoErrors();

    $entry = fn () => edge_entry(edge_compile($this->web1->id), app(EdgeRoutes::class)->routeId($this->site->id));
    expect(SiteSetting::for($this->site->id)->tailnet_only)->toBeTrue()
        ->and($entry()['allow_ips'])->toBe(['100.64.0.0/10', 'fd7a:115c:a1e0::/48', '203.0.113.0/24', '198.51.100.9']);

    $this->getJson("{$this->base}/routing")->assertOk()
        ->assertJsonPath('data.settings.tailnet_only', true)
        ->assertJsonPath('data.tailnet.configured', true);

    // Already on: saving other settings needs no check (a server that left is the operator's call), off restores it.
    $this->tailnet->joined = [];
    $this->put("{$this->base}/edge-settings", tailnet_settings(['encode' => false]))->assertSessionHasNoErrors();
    $this->put("{$this->base}/edge-settings", tailnet_settings(['tailnet_only' => false]))->assertSessionHasNoErrors();

    expect($entry())->not->toHaveKey('allow_ips');
});

it('needs the load balancer of a balanced site on the tailnet too', function () {
    $lb = edge_server($this->servers, $this->organization->id, ['name' => 'lb-1']);
    LoadBalancer::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);
    $this->tailnet->joined = [$this->web1->id, $this->web2->id];

    $this->put("{$this->base}/edge-settings", tailnet_settings())
        ->assertSessionHasErrors(['tailnet_only' => 'Put lb-1 on the tailnet first (server → Network → Tailscale).']);
});

it('names the tailnet-only sites a server serves, also as their load balancer', function () {
    $lb = edge_server($this->servers, $this->organization->id, ['name' => 'lb-1']);
    LoadBalancer::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);
    $sites = app(TailnetSites::class);

    expect($sites->onServer($this->web1->id))->toBe([])->and($sites->onServer($lb->id))->toBe([]);

    SiteSetting::for($this->site->id)->forceFill(['tailnet_only' => true])->save();
    expect($sites->onServer($this->web1->id))->toBe([$this->site->name])
        ->and($sites->onServer($lb->id))->toBe([$this->site->name]);
});
