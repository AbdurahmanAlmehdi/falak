<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Network\Application\Actions\SaveFirewallRule;
use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Events\FirewallApplied;
use Falak\Network\Events\FirewallApplyFailed;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->server = network_server($this->organization, ['name' => 'web-1']);
});

it('seeds default rules on first visit and applies a schema-valid full ruleset', function () {
    $this->get("/servers/{$this->server->id}/firewall")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/Firewall', false)
        ->has('rules', 3)
        ->where('rules.0.port', '22')
        ->where('rules.1.port', '80')
        ->where('rules.2.port', '443')
        ->where('can.manage', true));

    $command = $this->agents->last('net.firewall.apply', $this->server->id);

    expect(network_schema_errors('net.firewall.apply', $command['payload']))->toBe([])
        ->and($command['payload'])->toMatchArray(['input_policy' => 'drop', 'ssh_port' => 22, 'allow_icmp' => true])
        ->and(array_column($command['payload']['rules'], 'ports'))->toBe([['22'], ['80'], ['443']])
        ->and($command['payload']['rules'][0])->toMatchArray(['action' => 'accept', 'protocol' => 'tcp', 'comment' => 'SSH'])
        ->and($command['payload']['rules'][0])->not->toHaveKey('sources')
        ->and($command['handle']->idempotencyKey)->toBe("net.firewall:{$this->server->id}:1");

    // Visiting again neither re-seeds nor re-dispatches.
    $this->get("/servers/{$this->server->id}/firewall")->assertOk();
    expect(FirewallRule::query()->count())->toBe(3)
        ->and($this->agents->dispatched('net.firewall.apply'))->toHaveCount(1);
});

it('only opens SSH by default on servers that do not serve HTTP', function () {
    $db = network_server($this->organization, ['name' => 'db-1'], ServerType::Database);

    $this->get("/servers/{$db->id}/firewall")->assertOk()->assertInertia(fn ($page) => $page->has('rules', 1)->where('rules.0.port', '22'));
});

it('seeds and applies defaults when a server finishes provisioning', function () {
    ServerProvisioned::dispatch($this->server->id, $this->organization->id, 'web', 'web-1');

    expect(FirewallRule::query()->where('server_id', $this->server->id)->orderBy('position')->pluck('port')->all())->toBe(['22', '80', '443'])
        ->and(FirewallState::query()->find($this->server->id)->status)->toBe(ApplyStatus::Applying);

    ServerProvisioned::dispatch($this->server->id, $this->organization->id, 'web', 'web-1');
    expect(FirewallRule::query()->count())->toBe(3)
        ->and($this->agents->dispatched('net.firewall.apply'))->toHaveCount(1);
});

it('orders deny rules before allow rules and compiles ranges and sources', function () {
    $this->get("/servers/{$this->server->id}/firewall");

    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'App ports', 'action' => 'allow', 'protocol' => 'udp', 'port' => '8000-8100', 'source' => '10.0.0.0/8'])->assertSessionHasNoErrors();
    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'Block bad actor', 'action' => 'deny', 'protocol' => 'any', 'port' => '', 'source' => '2001:db8::/32'])->assertSessionHasNoErrors();

    $payload = $this->agents->last('net.firewall.apply')['payload'];

    expect(network_schema_errors('net.firewall.apply', $payload))->toBe([])
        ->and(array_column($payload['rules'], 'comment'))->toBe(['Block bad actor', 'SSH', 'HTTP', 'HTTPS', 'App ports'])
        ->and($payload['rules'][0])->toMatchArray(['action' => 'drop', 'protocol' => 'any', 'sources' => ['2001:db8::/32']])
        ->and($payload['rules'][0])->not->toHaveKey('ports')
        ->and($payload['rules'][4])->toMatchArray(['action' => 'accept', 'protocol' => 'udp', 'ports' => ['8000-8100'], 'sources' => ['10.0.0.0/8']])
        ->and(array_map(fn ($c) => $c['handle']->idempotencyKey, $this->agents->dispatched('net.firewall.apply')))
        ->toBe(["net.firewall:{$this->server->id}:1", "net.firewall:{$this->server->id}:2", "net.firewall:{$this->server->id}:3"]);

    expect(AuditEntry::query()->where('action', 'network.firewall_rule_created')->count())->toBe(2);
});

it('rejects invalid rules', function (array $input, string $field) {
    $this->post("/network/servers/{$this->server->id}/firewall/rules", [...['name' => 'x', 'action' => 'allow', 'protocol' => 'tcp'], ...$input])->assertSessionHasErrors($field);
})->with([
    [['port' => '70000'], 'port'],
    [['port' => '90-80'], 'port'],
    [['port' => 'http'], 'port'],
    [['source' => '10.0.0.0/40'], 'source'],
    [['source' => 'evil.example'], 'source'],
    [['action' => 'reject'], 'action'],
    [['protocol' => 'icmp'], 'protocol'],
    [['name' => ''], 'name'],
]);

