<?php

use Falak\Identity\Contracts\Role;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'app-1']);
});

it('redirects the legacy firewall URL to the server tab', function () {
    $this->get("/network/servers/{$this->server->id}/firewall")->assertRedirect("/servers/{$this->server->id}/firewall")->assertStatus(301);
});

it('renders the firewall tab with the shared server header', function () {
    $this->get("/servers/{$this->server->id}/firewall")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/Firewall', false)
        ->where('server.id', $this->server->id)
        ->has('server.provider_label')
        ->has('server.agent'));
});

it('renders the private network tab with memberships and joinable networks', function () {
    $this->post('/network/private-networks', ['name' => 'backend'])->assertSessionHasNoErrors();
    $this->post('/network/private-networks', ['name' => 'metrics', 'cidr' => '10.91.0.0/24', 'listen_port' => 51821])->assertSessionHasNoErrors();
    $backend = PrivateNetwork::query()->where('name', 'backend')->firstOrFail();
    $this->post("/network/private-networks/{$backend->id}/members", ['server_id' => $this->server->id])->assertSessionHasNoErrors();

    $this->get("/servers/{$this->server->id}/private-network")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/ServerPrivateNetwork', false)
        ->where('server.name', 'app-1')
        ->has('memberships', 1)
        ->where('memberships.0.network.name', 'backend')
        ->has('availableNetworks', 1)
        ->where('availableNetworks.0.name', 'metrics')
        ->where('can.manage', true)
        ->where('can.join', true));
});

it('renders the network tab with the shared server header', function () {
    $this->get("/servers/{$this->server->id}/network")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/ServerNetwork', false)
        ->where('server.id', $this->server->id)
        ->has('server.agent'));
});

it('creates a network from the server tab and joins the server right away', function () {
    $this->from("/servers/{$this->server->id}/private-network")
        ->post('/network/private-networks', ['name' => 'mesh', 'server_id' => $this->server->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/servers/{$this->server->id}/private-network");

    $network = PrivateNetwork::query()->where('name', 'mesh')->firstOrFail();
    expect(PrivateNetworkMember::query()->where('network_id', $network->id)->where('server_id', $this->server->id)->exists())->toBeTrue();
});

it('hides the private network tab of other organizations', function () {
    [$stranger] = memberOf();

    $this->actingAs($stranger)->get("/servers/{$this->server->id}/private-network")->assertNotFound();
    $this->actingAs($stranger)->get("/servers/{$this->server->id}/network")->assertNotFound();
    $this->actingAs($stranger)->post('/network/private-networks', ['name' => 'x', 'server_id' => $this->server->id])->assertNotFound();
});
