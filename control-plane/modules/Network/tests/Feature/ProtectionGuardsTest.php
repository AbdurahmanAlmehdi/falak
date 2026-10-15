<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Identity\Application\Actions\AssignRole;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Application\Jobs\ConvergeProtection;
use Falak\Network\Application\ProtectionGuards;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Support\Cidr;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

/*
 * Findings of the security review of the protection stream (docs/plans/network/protection.md).
 */

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'network.protection.control_plane_addresses' => ['198.51.100.200']]);
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'web-1', 'ipv4' => '192.0.2.10', 'ipv6' => null, 'private_ipv4' => null]);
    $this->enrolled = fleet_enroll($this->organization->id, $this->server->id, ['features' => ['net.protection']]);
    Certificate::query()->where('fingerprint', $this->enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);
    RateLimiter::clear('network.protect:'.$this->user->id);
});

function guards_block(array $data = [], string $ip = '203.0.113.250'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/network/blocks', [...['address' => '203.0.113.7', 'scope' => 'server', 'server_id' => test()->server->id, 'duration' => '24h'], ...$data]);
}

function guards_report(array $report): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/agent/v1/requests/net.protection.observed', ['at' => now()->toIso8601String(), 'window_s' => 60, ...$report], fleet_mtls(test()->enrolled['fingerprint']));
}

it('unmaps IPv4-mapped requester addresses before guarding them', function () {
    expect((string) Cidr::fromAddress('::ffff:203.0.113.250'))->toBe('203.0.113.250')
        ->and(Cidr::fromAddress('203.0.113.0/24'))->toBeNull()
        ->and(Cidr::fromAddress('fe80::1%eth0'))->toBeNull();

    guards_block(['address' => '203.0.113.0/24'], '::ffff:203.0.113.250')->assertUnprocessable()->assertJsonValidationErrors(['address' => 'your own address']);
});

it('never blocks an address a member used the panel from in the last 30 days, and sends them to the agent', function () {
    [$teammate] = memberOf($this->organization, Role::Viewer);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $teammate->id, 'ip_address' => '203.0.113.44', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->subDays(3)->getTimestamp()]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $teammate->id, 'ip_address' => '203.0.113.45', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->subDays(40)->getTimestamp()]);
    AuditEntry::query()->forceCreate(['id' => (string) Str::ulid(), 'organization_id' => $this->organization->id, 'actor_type' => 'user', 'actor_id' => $teammate->id,
        'action' => 'x', 'ip_address' => '2001:db8:77::5', 'created_at' => now()->subDay()]);

    guards_block(['address' => '203.0.113.44'])->assertUnprocessable()->assertJsonValidationErrors(['address' => 'a member used the panel from']);
    guards_block(['address' => '2001:db8:77::/48'])->assertUnprocessable()->assertJsonValidationErrors(['address' => 'a member used the panel from']);
    guards_block(['address' => '203.0.113.45'])->assertCreated();

    expect($this->agents->last('net.protection.apply', $this->server->id)['payload']['never_block'])->toContain('203.0.113.44', '2001:db8:77::5')
        ->not->toContain('203.0.113.45');
});

it('scopes server blocks and auto-ban settings to the current organization', function () {
    $other = Organization::factory()->create();
    $other->members()->attach($this->user->id);
    app(AssignRole::class)($this->user, $other->id, Role::Admin);
    $foreign = network_server($other, ['ipv4' => '192.0.2.99']);

    guards_block(['server_id' => $foreign->id])->assertNotFound();
    $this->put("/network/servers/{$foreign->id}/protection", ['enabled' => true, 'failures' => 5, 'window_s' => 300, 'ban_s' => 3600])->assertNotFound();

    expect(IpBlock::query()->count())->toBe(0)->and(ProtectionState::query()->find($foreign->id))->toBeNull();
});

it('asks to confirm blocking a peer the server connects to', function () {
    guards_block(['outbound' => true])->assertUnprocessable()->assertJsonValidationErrors(['confirm' => 'a peer your server connects to']);
    guards_block(['outbound' => true, 'confirm' => true])->assertCreated();
    expect(AuditEntry::query()->where('action', 'network.ip_blocked')->sole()->context)->toMatchArray(['outbound' => true, 'confirmed' => true]);
});