it('does not re-dispatch an unchanged ruleset and uses a new key for A → B → A', function () {
    $apply = app(ApplyFirewall::class);
    $this->get("/servers/{$this->server->id}/firewall");
    $first = $this->agents->last('net.firewall.apply');

    // In flight with the same desired state: no duplicate.
    $apply($this->server->id);
    expect($this->agents->dispatched('net.firewall.apply'))->toHaveCount(1);

    $this->agents->succeed($first['handle'], ['changed' => true, 'ruleset_sha256' => str_repeat('a', 64)]);
    $apply($this->server->id);
    expect($this->agents->dispatched('net.firewall.apply'))->toHaveCount(1);

    $rule = FirewallRule::query()->where('port', '80')->firstOrFail();
    $this->put("/network/servers/{$this->server->id}/firewall/rules/{$rule->id}", ['name' => 'HTTP', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '8080']);
    $this->agents->succeed($this->agents->last('net.firewall.apply')['handle']);
    $this->put("/network/servers/{$this->server->id}/firewall/rules/{$rule->id}", ['name' => 'HTTP', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '80']);

    $all = $this->agents->dispatched('net.firewall.apply');
    expect($all)->toHaveCount(3)
        ->and($all[2]['payload'])->toBe($first['payload'])
        ->and($all[2]['handle']->idempotencyKey)->not->toBe($first['handle']->idempotencyKey);

    // Explicit re-apply always dispatches.
    $this->agents->succeed($all[2]['handle']);
    $this->post("/network/servers/{$this->server->id}/firewall/apply")->assertSessionHasNoErrors();
    expect($this->agents->dispatched('net.firewall.apply'))->toHaveCount(4);
});

it('records the outcome and alerts only when the firewall starts failing and when it recovers', function () {
    Event::fake([FirewallApplied::class, FirewallApplyFailed::class]);
    $this->get("/servers/{$this->server->id}/firewall");
    $command = $this->agents->last('net.firewall.apply');

    $this->agents->succeed($command['handle'], ['changed' => true, 'ruleset_sha256' => str_repeat('b', 64)]);

    $state = FirewallState::query()->findOrFail($this->server->id);
    expect($state->status)->toBe(ApplyStatus::Applied)
        ->and($state->applied_hash)->toBe($state->desired_hash)
        ->and($state->ruleset_sha256)->toBe(str_repeat('b', 64))
        ->and($state->applied_at)->not->toBeNull();

    // A routine successful apply is not an alert ("Firewall applied again" was noisy).
    Event::assertNotDispatched(FirewallApplied::class);

    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'Redis', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '6379', 'source' => '10.0.0.5']);
    $this->agents->fail($this->agents->last('net.firewall.apply')['handle'], 'nft: syntax error');

    expect($state->refresh()->status)->toBe(ApplyStatus::Failed)
        ->and($state->error)->toBe('nft: syntax error')
        ->and($state->failed_at)->not->toBeNull();
    Event::assertDispatchedTimes(FirewallApplyFailed::class, 1);

    // Still failing on the next attempt: no second alert.
    app(ApplyFirewall::class)($this->server->id, force: true);
    $this->agents->fail($this->agents->last('net.firewall.apply')['handle'], 'nft: syntax error');
    Event::assertDispatchedTimes(FirewallApplyFailed::class, 1);

    // Recovers: one recovery alert.
    app(ApplyFirewall::class)($this->server->id, force: true);
    $recovered = $this->agents->last('net.firewall.apply');
    $this->agents->succeed($recovered['handle'], ['changed' => true, 'ruleset_sha256' => str_repeat('c', 64)]);
    Event::assertDispatched(FirewallApplied::class, fn ($e) => $e->serverId === $this->server->id && $e->commandId === $recovered['handle']->id && $e->rulesetSha256 === str_repeat('c', 64));
    expect($state->refresh()->failed_at)->toBeNull();
});

it('ignores results of superseded commands', function () {
    $this->get("/servers/{$this->server->id}/firewall");
    $old = $this->agents->last('net.firewall.apply');
    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'Redis', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '6379']);

    $this->agents->succeed($old['handle']);

    expect(FirewallState::query()->findOrFail($this->server->id)->status)->toBe(ApplyStatus::Applying);
});

it('marks the firewall failed when the agent is not connected', function () {
    $this->agents->unavailable($this->server->id);

    $this->get("/servers/{$this->server->id}/firewall")->assertOk();

    $state = FirewallState::query()->findOrFail($this->server->id);
    expect($state->status)->toBe(ApplyStatus::Failed)->and($state->error)->toContain('not connected');
});

