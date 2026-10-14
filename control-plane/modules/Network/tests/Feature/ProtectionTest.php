<?php

use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Domain\Models\Issue;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Support\Cidr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'network.protection.control_plane_addresses' => ['198.51.100.200']]);
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'web-1', 'ipv4' => '192.0.2.10', 'ipv6' => '2001:db8:10::1', 'private_ipv4' => null]);
    $this->enrolled = fleet_enroll($this->organization->id, $this->server->id, ['features' => ['net.protection']]);
    Certificate::query()->where('fingerprint', $this->enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);
});

function protection_block(array $data = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])
        ->postJson('/network/blocks', [...['address' => '203.0.113.7', 'scope' => 'server', 'server_id' => test()->server->id, 'duration' => '24h'], ...$data]);
}

it('parses and compares prefixes strictly', function () {
    expect((string) Cidr::parse('203.0.113.7'))->toBe('203.0.113.7')
        ->and((string) Cidr::parse('2001:DB8:1::/48'))->toBe('2001:db8:1::/48')
        ->and(Cidr::parse('203.0.113.0/24')->contains(Cidr::parse('203.0.113.7')))->toBeTrue()
        ->and(Cidr::parse('203.0.114.0/25')->overlaps(Cidr::parse('203.0.113.128/25')))->toBeFalse()
        ->and(Cidr::parse('2001:db8::/32')->overlaps(Cidr::parse('2001:db8:ffff::1')))->toBeTrue()
        ->and(Cidr::parse('10.0.0.0/8')->overlaps(Cidr::parse('::1')))->toBeFalse();

    foreach (['', 'nope', '203.0.113.7/24', '1.2.3.4/33', '::ffff:1.2.3.4', 'fe80::1%eth0', '1.2.3.4; flush ruleset', '1.2.3.4 ', '999.1.1.1', '1.2.3.4/024'] as $bad) {
        expect(Cidr::tryParse($bad === '1.2.3.4 ' ? "1.2.3.4\n}" : $bad))->toBeNull();
    }
});

it('blocks an address on a server: audited, schema-valid net.protection.apply with the guard list', function () {
    protection_block(['reason' => 'credential stuffing', 'duration' => '1h'])->assertCreated()->assertJsonPath('blocks.0.cidr', '203.0.113.7');

    $block = IpBlock::query()->sole();
    expect($block->server_id)->toBe($this->server->id)
        ->and($block->source)->toBe('manual')
        ->and(now()->diffInMinutes($block->expires_at))->toEqualWithDelta(60, 1)
        ->and(AuditEntry::query()->where('action', 'network.ip_blocked')->sole()->context)->toMatchArray(['cidr' => '203.0.113.7', 'duration' => '1h']);

    $command = $this->agents->last('net.protection.apply', $this->server->id);
    expect(network_schema_errors('net.protection.apply', $command['payload']))->toBe([])
        ->and($command['payload']['blocks'])->toHaveCount(1)
        ->and($command['payload']['blocks'][0]['cidr'])->toBe('203.0.113.7')
        ->and($command['payload']['never_block'])->toContain('192.0.2.10', '2001:db8:10::1', '198.51.100.200')
        ->and($command['payload']['auto_ban'])->toBe(['enabled' => false])
        ->and($command['handle']->idempotencyKey)->toBe("net.protection:{$this->server->id}:1");

    // The same block again updates it; the payload is unchanged, so nothing is re-sent.
    protection_block(['reason' => 'still at it', 'duration' => '1h'])->assertCreated();
    expect(IpBlock::query()->count())->toBe(1)->and(IpBlock::query()->sole()->reason)->toBe('still at it');
});

