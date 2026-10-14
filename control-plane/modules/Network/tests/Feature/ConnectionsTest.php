<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;
use Falak\Network\Application\ConfigureConnections;
use Falak\Network\Application\ExposedPorts;
use Falak\Network\Application\Jobs\ResolvePeerNames;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\NetworkObservation;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'telemetry.metrics.query_url' => '']);
    Carbon::setTestNow('2026-10-15 12:00:00');
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'app-1', 'ipv4' => '203.0.113.10']);
    FirewallState::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'revision' => 1,
        'status' => ApplyStatus::Applied, 'applied_at' => now()]);
});

afterEach(fn () => Carbon::setTestNow());

/** Posts a net.observed report as the server's agent. */
function net_observed(object $test, array $report, ?string $serverId = null): TestResponse
{
    $serverId ??= $test->server->id;
    $test->enrolled[$serverId] ??= (function () use ($test, $serverId) {
        $enrolled = fleet_enroll($test->organization->id, $serverId, ['features' => ['net.connections']]);
        Certificate::query()->where('fingerprint', $enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);

        return $enrolled;
    })();

    return $test->postJson('/agent/v1/requests/net.observed', $report, fleet_mtls($test->enrolled[$serverId]['fingerprint']));
}

function net_listener(string $proto, string $address, int $port, array $extra = []): array
{
    return ['proto' => $proto, 'address' => $address, 'port' => $port, 'public' => ! in_array($address, ['127.0.0.1', '::1', '10.90.0.1'], true), ...$extra];
}

function net_report(array $overrides = [], array $listeners = [], array $peers = []): array
{
    return [
        'at' => now()->toIso8601String(),
        'window_s' => 60,
        'conntrack' => ['entries' => 100, 'limit' => 1000, 'accounting' => 'off'],
        'workloads' => [
            ['kind' => 'host', 'name' => 'host',
                'connections' => ['inbound' => 2, 'outbound' => 1, 'long_lived' => 0, 'states' => ['established' => 3]],
                'listeners' => $listeners ?: [
                    net_listener('tcp', '0.0.0.0', 80, ['process' => 'caddy', 'owner' => 'host:caddy']),
                    net_listener('tcp', '0.0.0.0', 22, ['process' => 'sshd']),
                    net_listener('tcp', '127.0.0.1', 6379, ['process' => 'redis-server']),
                ],
                'peers' => $peers ?: [
                    ['direction' => 'inbound', 'address' => '198.51.100.7', 'port' => 0, 'connections' => 2],
                    ['direction' => 'outbound', 'address' => '8.8.8.8', 'port' => 443, 'connections' => 1],
                ]],
            ['kind' => 'processes', 'name' => 'blog', 'site' => 'blog',
                'connections' => ['inbound' => 0, 'outbound' => 1, 'long_lived' => 0, 'states' => ['established' => 1]],
                'listeners' => [], 'peers' => [['direction' => 'outbound', 'address' => '10.90.0.2', 'port' => 5432, 'name' => 'server:'.test()->server->id, 'connections' => 1]]],
        ],
        'wireguard' => [],
        'probes' => [],
        ...$overrides,
    ];
}

it('keeps the latest report and ignores reports of other servers and invalid ones', function () {
    Bus::fake([ResolvePeerNames::class]);
    net_observed($this, net_report())->assertOk()->assertJson(['accepted' => true]);

    $observation = NetworkObservation::query()->findOrFail($this->server->id);
    expect($observation->report['workloads'][1]['site'])->toBe('blog')
        ->and($observation->observed_at->toIso8601String())->toBe(now()->toIso8601String());

    // An older (replayed) report never replaces it.
    net_observed($this, net_report(['at' => now()->subMinutes(3)->toIso8601String(), 'conntrack' => ['accounting' => 'unavailable']]))->assertOk();
    expect($observation->refresh()->report['conntrack']['accounting'])->toBe('off');

    net_observed($this, [...net_report(), 'password' => 'x'])->assertStatus(422);
    net_observed($this, net_report(['conntrack' => ['accounting' => 'maybe']]))->assertStatus(422);

    // External peers get a reverse DNS lookup, private ones never.
    Bus::assertDispatched(ResolvePeerNames::class, fn (ResolvePeerNames $job) => $job->addresses === ['198.51.100.7', '8.8.8.8']);
});