it('lifts the auto-bans a manual block covers', function () {
    guards_report(['bans' => [['cidr' => '203.0.113.66', 'until' => now()->addHour()->toIso8601String(), 'failures' => 12]]])->assertOk();
    expect(IpBlock::query()->sole()->source)->toBe('auto');

    guards_block(['address' => '203.0.113.64/28', 'duration' => '7d'])->assertCreated();

    $payload = $this->agents->last('net.protection.apply', $this->server->id)['payload'];
    expect(IpBlock::query()->pluck('source')->all())->toBe(['manual'])
        ->and($payload['blocks'])->toBe([['cidr' => '203.0.113.64/28', 'expires_at' => now()->addDays(7)->utc()->toIso8601ZuluString()]])
        ->and($payload['release'])->toBe(['203.0.113.66'])
        ->and(AuditEntry::query()->where('action', 'network.ip_blocked')->sole()->context['auto_bans_lifted'])->toBe(['203.0.113.66']);

    // The agent still reports the ban until it applies the release: it is not recreated.
    guards_report(['bans' => [['cidr' => '203.0.113.66', 'until' => now()->addHour()->toIso8601String(), 'failures' => 12]]])->assertOk();
    expect(IpBlock::query()->where('source', 'auto')->exists())->toBeFalse();
});

it('upserts the active bans every report carries, auditing each once, and batches hits', function () {
    $until = now()->addHour()->toIso8601String();
    $bans = [['cidr' => '203.0.113.66', 'until' => $until, 'failures' => 12], ['cidr' => '2001:db8:bad::/64', 'until' => $until, 'failures' => 30]];

    guards_report(['bans' => $bans])->assertOk();
    guards_report(['bans' => $bans, 'blocks' => [['cidr' => '203.0.113.66', 'packets' => 5, 'bytes' => 300], ['cidr' => '2001:db8:bad::/64', 'packets' => 2, 'bytes' => 160]]])->assertOk();

    expect(IpBlock::query()->where('source', 'auto')->pluck('cidr')->sort()->values()->all())->toBe(['2001:db8:bad::/64', '203.0.113.66'])
        ->and(AuditEntry::query()->where('action', 'network.ip_auto_banned')->count())->toBe(2)
        ->and(DB::table('network_ip_block_hits')->sum('packets'))->toEqual(7);

    guards_report(['blocks' => [['cidr' => '203.0.113.66', 'packets' => 9, 'bytes' => 540]]])->assertOk();
    expect((int) DB::table('network_ip_block_hits')->sum('packets'))->toBe(11);
});

it('suspends and alerts a block that covers a server provisioned later, everywhere it applied', function () {
    guards_block(['scope' => 'organization', 'server_id' => null, 'address' => '198.51.100.0/25', 'duration' => 'permanent'])->assertCreated();
    expect($this->agents->last('net.protection.apply', $this->server->id)['payload']['blocks'])->toHaveCount(1);

    $new = network_server($this->organization, ['name' => 'web-2', 'ipv4' => '198.51.100.20']);
    fleet_enroll($this->organization->id, $new->id, ['features' => ['net.protection']]);
    ServerProvisioned::dispatch($new->id, $this->organization->id, 'web', 'web-2');

    $block = IpBlock::query()->sole();
    expect($block->suspended_at)->not->toBeNull()
        ->and($block->suspended_reason)->toContain('the server web-2')
        ->and($this->agents->last('net.protection.apply', $this->server->id)['payload']['blocks'])->toBe([])
        ->and(AuditEntry::query()->where('action', 'network.ip_block_suspended')->exists())->toBeTrue()
        ->and(Alert::query()->where('type', 'network.block_suspended')->exists())->toBeTrue();

    $this->get('/network/blocked')->assertInertia(fn ($page) => $page->where('blocks.0.suspended_reason', fn ($r) => str_contains((string) $r, 'web-2')));
});