it('refuses blocks that would lock someone out or are too broad', function (string $address, string $field, string $message) {
    PrivateNetworkMember::query()->create([
        'network_id' => PrivateNetwork::query()->create(['organization_id' => $this->organization->id, 'name' => 'mesh', 'cidr' => '10.90.0.0/24', 'interface' => 'wg-mesh', 'listen_port' => 51820])->id,
        'organization_id' => $this->organization->id, 'server_id' => $this->server->id, 'address' => '10.90.0.2', 'public_key' => str_repeat('a', 43).'=', 'key_status' => 'installed', 'status' => 'applied',
    ]);

    protection_block(['address' => $address])->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
    expect(IpBlock::query()->count())->toBe(0);
    $this->agents->assertNothingDispatched('net.protection.apply');
})->with([
    'everything v4' => ['0.0.0.0/0', 'address', 'too broad'],
    'everything v6' => ['::/0', 'address', 'too broad'],
    'huge v4' => ['8.0.0.0/7', 'address', 'too broad'],
    'yourself' => ['203.0.113.250', 'address', 'your own address'],
    'your network' => ['203.0.113.0/24', 'address', 'your own address'],
    'the control plane' => ['198.51.100.200', 'address', 'control plane'],
    'a server' => ['192.0.2.0/24', 'address', 'the server web-1'],
    'a server v6' => ['2001:db8:10::1', 'address', 'the server web-1'],
    'a WireGuard network' => ['10.90.0.0/16', 'address', 'private network mesh'],
    'loopback' => ['127.0.0.1', 'address', 'reserved'],
    'link-local' => ['fe80::1', 'address', 'reserved'],
    'injection' => ['1.2.3.4; flush ruleset', 'address', 'not an IP address'],
    'host bits' => ['203.0.113.7/24', 'address', 'host bits'],
    'mapped' => ['::ffff:203.0.113.7', 'address', 'IPv4 form'],
    'private without confirming' => ['10.1.2.3', 'confirm', 'private range'],
    'broad without confirming' => ['11.0.0.0/12', 'confirm', 'addresses'],
]);

it('blocks private ranges and broad prefixes once confirmed', function () {
    protection_block(['address' => '10.1.2.3', 'confirm' => true])->assertCreated();
    protection_block(['address' => '11.0.0.0/12', 'confirm' => true])->assertCreated();

    expect(IpBlock::query()->pluck('cidr')->sort()->values()->all())->toBe(['10.1.2.3', '11.0.0.0/12'])
        ->and(AuditEntry::query()->where('action', 'network.ip_blocked')->get()->every(fn ($e) => $e->context['confirmed'] === true))->toBeTrue();
});

it('refuses a block overlapping another one in the same reach', function () {
    protection_block(['address' => '203.0.114.0/25'])->assertCreated();
    protection_block(['address' => '203.0.114.7'])->assertUnprocessable()->assertJsonValidationErrors(['address' => 'already covered by the block 203.0.114.0/25']);
    protection_block(['address' => '203.0.114.0/24'])->assertUnprocessable()->assertJsonValidationErrors(['address' => 'overlaps the block 203.0.114.0/25']);
});

it('blocks organization-wide on every server that supports it; older agents never get block sets', function () {
    $old = network_server($this->organization, ['name' => 'old-1', 'ipv4' => '192.0.2.20']);
    fleet_enroll($this->organization->id, $old->id);

    protection_block(['scope' => 'organization', 'server_id' => null, 'duration' => 'permanent'])->assertCreated();

    expect(IpBlock::query()->sole()->server_id)->toBeNull()
        ->and($this->agents->dispatched('net.protection.apply', $this->server->id))->toHaveCount(1)
        ->and($this->agents->dispatched('net.protection.apply', $old->id))->toHaveCount(0)
        ->and(ProtectionState::query()->find($old->id)->status)->toBe('unsupported')
        ->and($this->agents->last('net.protection.apply', $this->server->id)['payload']['blocks'][0])->toBe(['cidr' => '203.0.113.7', 'expires_at' => null]);

    $this->get('/network/blocked')->assertOk()->assertInertia(fn ($page) => $page->component('Network/Blocked', false)
        ->has('blocks', 1)->where('blocks.0.server', null)
        ->where('servers', fn ($servers) => collect($servers)->firstWhere('id', $old->id)['supported'] === false)
        ->where('can.protect', true));
});