it('caches reverse DNS of external peers for a day', function () {
    ResolvePeerNames::$resolver = fn (string $address) => $address === '8.8.8.8' ? 'dns.google' : null;
    (new ResolvePeerNames(['8.8.8.8', '198.51.100.7', 'not-an-ip']))->handle();
    ResolvePeerNames::$resolver = null;

    expect(DB::table('network_peer_names')->orderBy('address')->pluck('name', 'address')->all())->toEqual(['198.51.100.7' => null, '8.8.8.8' => 'dns.google']);

    Bus::fake([ResolvePeerNames::class]);
    net_observed($this, net_report())->assertOk();
    Bus::assertNotDispatched(ResolvePeerNames::class);
});

it('raises a security issue and an alert for a port reachable from the internet, and resolves them when it closes', function () {
    Bus::fake([ResolvePeerNames::class]);
    FirewallRule::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'name' => 'pg', 'action' => 'allow',
        'protocol' => 'tcp', 'port' => '5432', 'position' => 1]);
    $listeners = [
        net_listener('tcp', '0.0.0.0', 80, ['process' => 'caddy']),
        net_listener('tcp', '0.0.0.0', 22, ['process' => 'sshd']),
        net_listener('tcp', '0.0.0.0', 5432, ['process' => 'postgres']),
        // Not allowed by the firewall: dropped by the default policy.
        net_listener('tcp', '0.0.0.0', 9200, ['process' => 'java']),
        // A container's published port goes around the input chain.
        net_listener('tcp', '0.0.0.0', 8080, ['process' => 'docker-proxy', 'container' => 'shop-web', 'owner' => 'site:shop/web']),
        net_listener('tcp', '127.0.0.1', 6379, ['process' => 'redis-server']),
    ];
    net_observed($this, net_report([], $listeners))->assertOk();

    $issues = Issue::query()->where('kind', IssueKind::Security)->orderBy('title')->get();
    expect($issues->pluck('title')->all())->toBe(['Port tcp/8080 reachable from the internet on app-1', 'PostgreSQL 5432 reachable from the internet on app-1'])
        ->and($issues[1]->server_id)->toBe($this->server->id)
        ->and($issues[1]->culprit)->toBe('postgres')
        ->and($issues[1]->meta['source'])->toBe('network.exposed_port')
        ->and($issues[0]->culprit)->toBe('container shop-web');
    expect(Alert::query()->where('type', 'network.exposed_port')->count())->toBe(2);

    // The same report again: one occurrence more, no new alert.
    net_observed($this, net_report(['at' => now()->addMinute()->toIso8601String()], $listeners))->assertOk();
    expect($issues[1]->refresh()->occurrences)->toBe(2)->and(Alert::query()->where('type', 'network.exposed_port')->count())->toBe(2);

    // Postgres rebound to localhost: resolved.
    $listeners[2] = net_listener('tcp', '127.0.0.1', 5432, ['process' => 'postgres']);
    net_observed($this, net_report(['at' => now()->addMinutes(2)->toIso8601String()], $listeners))->assertOk();
    expect($issues[1]->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and($issues[0]->refresh()->status)->toBe(IssueStatus::Open);
});