it('waits for inactive servers to be provisioned', function () {
    $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();

    $this->get("/servers/{$this->server->id}/firewall")->assertOk();
    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'x', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '9000']);

    $this->agents->assertNothingDispatched();
    expect(FirewallState::query()->findOrFail($this->server->id)->status)->toBe(ApplyStatus::Pending);
});

it('deletes rules and forgets deleted servers', function () {
    $this->get("/servers/{$this->server->id}/firewall");
    $rule = FirewallRule::query()->where('port', '443')->firstOrFail();

    $this->delete("/network/servers/{$this->server->id}/firewall/rules/{$rule->id}")->assertSessionHasNoErrors();
    expect(array_column($this->agents->last('net.firewall.apply')['payload']['rules'], 'ports'))->toBe([['22'], ['80']]);

    ServerDeleted::dispatch($this->server->id, $this->organization->id, 'web', 'web-1');
    expect(FirewallRule::query()->count())->toBe(0)->and(FirewallState::query()->count())->toBe(0);
});

it('lets viewers look but not touch', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->get('/network')->assertOk()->assertInertia(fn ($page) => $page->component('Network/Index', false)->where('can.manage', false)->has('servers', 1));
    $this->get("/servers/{$this->server->id}/firewall")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'x', 'action' => 'allow', 'protocol' => 'tcp', 'port' => '1'])->assertForbidden();
    $this->post("/network/servers/{$this->server->id}/firewall/apply")->assertForbidden();

    $rule = FirewallRule::query()->firstOrFail();
    $this->delete("/network/servers/{$this->server->id}/firewall/rules/{$rule->id}")->assertForbidden();
    // Viewers do not trigger dispatches.
    expect($this->agents->dispatched())->toHaveCount(0);
});

it('hides other organizations servers and rules', function () {
    [$outsider] = memberOf(null, Role::Owner);
    $this->get("/servers/{$this->server->id}/firewall");
    $rule = FirewallRule::query()->firstOrFail();

    $this->actingAs($outsider);
    $this->get("/servers/{$this->server->id}/firewall")->assertNotFound();
    $this->post("/network/servers/{$this->server->id}/firewall/rules", ['name' => 'x', 'action' => 'allow', 'protocol' => 'tcp'])->assertNotFound();
    $this->delete("/network/servers/{$this->server->id}/firewall/rules/{$rule->id}")->assertNotFound();

    $own = network_server($outsider->current_organization_id, ['name' => 'mine']);
    $this->delete("/network/servers/{$own->id}/firewall/rules/{$rule->id}")->assertNotFound();
});

it('keeps the SSH port the control plane machine really uses open, and refuses web-port deny rules there', function () {
    $cp = network_server($this->organization, ['name' => 'this-machine', 'control_plane_host' => true, 'ssh_port' => 22]);
    $cp->machineInspection()->create(['purpose' => 'check', 'status' => 'finished', 'report' => ['ssh' => ['effective' => ['port' => '2222 22']]]]);

    $this->get("/servers/{$cp->id}/firewall")->assertOk();
    expect($this->agents->last('net.firewall.apply', $cp->id)['payload']['ssh_port'])->toBe(2222);

    // Without a machine check: the server's own port, not the configured default.
    $cp->machineInspection()->delete();
    $cp->forceFill(['ssh_port' => 2200])->save();
    app(ApplyFirewall::class)($cp->id, force: true);
    expect($this->agents->last('net.firewall.apply', $cp->id)['payload']['ssh_port'])->toBe(2200);

    // Other servers keep network.ssh_port.
    $this->get("/servers/{$this->server->id}/firewall");
    expect($this->agents->last('net.firewall.apply', $this->server->id)['payload']['ssh_port'])->toBe((int) config('network.ssh_port', 22));

    foreach (['', '443', '1-1000'] as $port) {
        $this->post("/network/servers/{$cp->id}/firewall/rules", ['name' => 'Block', 'action' => 'deny', 'protocol' => 'tcp', 'port' => $port, 'source' => '203.0.113.0/24'])
            ->assertSessionHasErrors(['action' => SaveFirewallRule::CONTROL_PLANE_DENY]);
    }
    // Ports the host itself serves still work.
    $this->post("/network/servers/{$cp->id}/firewall/rules", ['name' => 'No pg', 'action' => 'deny', 'protocol' => 'tcp', 'port' => '5432'])->assertSessionHasNoErrors();

    expect(fn () => app(Firewalls::class)->denyPort($cp->id, 'tcp', 80, 'web'))->toThrow(InvalidArgumentException::class, 'do not apply on the control-plane machine');
});