it('needs network.protect (admins) to block, and scopes everything to the organization', function () {
    [, $other] = [null, Organization::factory()->create()];
    $foreign = network_server($other, ['ipv4' => '192.0.2.99']);

    protection_block(['server_id' => $foreign->id])->assertNotFound();

    [$dev] = actingAsMember(Role::Developer, $this->organization);
    $this->actingAs($dev);
    protection_block()->assertForbidden();
    $this->get('/network/blocked')->assertOk()->assertInertia(fn ($page) => $page->where('can.protect', false));

    $block = IpBlock::query()->create(['organization_id' => $other->id, 'server_id' => null, 'cidr' => '203.0.113.9', 'family' => 'ipv4', 'source' => 'manual']);
    $this->actingAs($this->user)->deleteJson("/network/blocks/{$block->id}")->assertNotFound();
    expect(IpBlock::query()->count())->toBe(1);
});

it('rate-limits block changes per user', function () {
    config(['network.protection.changes_per_minute' => 2]);
    RateLimiter::clear('network.protect:'.$this->user->id);

    protection_block(['address' => '203.0.113.1'])->assertCreated();
    protection_block(['address' => '203.0.113.2'])->assertCreated();
    protection_block(['address' => '203.0.113.3'])->assertUnprocessable()->assertJsonValidationErrors(['address' => 'Too many']);
});

it('unblocks: audited, re-applied; a lifted auto-ban is released on the agent', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/agent/v1/requests/net.protection.observed', ['at' => now()->toIso8601String(), 'window_s' => 60,
        'bans' => [['cidr' => '203.0.113.66', 'until' => now()->addHour()->toIso8601String(), 'failures' => 12]]], fleet_mtls($this->enrolled['fingerprint']))->assertOk();

    $ban = IpBlock::query()->sole();
    expect($ban->source)->toBe('auto')->and($ban->server_id)->toBe($this->server->id)
        ->and(AuditEntry::query()->where('action', 'network.ip_auto_banned')->exists())->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])->deleteJson("/network/blocks/{$ban->id}")->assertNoContent();

    $payload = $this->agents->last('net.protection.apply', $this->server->id)['payload'];
    expect(IpBlock::query()->count())->toBe(0)
        ->and($payload['release'])->toBe(['203.0.113.66'])
        ->and($payload['blocks'])->toBe([])
        ->and(AuditEntry::query()->where('action', 'network.ip_unblocked')->sole()->context)->toMatchArray(['cidr' => '203.0.113.66', 'source' => 'auto']);

    // Once applied, the release is forgotten.
    $command = $this->agents->last('net.protection.apply', $this->server->id);
    $this->agents->succeed($command['handle'], ['changed' => true, 'added' => 0, 'removed' => 1, 'failed' => [], 'set_counters' => true]);
    expect(ProtectionState::query()->find($this->server->id))->status->toBe('applied')->released->toBeNull();
});