it('treats every public port as reachable while the firewall is not applied, except the ones Falak exposes', function () {
    Bus::fake([ResolvePeerNames::class]);
    FirewallState::query()->whereKey($this->server->id)->update(['status' => ApplyStatus::Failed->value]);
    $db = databases_instance($this->organization, 'postgresql', $this->server, ['public_access' => true, 'host_port' => 25432]);
    $network = PrivateNetwork::query()->create(['organization_id' => $this->organization->id, 'name' => 'mesh', 'cidr' => '10.90.0.0/24', 'interface' => 'falak0', 'listen_port' => 51820]);
    PrivateNetworkMember::query()->create(['network_id' => $network->id, 'organization_id' => $this->organization->id, 'server_id' => $this->server->id,
        'address' => '10.90.0.1', 'public_key' => 'xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=', 'key_status' => 'installed', 'status' => 'applied']);
    $peer = network_server($this->organization, ['name' => 'app-2', 'ipv4' => '203.0.113.11']);
    PrivateNetworkMember::query()->create(['network_id' => $network->id, 'organization_id' => $this->organization->id, 'server_id' => $peer->id,
        'address' => '10.90.0.2', 'public_key' => 'TrMvSoP4jYQlY6RIzBgbssQqY3vxI2Pi+y71lOWWXX0=', 'key_status' => 'installed', 'status' => 'applied']);

    $found = app(ExposedPorts::class)->find($this->server->id, net_report([], [
        net_listener('tcp', '0.0.0.0', 443), net_listener('udp', '0.0.0.0', 443), net_listener('udp', '0.0.0.0', 51820),
        net_listener('tcp', '0.0.0.0', 25432, ['process' => 'docker-proxy']), net_listener('udp', '0.0.0.0', 68),
        net_listener('tcp', '::', 6379, ['process' => 'redis-server']),
    ]));
    expect(array_column($found, 'key'))->toBe(['tcp/6379'])->and($found[0]['title'])->toBe('Redis 6379 reachable from the internet');
    expect($db->public_access)->toBeTrue();
});

it('matches the first firewall rule that applies to everyone', function () {
    $rules = [
        ['id' => 'wg', 'action' => 'accept', 'protocol' => 'any', 'interface' => 'falak0'],
        ['id' => 'deny', 'action' => 'drop', 'protocol' => 'tcp', 'ports' => ['8000-8100']],
        ['id' => 'office', 'action' => 'accept', 'protocol' => 'tcp', 'ports' => ['9000'], 'sources' => ['198.51.100.0/24']],
        ['id' => 'all', 'action' => 'accept', 'protocol' => 'any'],
    ];
    expect(ExposedPorts::accepted($rules, 'tcp', 8080))->toBeFalse()
        ->and(ExposedPorts::accepted($rules, 'tcp', 9000))->toBeTrue()
        ->and(ExposedPorts::accepted(array_slice($rules, 0, 3), 'tcp', 9000))->toBeFalse()
        ->and(ExposedPorts::accepted(array_slice($rules, 0, 3), 'udp', 8080))->toBeFalse();
});

it('alerts on conntrack near full, stale WireGuard peers and latency spikes once they hold', function () {
    Bus::fake([ResolvePeerNames::class]);
    $peerName = 'server:'.strtolower((string) Str::ulid());
    $report = fn (int $minute, float $ms, ?int $handshake, bool $ok = true) => net_report([
        'at' => now()->addMinutes($minute)->toIso8601String(),
        'conntrack' => ['entries' => 900, 'limit' => 1000, 'accounting' => 'on'],
        'wireguard' => [['interface' => 'falak0', 'public_key' => 'TrMvSoP4jYQlY6RIzBgbssQqY3vxI2Pi+y71lOWWXX0=', 'name' => $peerName, 'allowed_ips' => ['10.90.0.2/32'],
            'handshake_age_s' => $handshake, 'rx_bytes' => 1, 'tx_bytes' => 2]],
        'probes' => [['name' => $peerName, 'kind' => 'server', 'host' => '10.90.0.2', 'port' => 22, 'ok' => $ok, 'connect_ms' => $ok ? $ms : null, ...($ok ? [] : ['error' => 'timeout'])]],
    ]);

    foreach (range(0, 4) as $minute) {
        Carbon::setTestNow(now()->addMinute());
        net_observed($this, $report(0, 2.0, 30))->assertOk();
    }
    expect(Alert::query()->where('type', 'network.conntrack_full')->count())->toBe(0);
    expect(NetworkObservation::query()->find($this->server->id)->baselines[$peerName])->toEqual(2.0);

    foreach (range(0, 6) as $minute) {
        Carbon::setTestNow(now()->addMinute());
        net_observed($this, $report(0, 120.0, 900))->assertOk();
    }
    expect(Alert::query()->where('type', 'network.conntrack_full')->count())->toBe(1)
        ->and(Alert::query()->where('type', 'network.wireguard_stale')->count())->toBe(1)
        ->and(Alert::query()->where('type', 'network.latency_spike')->count())->toBe(1);
    // The baseline does not follow a spike.
    expect(NetworkObservation::query()->find($this->server->id)->baselines[$peerName])->toEqual(2.0);
});