it('re-converges on private network changes and retries failed applies until the agent confirms', function () {
    guards_block()->assertCreated();
    $command = $this->agents->last('net.protection.apply', $this->server->id);
    $this->agents->fail($command['handle'], 'nft: busy');
    expect(ProtectionState::query()->find($this->server->id)->status)->toBe('failed');

    // Back online: sent again.
    AgentCameOnline::dispatch($this->enrolled['agent']->id, $this->organization->id, $this->server->id, null);
    expect($this->agents->dispatched('net.protection.apply', $this->server->id))->toHaveCount(2);
    $this->agents->fail($this->agents->last('net.protection.apply', $this->server->id)['handle'], 'nft: busy');

    // An unblock whose apply fails is retried by the periodic converge.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])->deleteJson('/network/blocks/'.IpBlock::query()->sole()->id)->assertNoContent();
    $this->agents->fail($this->agents->last('net.protection.apply', $this->server->id)['handle'], 'nft: busy');
    $before = count($this->agents->dispatched('net.protection.apply', $this->server->id));
    (new ConvergeProtection)->handle(app(ApplyProtection::class), app(ServerDirectory::class));
    expect($this->agents->dispatched('net.protection.apply', $this->server->id))->toHaveCount($before + 1)
        ->and($this->agents->last('net.protection.apply', $this->server->id)['payload']['blocks'])->toBe([]);
    $this->agents->succeed($this->agents->last('net.protection.apply', $this->server->id)['handle'], ['changed' => true]);

    // Applied: the next run sends nothing; a private network change that adds a guard does.
    (new ConvergeProtection)->handle(app(ApplyProtection::class), app(ServerDirectory::class));
    $count = count($this->agents->dispatched('net.protection.apply', $this->server->id));
    expect($count)->toBe($before + 1);

    PrivateNetwork::query()->create(['organization_id' => $this->organization->id, 'name' => 'mesh', 'cidr' => '10.91.0.0/24', 'interface' => 'wg-mesh', 'listen_port' => 51820]);
    PrivateNetworkChanged::dispatch('x', $this->organization->id, PrivateNetworkChanged::MEMBER_ADDED, [$this->server->id]);
    expect($this->agents->dispatched('net.protection.apply', $this->server->id))->toHaveCount($count + 1)
        ->and($this->agents->last('net.protection.apply', $this->server->id)['payload']['never_block'])->toContain('10.91.0.0/24');
});

it('rate-limits unblocks and counts a site-wide block once', function () {
    config(['network.protection.changes_per_minute' => 2]);
    $blocks = collect(['203.0.113.1', '203.0.113.2', '203.0.113.3'])->map(fn ($a) => IpBlock::query()->create([
        'organization_id' => $this->organization->id, 'server_id' => $this->server->id, 'cidr' => $a, 'family' => 'ipv4', 'source' => 'manual',
    ]));

    foreach ($blocks->take(2) as $block) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])->deleteJson("/network/blocks/{$block->id}")->assertNoContent();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])->deleteJson("/network/blocks/{$blocks[2]->id}")->assertUnprocessable()->assertJsonValidationErrors(['block' => 'Too many']);

    RateLimiter::clear('network.protect:'.$this->user->id);
    $second = network_server($this->organization, ['name' => 'web-2', 'ipv4' => '192.0.2.11']);
    $site = Site::query()->create(['organization_id' => $this->organization->id, 'name' => 'Shop', 'slug' => 'shop', 'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native, 'framework' => Framework::Laravel, 'php_version' => '8.4', 'web_directory' => 'public', 'unix_user' => 'shop',
        'isolated' => true, 'repository' => 'acme/shop', 'branch' => 'main', 'deploy_script' => '$FALAK_FETCH', 'laravel' => new LaravelSettings]);
    foreach ([$this->server, $second] as $i => $server) {
        SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => $i === 0 ? TargetRole::Leader : TargetRole::Member, 'status' => TargetStatus::Ready]);
    }
    guards_block(['scope' => 'site', 'server_id' => null, 'site_id' => $site->id, 'address' => '198.51.100.7'])->assertCreated()->assertJsonCount(2, 'blocks');
    guards_block(['scope' => 'site', 'server_id' => null, 'site_id' => $site->id, 'address' => '198.51.100.8'])->assertCreated();
});

it('logs configured control-plane entries that are not addresses', function () {
    Log::spy();
    config(['network.protection.control_plane_addresses' => ['198.51.100.200', 'cp.example.com', '1.2.3.4; x']]);

    expect(array_map('strval', app(ProtectionGuards::class)->controlPlane()))->toBe(['198.51.100.200']);
    Log::shouldHaveReceived('warning')->twice();
});

