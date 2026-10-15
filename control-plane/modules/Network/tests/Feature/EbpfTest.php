<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Network\Application\ConfigureEbpf;
use Falak\Network\Application\Jobs\CheckEbpfAlerts;
use Falak\Network\Domain\Models\EbpfSetting;
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
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'telemetry.metrics.query_url' => '']);
    Carbon::setTestNow('2026-10-15 12:00:00');
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = network_server($this->organization, ['name' => 'app-1', 'ipv4' => '203.0.113.10']);
});

afterEach(fn () => Carbon::setTestNow());

/** Enrolls the server's agent with the given features and facts.ebpf. */
function ebpf_agent(object $test, array $features = ['net.ebpf'], ?array $probe = null, ?string $serverId = null): array
{
    $enrolled = fleet_enroll($test->organization->id, $serverId ?? $test->server->id, ['features' => $features, ...($probe !== null ? ['ebpf' => $probe] : [])]);
    Certificate::query()->where('fingerprint', $enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);

    return $enrolled;
}

/** VictoriaMetrics answering every query with $series (instant or range), recording the queries. */
function ebpf_vm(array $series = [], ?Closure $instant = null): void
{
    config(['telemetry.metrics.backend' => 'victoriametrics', 'telemetry.metrics.query_url' => 'http://vm:8428']);
    Http::preventStrayRequests();
    Http::fake(['vm:8428/*' => function (Request $r) use ($series, $instant) {
        if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/query_range')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => array_map(fn ($s) => ['metric' => $s[0], 'values' => [[1_790_000_000, (string) $s[1]]]], $series)]]);
        }
        $rows = $instant !== null ? $instant((string) $r['query']) : [];

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(fn ($s) => ['metric' => $s[0], 'value' => [1_790_000_000, (string) $s[1]]], $rows)]]);
    }]);
}

it('turns eBPF on: audited, net.ebpf.configure to an agent with net.ebpf, settled by its outcome, re-sent on upgrade', function () {
    $gateway = FakeAgentGateway::install();
    $enrolled = ebpf_agent($this);

    $this->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => true])->assertOk()
        ->assertJsonPath('servers.0.enabled', true)
        ->assertJsonPath('servers.0.delivery', 'pending')
        ->assertJsonPath('servers.0.agent_outdated', false);
    $command = $gateway->last('net.ebpf.configure', $this->server->id);
    expect($command['payload'])->toBe(['enabled' => true])
        ->and(network_schema_errors('net.ebpf.configure', $command['payload']))->toBe([])
        ->and(AuditEntry::query()->where('action', 'network.ebpf_updated')->sole()->context)->toBe(['enabled' => true]);

    $gateway->succeed($command['handle'], ['supported' => true, 'enabled' => true, 'state' => 'running', 'programs' => ['dns', 'runq', 'tcp']]);
    $setting = EbpfSetting::query()->findOrFail($this->server->id);
    expect($setting->status)->toBe('applied')->and($setting->applied_enabled)->toBeTrue();
    // Applied: not sent again unless forced (an agent upgrade, which may have just brought the feature).
    expect(app(ConfigureEbpf::class)($this->server->id))->toBeNull();
    AgentVersionChanged::dispatch($enrolled['agent']->id, $this->organization->id, $this->server->id, 'v0.17.0', 'v0.18.0', ['net.ebpf']);
    expect($gateway->dispatched('net.ebpf.configure', $this->server->id))->toHaveCount(2);

    // A failed delivery is kept with its error.
    $this->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => false])->assertOk();
    $gateway->fail($gateway->last('net.ebpf.configure', $this->server->id)['handle'], 'agent busy');
    expect(EbpfSetting::query()->findOrFail($this->server->id))->status->toBe('failed')->error->toBe('agent busy');
});

it('keeps the setting for an agent without net.ebpf and sends it nothing', function () {
    $gateway = FakeAgentGateway::install();
    ebpf_agent($this, []);

    $this->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => true])->assertOk()
        ->assertJsonPath('servers.0.enabled', true)
        ->assertJsonPath('servers.0.agent_outdated', true);
    $gateway->assertNothingDispatched('net.ebpf.configure');
    $this->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => 'maybe'])->assertStatus(422);
});