it('records the agent report: rule counters, block hits, brute-force Issue; refuses guarded auto-bans', function () {
    protection_block(['address' => '203.0.113.0/28'])->assertCreated();
    FirewallState::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'status' => 'applied']);

    $report = ['at' => now()->toIso8601String(), 'window_s' => 60,
        'auth' => ['source' => 'journald', 'sources' => [['ip' => '203.0.113.7', 'failures' => 15, 'invalid_users' => 3, 'users' => ['root', 'admin']], ['ip' => '2001:db8:bad::1', 'failures' => 9]]],
        'bans' => [['cidr' => '192.0.2.10', 'until' => now()->addHour()->toIso8601String(), 'failures' => 40]],
        'rules' => [['id' => 'ssh', 'packets' => 7, 'bytes' => 420]],
        'blocks' => [['cidr' => '203.0.113.0/28', 'packets' => 31, 'bytes' => 1860]],
        'blocked' => ['packets' => 31, 'bytes' => 1860]];
    expect(fleet_schema_errors('requests/net.protection.observed.schema.json', $report))->toBe([]);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/agent/v1/requests/net.protection.observed', $report, fleet_mtls($this->enrolled['fingerprint']))->assertOk();

    expect(FirewallState::query()->find($this->server->id)->counters)->toBe(['ssh' => ['packets' => 7, 'bytes' => 420]])
        ->and((int) DB::table('network_ip_block_hits')->value('packets'))->toBe(31)
        ->and(IpBlock::query()->where('source', 'auto')->exists())->toBeFalse()
        ->and(ProtectionState::query()->find($this->server->id)->released)->toBe(['192.0.2.10']);

    $issue = Issue::query()->where('kind', IssueKind::Security)->sole();
    expect($issue->title)->toBe('SSH brute force on web-1')
        ->and($issue->server_id)->toBe($this->server->id)
        ->and($issue->meta)->toMatchArray(['source' => 'host', 'finding' => 'ssh.bruteforce'])
        ->and($issue->sample['sources'][0])->toMatchArray(['ip' => '203.0.113.7', 'failures' => 15]);

    $this->get("/servers/{$this->server->id}/firewall")->assertInertia(fn ($page) => $page->where('counters.ssh.packets', 7));

    // Below the threshold: no occurrence.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/agent/v1/requests/net.protection.observed', ['at' => now()->toIso8601String(), 'window_s' => 60,
        'auth' => ['source' => 'auth.log', 'sources' => [['ip' => '203.0.113.7', 'failures' => 3]]]], fleet_mtls($this->enrolled['fingerprint']))->assertOk();
    expect(Issue::query()->sole()->occurrences)->toBe(24);

    // An invalid report never reaches the handler.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/agent/v1/requests/net.protection.observed', ['at' => 'x', 'window_s' => 60, 'bans' => [['cidr' => '1.2.3.4; drop', 'until' => 'x']]],
        fleet_mtls($this->enrolled['fingerprint']))->assertUnprocessable();
});

it('saves the auto-ban settings (off by default) and sends them', function () {
    expect(ProtectionState::query()->count())->toBe(0);

    $this->put("/network/servers/{$this->server->id}/protection", ['enabled' => true, 'failures' => 5, 'window_s' => 300, 'ban_s' => 86400])->assertSessionHasNoErrors();

    expect($this->agents->last('net.protection.apply', $this->server->id)['payload']['auto_ban'])->toBe(['enabled' => true, 'failures' => 5, 'window_s' => 300, 'ban_s' => 86400])
        ->and(AuditEntry::query()->where('action', 'network.protection_updated')->exists())->toBeTrue();

    $this->put("/network/servers/{$this->server->id}/protection", ['enabled' => true, 'failures' => 1, 'window_s' => 10, 'ban_s' => 1])->assertSessionHasErrors(['failures', 'window_s', 'ban_s']);
});

it('puts counters on firewall rules for agents with net.protection only, and re-sends the firewall after the upgrade', function () {
    $this->get("/servers/{$this->server->id}/firewall");
    $payload = $this->agents->last('net.firewall.apply', $this->server->id)['payload'];
    expect($payload['counters'])->toBeTrue()->and(network_schema_errors('net.firewall.apply', $payload))->toBe([]);

    $old = PayloadCompatibility::adapt('net.firewall.apply', json_decode(json_encode($payload)), []);
    $new = PayloadCompatibility::adapt('net.firewall.apply', json_decode(json_encode($payload)), ['net.protection']);
    expect(property_exists($old, 'counters'))->toBeFalse()->and($new->counters)->toBeTrue();

    $before = count($this->agents->dispatched('net.firewall.apply', $this->server->id));
    AgentVersionChanged::dispatch($this->enrolled['agent']->id, $this->organization->id, $this->server->id, 'v0.16.0', 'v0.17.0', ['net.protection']);
    expect($this->agents->dispatched('net.firewall.apply', $this->server->id))->toHaveCount($before + 1);

    AgentVersionChanged::dispatch($this->enrolled['agent']->id, $this->organization->id, $this->server->id, 'v0.17.0', 'v0.17.1', []);
    expect($this->agents->dispatched('net.firewall.apply', $this->server->id))->toHaveCount($before + 1);
});