it('never cuts the control plane or the servers from never_block: members\' addresses come last', function () {
    [$teammate] = memberOf($this->organization, Role::Viewer);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $teammate->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $list = app(ProtectionGuards::class)->neverBlock($this->organization->id);
    expect($list)->toContain('192.0.2.10', '198.51.100.200')
        ->and(array_slice($list, -1))->toBe(['1.1.1.1'])
        ->and(array_slice($list, 0, -1))->toBe(collect(array_slice($list, 0, -1))->sort()->values()->all());
});

it('refuses to send protection when the protected addresses do not fit, and says so', function () {
    config(['network.protection.never_block_max' => 2]);
    network_server($this->organization, ['name' => 'web-2', 'ipv4' => '192.0.2.11', 'ipv6' => null, 'private_ipv4' => null]);

    guards_block()->assertCreated();

    $state = ProtectionState::query()->find($this->server->id);
    expect($state->status)->toBe('failed')
        ->and($state->error)->toContain('protected addresses')
        ->and($this->agents->dispatched('net.protection.apply', $this->server->id))->toHaveCount(0);
    $this->get('/network/blocked')->assertInertia(fn ($page) => $page->where('servers', fn ($servers) => str_contains((string) collect($servers)->firstWhere('id', $this->server->id)['error'], 'agent accepts 2')));

    config(['network.protection.never_block_max' => 5000]);
    expect(count(app(ProtectionGuards::class)->neverBlock($this->organization->id)))->toBeLessThanOrEqual(5000);
});

it('never brings back a released auto-ban from a report built before the release', function () {
    $until = now()->addHour()->toIso8601String();
    guards_report(['bans' => [['cidr' => '203.0.113.66', 'until' => $until, 'failures' => 12]]])->assertOk();
    $built = now()->subSecond()->toIso8601String();

    $this->travel(5)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])->deleteJson('/network/blocks/'.IpBlock::query()->sole()->id)->assertNoContent();
    // The agent confirms the release: the pending list is cleared, the release time is kept.
    $this->agents->succeed($this->agents->last('net.protection.apply', $this->server->id)['handle'], ['changed' => true]);
    expect(ProtectionState::query()->find($this->server->id)->released)->toBeNull();

    // A report built before the release arrives late (a retry): ignored.
    guards_report(['at' => $built, 'bans' => [['cidr' => '203.0.113.66', 'until' => $until, 'failures' => 12]]])->assertOk();
    expect(IpBlock::query()->count())->toBe(0);

    // A later report with the address banned again (it attacked again): recorded.
    $this->travel(5)->seconds();
    guards_report(['at' => now()->toIso8601String(), 'bans' => [['cidr' => '203.0.113.66', 'until' => now()->addHour()->toIso8601String(), 'failures' => 30]]])->assertOk();
    expect(IpBlock::query()->sole()->source)->toBe('auto');
});

it('says nothing is waiting when a server has nothing to protect, and why when it waits', function () {
    expect(app(ApplyProtection::class)($this->server->id)->status)->toBe('idle');

    $this->server->update(['status' => 'provisioning']);
    IpBlock::query()->forceCreate(['id' => (string) Str::ulid(), 'organization_id' => $this->organization->id, 'server_id' => $this->server->id,
        'cidr' => '203.0.113.7', 'family' => 'ipv4', 'source' => 'manual']);

    $state = app(ApplyProtection::class)($this->server->id);
    expect($state->status)->toBe('pending')->and($state->error)->toContain('provisioning');
});

it("refuses Cloudflare's proxy ranges", function () {
    guards_block(['address' => '172.70.1.2'])->assertUnprocessable()->assertJsonValidationErrors(['address' => "Cloudflare's proxy range"]);
    guards_block(['address' => '2606:4700::/48'])->assertUnprocessable()->assertJsonValidationErrors(['address' => "Cloudflare's proxy range"]);
});

it('labels the addresses a row must not offer Block on', function () {
    $labels = app(ProtectionGuards::class)->unblockable(
        ['198.51.100.200', '192.0.2.10', '162.158.4.5', '10.0.0.3', '127.0.0.1', '203.0.113.250', '203.0.113.9', 'not-an-ip'],
        $this->organization->id,
        '203.0.113.250',
    );

    expect($labels)->toBe([
        '198.51.100.200' => 'Control plane',
        '192.0.2.10' => 'Your server',
        '162.158.4.5' => 'Cloudflare',
        '10.0.0.3' => 'Private range',
        '127.0.0.1' => 'Reserved',
        '203.0.113.250' => 'Your address',
    ]);
});