it('shows a server its listeners, peers and WireGuard peers, named, to network viewers only', function () {
    Bus::fake([ResolvePeerNames::class]);
    DB::table('network_peer_names')->insert(['address' => '8.8.8.8', 'name' => 'dns.google', 'resolved_at' => now()]);
    net_observed($this, net_report())->assertOk();

    $response = $this->getJson("/network/servers/{$this->server->id}/connections?range=6h")->assertOk()
        ->assertJsonPath('reporting', 'ok')
        ->assertJsonPath('step', 120)
        ->assertJsonPath('errors.metrics', 'not_configured')
        ->assertJsonPath('listeners.0.port', 22)
        ->assertJsonPath('listeners.1.owner.label', 'caddy')
        ->assertJsonPath('clients.0.address', '198.51.100.7');
    expect(collect($response->json('talks_to'))->firstWhere('address', '10.90.0.2')['peer']['label'])->toBe('app-1')
        ->and(collect($response->json('talks_to'))->firstWhere('address', '10.90.0.2')['workload'])->toBe('blog');
    expect(collect($response->json('talks_to'))->firstWhere('address', '8.8.8.8')['rdns'])->toBe('dns.google');

    [$stranger] = memberOf();
    $this->actingAs($stranger)->getJson("/network/servers/{$this->server->id}/connections")->assertNotFound();
    $this->actingAs($this->user)->getJson("/network/servers/{$this->server->id}/connections?range=1y")->assertStatus(422);
});

it('says why a server has no report yet', function () {
    $this->getJson("/network/servers/{$this->server->id}/connections")->assertOk()->assertJsonPath('reporting', 'waiting');

    fleet_enroll($this->organization->id, $this->server->id, ['features' => []]);
    $this->getJson("/network/servers/{$this->server->id}/connections")->assertOk()->assertJsonPath('reporting', 'agent_outdated');
});

it('queries the metrics of the server only', function () {
    Http::preventStrayRequests();
    config(['telemetry.metrics.backend' => 'victoriametrics', 'telemetry.metrics.query_url' => 'http://vm:8428']);
    Http::fake(['vm:8428/api/v1/query_range' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
        ['metric' => ['falak_net_state' => 'established'], 'values' => [[1_790_000_000, '7']]],
    ]]])]);

    $this->getJson("/network/servers/{$this->server->id}/connections")->assertOk()->assertJsonPath('charts.states.0.points.0', [1_790_000_000, 7]);
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'falak_server_id="'.strtoupper($this->server->id).'"'));
});

it('shows a database its connected clients', function () {
    Bus::fake([ResolvePeerNames::class]);
    $db = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-pg']);
    $report = net_report();
    $report['workloads'][] = ['kind' => 'container', 'name' => $db->container(), 'db_instance' => $db->id, 'container_id' => str_repeat('c', 64),
        'connections' => ['inbound' => 4, 'outbound' => 0, 'long_lived' => 4, 'states' => ['established' => 4]],
        'listeners' => [net_listener('tcp', '0.0.0.0', 5432)],
        'peers' => [['direction' => 'inbound', 'address' => '172.18.0.2', 'port' => 0, 'name' => 'site:shop/web', 'connections' => 4]]];
    net_observed($this, $report)->assertOk();

    $this->getJson("/network/databases/{$db->id}/connections")->assertOk()
        ->assertJsonPath('connections.inbound', 4)
        ->assertJsonPath('clients.0.peer.label', 'shop/web')
        ->assertJsonPath('clients.0.workload', 'main-pg');

    $foreign = databases_instance(Organization::factory()->create(), 'postgresql');
    $this->getJson("/network/databases/{$foreign->id}/connections")->assertNotFound();
});