it('needs network.manage to change it; viewers see it; other organizations get 404', function () {
    FakeAgentGateway::install();
    ebpf_agent($this, ['net.ebpf'], ['supported' => false, 'reason' => 'no kernel BTF (/sys/kernel/btf/vmlinux)', 'enabled' => false, 'state' => 'off', 'programs' => []]);

    actingAsMember(Role::Viewer, $this->organization);
    $this->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => true])->assertForbidden();
    $this->getJson("/network/servers/{$this->server->id}/ebpf")->assertOk()
        ->assertJsonPath('servers.0.can_manage', false)
        ->assertJsonPath('servers.0.probe.supported', false)
        ->assertJsonPath('servers.0.probe.reason', 'no kernel BTF (/sys/kernel/btf/vmlinux)');
    expect(EbpfSetting::query()->count())->toBe(0);

    [$stranger] = memberOf();
    $this->actingAs($stranger)->getJson("/network/servers/{$this->server->id}/ebpf")->assertNotFound();
    $this->actingAs($stranger)->putJson("/network/servers/{$this->server->id}/ebpf", ['enabled' => true])->assertNotFound();
});

it('queries nothing while the probe is off, and the server\'s metrics only once it runs', function () {
    ebpf_agent($this, ['net.ebpf'], ['supported' => true, 'enabled' => false, 'state' => 'off', 'programs' => []]);
    ebpf_vm([[['falak_net_dns_error' => 'servfail'], 3]], fn (string $q) => str_contains($q, 'falak_net_dns_name')
        ? [[['falak_net_dns_name' => 'api.stripe.com'], 40], [['falak_net_dns_name' => 'db.internal'], 90]] : []);
    $this->getJson("/network/servers/{$this->server->id}/ebpf")->assertOk()->assertJsonPath('charts', []);
    Http::assertNothingSent();

    EbpfSetting::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'enabled' => true, 'status' => 'applied']);
    $this->getJson("/network/servers/{$this->server->id}/ebpf?range=6h")->assertOk()
        ->assertJsonPath('step', 120)
        ->assertJsonPath('servers.0.enabled', true)
        ->assertJsonPath('charts.dns_failures.0.points.0', [1_790_000_000, 3])
        ->assertJsonPath('names', [['name' => 'db.internal', 'queries' => 90], ['name' => 'api.stripe.com', 'queries' => 40]])
        ->assertJsonStructure(['charts' => ['dns_queries', 'dns_p95', 'retransmits', 'resets', 'rtt_p95', 'connect_p95', 'runq_p99', 'overhead']]);
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'falak_server_id=~"'.strtoupper($this->server->id).'"'));
    Http::assertNotSent(fn (Request $r) => ! str_contains((string) $r['query'], strtoupper($this->server->id)));
});

it('scopes a site to its workload and servers, and a database to its instance', function () {
    ebpf_agent($this, ['net.ebpf'], ['supported' => true, 'enabled' => true, 'state' => 'running', 'programs' => ['tcp']]);
    EbpfSetting::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'enabled' => true, 'status' => 'applied']);
    $site = Site::query()->create(['organization_id' => $this->organization->id, 'name' => 'Shop', 'slug' => 'shop', 'runtime' => SiteRuntime::Compose,
        'build_mode' => BuildMode::Native, 'framework' => Framework::Laravel, 'web_directory' => 'public', 'unix_user' => 'falak', 'isolated' => false,
        'deploy_script' => '', 'laravel' => new LaravelSettings]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $this->server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);
    ebpf_vm();

    $this->getJson("/network/sites/{$site->id}/ebpf?service=web")->assertOk()->assertJsonPath('servers.0.server_id', $this->server->id);
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'falak_site_id="'.strtoupper($site->id).'"') && str_contains((string) $r['query'], 'falak_compose_service="web"'));
    Http::assertNotSent(fn (Request $r) => ! str_contains((string) $r['query'], 'falak_site_id="'.strtoupper($site->id).'"'));
    $this->getJson("/network/sites/{$site->id}/ebpf?server=01JZZZZZZZZZZZZZZZZZZZZZZZ")->assertStatus(422);

    $db = databases_instance($this->organization, 'postgresql', $this->server);
    $this->getJson("/network/databases/{$db->id}/ebpf")->assertOk();
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'falak_db_instance_id="'.$db->id.'"'));

    actingAsMember(Role::Viewer, $this->organization);
    $this->getJson("/network/sites/{$site->id}/ebpf")->assertOk();
    [$stranger] = memberOf();
    $this->actingAs($stranger)->getJson("/network/sites/{$site->id}/ebpf")->assertNotFound();
    $foreign = databases_instance(Organization::factory()->create(), 'postgresql');
    $this->actingAs($this->user)->getJson("/network/databases/{$foreign->id}/ebpf")->assertNotFound();
});