it('sends net.connections.configure with peers, databases and names, only when it changed', function () {
    $gateway = FakeAgentGateway::install();
    $network = PrivateNetwork::query()->create(['organization_id' => $this->organization->id, 'name' => 'mesh', 'cidr' => '10.90.0.0/24', 'interface' => 'falak0', 'listen_port' => 51820]);
    $peer = network_server($this->organization, ['name' => 'app-2', 'ipv4' => '203.0.113.11']);
    foreach ([[$this->server, '10.90.0.1', 'xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg='], [$peer, '10.90.0.2', 'TrMvSoP4jYQlY6RIzBgbssQqY3vxI2Pi+y71lOWWXX0=']] as [$s, $address, $key]) {
        PrivateNetworkMember::query()->create(['network_id' => $network->id, 'organization_id' => $this->organization->id, 'server_id' => $s->id,
            'address' => $address, 'public_key' => $key, 'key_status' => 'installed', 'status' => 'applied']);
    }
    $local = databases_instance($this->organization, 'postgresql', $this->server, ['host_port' => 20001]);
    $remote = databases_instance($this->organization, 'mysql', $peer, ['host_port' => 20002, 'published_addresses' => ['10.90.0.2']]);
    fleet_enroll($this->organization->id, $this->server->id, ['features' => ['net.connections']]);

    expect(app(ConfigureConnections::class)($this->server->id))->not->toBeNull();
    $payload = $gateway->last('net.connections.configure', $this->server->id)['payload'];
    expect(network_schema_errors('net.connections.configure', $payload))->toBe([])
        ->and(collect($payload['probes'])->pluck('host', 'name')->sortKeys()->all())->toEqualCanonicalizing([
            "server:{$peer->id}" => '10.90.0.2', "db:{$local->id}" => '127.0.0.1', "db:{$remote->id}" => '10.90.0.2',
        ])
        ->and(collect($payload['names'])->where('name', "server:{$peer->id}")->pluck('address')->all())->toContain('10.90.0.2', '203.0.113.11')
        ->and($payload['wireguard'])->toBe([['public_key' => 'TrMvSoP4jYQlY6RIzBgbssQqY3vxI2Pi+y71lOWWXX0=', 'name' => "server:{$peer->id}"]]);

    expect(app(ConfigureConnections::class)($this->server->id))->toBeNull();

    // Agents without the feature never get the command.
    expect(app(ConfigureConnections::class)($peer->id))->toBeNull();
});

it('shows a site its containers\' connections per compose service, to telemetry viewers', function () {
    Bus::fake([ResolvePeerNames::class]);
    $site = Site::query()->create(['organization_id' => $this->organization->id, 'name' => 'Shop', 'slug' => 'shop', 'runtime' => SiteRuntime::Compose,
        'build_mode' => BuildMode::Native, 'framework' => Framework::Laravel, 'web_directory' => 'public', 'unix_user' => 'falak', 'isolated' => false,
        'deploy_script' => '', 'laravel' => new LaravelSettings]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $this->server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);
    $container = fn (string $service, array $peers) => ['kind' => 'container', 'name' => "shop-{$service}-1", 'site' => 'shop', 'compose_service' => $service,
        'container_id' => str_repeat('a', 64), 'connections' => ['inbound' => 1, 'outbound' => 1, 'long_lived' => 1, 'states' => ['established' => 2]],
        'listeners' => [net_listener('tcp', '0.0.0.0', 8080)], 'peers' => $peers];
    $report = net_report();
    $report['workloads'][] = $container('web', [['direction' => 'outbound', 'address' => '172.18.0.3', 'port' => 6379, 'name' => 'site:shop/redis', 'connections' => 3]]);
    $report['workloads'][] = $container('redis', [['direction' => 'inbound', 'address' => '172.18.0.2', 'port' => 0, 'name' => 'site:shop/web', 'connections' => 3]]);
    net_observed($this, $report)->assertOk();

    $this->getJson("/network/sites/{$site->id}/connections")->assertOk()
        ->assertJsonPath('services', ['web', 'redis'])
        ->assertJsonCount(2, 'workloads')
        ->assertJsonPath('talks_to.0.peer.label', 'shop/redis');
    $this->getJson("/network/sites/{$site->id}/connections?service=redis")->assertOk()
        ->assertJsonCount(1, 'workloads')
        ->assertJsonCount(0, 'talks_to')
        ->assertJsonPath('clients.0.peer.label', 'shop/web');

    actingAsMember(Role::Viewer, $this->organization);
    $this->getJson("/network/sites/{$site->id}/connections")->assertOk();
    [$stranger] = memberOf();
    $this->actingAs($stranger)->getJson("/network/sites/{$site->id}/connections")->assertNotFound();
});