it('alerts on DNS failures and retransmit spikes once they hold, and clears them', function () {
    EbpfSetting::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'enabled' => true, 'status' => 'applied']);
    $other = network_server($this->organization, ['name' => 'app-2']);
    $server = strtoupper($this->server->id);
    $values = [
        // shop: 90 of 200 queries failing (alert); api: 70 of 1000 (7 %, no alert); app-2 has the setting off (ignored).
        'falak_net_dns_failures_total' => [[['falak_server_id' => $server, 'service_name' => 'shop', 'falak_workload_name' => 'shop-web-1'], 90],
            [['falak_server_id' => $server, 'service_name' => 'api', 'falak_workload_name' => 'api'], 70],
            [['falak_server_id' => strtoupper($other->id), 'service_name' => 'x', 'falak_workload_name' => 'x'], 500]],
        'falak_net_dns_queries_total' => [[['falak_server_id' => $server, 'service_name' => 'shop', 'falak_workload_name' => 'shop-web-1'], 200],
            [['falak_server_id' => $server, 'service_name' => 'api', 'falak_workload_name' => 'api'], 1000]],
        'falak_net_tcp_retransmits_total' => [[['falak_server_id' => $server, 'service_name' => 'falak-host', 'falak_workload_name' => 'host'], 1500],
            [['falak_server_id' => $server, 'service_name' => 'shop', 'falak_workload_name' => 'shop-web-1'], 12]],
    ];
    $live = true;
    ebpf_vm([], function (string $q) use (&$values, &$live) {
        expect($q)->toContain('[5m]');
        if (str_contains($q, 'falak_net_dns_failures_total')) {
            expect($q)->toContain('falak_net_dns_error!="nxdomain"');
        }
        foreach ($values as $metric => $rows) {
            if ($live && str_contains($q, $metric)) {
                return $rows;
            }
        }

        return [];
    });

    foreach (range(0, 6) as $minute) {
        Carbon::setTestNow(now()->addMinute());
        dispatch_sync(new CheckEbpfAlerts);
    }
    $raised = fn (string $type) => Alert::query()->where('type', $type)->where('recovery', false)->get();
    expect($raised(CheckEbpfAlerts::ALERT_DNS))->toHaveCount(1)
        ->and($raised(CheckEbpfAlerts::ALERT_DNS)->first()->title)->toBe('app-1: DNS lookups failing for shop/shop-web-1 (45% of queries)')
        ->and($raised(CheckEbpfAlerts::ALERT_RETRANSMITS))->toHaveCount(1)
        ->and($raised(CheckEbpfAlerts::ALERT_RETRANSMITS)->first()->title)->toBe('app-1: TCP retransmits spiking for the server');

    // The workloads stop reporting: the conditions clear.
    $live = false;
    Carbon::setTestNow(now()->addMinute());
    dispatch_sync(new CheckEbpfAlerts);
    expect(Alert::query()->where('type', CheckEbpfAlerts::ALERT_DNS)->where('recovery', true)->count())->toBe(1)
        ->and(Alert::query()->where('type', CheckEbpfAlerts::ALERT_RETRANSMITS)->where('recovery', true)->count())->toBe(1);
});
