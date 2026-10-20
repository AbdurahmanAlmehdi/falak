<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Databases\Application\Actions\ApplyInstance;
use Falak\Databases\Application\Jobs\PruneObservations;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    Carbon::setTestNow('2026-10-09 12:00:00');
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = databases_server($this->organization);
    $this->pg = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-pg']);
});

afterEach(fn () => Carbon::setTestNow());

/** Posts a db.observed report as the server's agent. */
function observed(object $test, array $instances, ?string $serverId = null): TestResponse
{
    $serverId ??= $test->server->id;
    $test->enrolled[$serverId] ??= (function () use ($test, $serverId) {
        $enrolled = fleet_enroll($test->organization->id, $serverId, ['features' => ['db.observability']]);
        Certificate::query()->where('fingerprint', $enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);

        return $enrolled;
    })();

    return $test->postJson('/agent/v1/requests/db.observed', ['instances' => $instances], fleet_mtls($test->enrolled[$serverId]['fingerprint']));
}

function pg_report(DatabaseInstance $instance, array $overrides = []): array
{
    return [
        'id' => $instance->id,
        'engine' => 'postgres',
        'at' => now()->toIso8601String(),
        'window_s' => 60,
        'version' => '17.2',
        'query_insights' => ['status' => 'on'],
        'gauges' => ['connections' => 12, 'connections_active' => 2, 'connections_idle' => 10, 'max_connections' => 100],
        'deltas' => ['slow_queries' => 1, 'deadlocks' => 0, 'cache_hits' => 99000, 'cache_misses' => 1000],
        'labels' => ['role' => 'primary'],
        'settings' => ['slow_query_ms' => 1000, 'shared_buffers_bytes' => 128 * 1024 ** 2, 'stats_age_s' => 30 * 86400],
        'memory' => ['used_bytes' => 200 * 1024 ** 2, 'limit_bytes' => 512 * 1024 ** 2],
        'statements' => [
            ['fingerprint' => 'pg:42', 'database' => 'shop', 'query' => 'SELECT * FROM orders WHERE customer_id = $1', 'calls' => 10, 'total_ms' => 25000, 'max_ms' => 4000, 'rows' => 100, 'source' => 'pg_stat_statements'],
            ['fingerprint' => 'pg:43', 'database' => 'shop', 'query' => 'SELECT 1', 'calls' => 1000, 'total_ms' => 50, 'max_ms' => 1, 'rows' => 1000, 'source' => 'pg_stat_statements'],
        ],
        'databases' => [['name' => 'shop', 'size_bytes' => 5 * 1024 ** 3]],
        'tables' => [['database' => 'shop', 'schema' => 'public', 'name' => 'orders', 'size_bytes' => 2 * 1024 ** 3, 'rows' => 10_000_000, 'seq_scan' => 5000, 'seq_tup_read' => 900_000_000, 'idx_scan' => 10]],
        'indexes' => [['database' => 'shop', 'schema' => 'public', 'table' => 'orders', 'name' => 'orders_note_idx', 'size_bytes' => 300 * 1024 ** 2, 'scans' => 0]],
        ...$overrides,
    ];
}

it('records a report: snapshot, query shapes, hourly stats and the size sample', function () {
    observed($this, [pg_report($this->pg)])->assertOk()->assertJson(['accepted' => 1]);
    observed($this, [pg_report($this->pg, ['at' => now()->addMinute()->toIso8601String()])])->assertOk();

    $this->pg->refresh();
    expect($this->pg->observed_at->toIso8601String())->toBe(now()->addMinute()->toIso8601String())
        ->and($this->pg->observed['gauges']['connections'])->toBe(12)
        ->and($this->pg->observed['tables'][0]['name'])->toBe('orders')
        ->and($this->pg->observed)->not->toHaveKey('statements');

    $shape = DB::table('databases_query_shapes')->where('fingerprint', 'pg:42')->first();
    $stats = DB::table('databases_query_stats')->where('shape_id', $shape->id)->get();
    expect($shape->query)->toBe('SELECT * FROM orders WHERE customer_id = $1')
        ->and($stats)->toHaveCount(1)
        ->and((int) $stats[0]->calls)->toBe(20)
        ->and((float) $stats[0]->total_ms)->toBe(50000.0)
        ->and((float) $stats[0]->max_ms)->toBe(4000.0);
    expect((int) DB::table('databases_size_samples')->where('database_instance_id', $this->pg->id)->value('size_bytes'))->toBe(5 * 1024 ** 3);
});

it('ignores instances of other servers and organizations, other engines and invalid reports', function () {
    $other = databases_instance($this->organization, 'mysql', databases_server($this->organization));
    $foreign = databases_instance(Organization::factory()->create(), 'postgresql');

    observed($this, [pg_report($other, ['engine' => 'mysql']), pg_report($foreign), pg_report($this->pg, ['engine' => 'mysql'])])
        ->assertOk()->assertJson(['accepted' => 0]);
    expect($other->refresh()->observed)->toBeNull()->and($foreign->refresh()->observed)->toBeNull()->and($this->pg->refresh()->observed)->toBeNull();

    observed($this, [[...pg_report($this->pg), 'statements' => [['fingerprint' => 'pg:1', 'query' => 'x', 'calls' => 1, 'total_ms' => 1, 'source' => 'magic']]]])->assertStatus(422);
    observed($this, [[...pg_report($this->pg), 'password' => 'x']])->assertStatus(422);
});

it('turns slow query shapes and engine errors into database issues, and reopens resolved ones', function () {
    $report = pg_report($this->pg, ['errors' => [
        ['kind' => 'deadlock', 'count' => 2, 'message' => 'deadlock detected'],
        ['kind' => 'auth_failed', 'count' => 3],
    ]]);
    observed($this, [$report])->assertOk();

    $issues = Issue::query()->where('kind', IssueKind::Database)->get();
    expect($issues)->toHaveCount(2)
        ->and($issues->pluck('title')->all())->toContain('Slow query: SELECT * FROM orders WHERE customer_id = $1', 'Deadlocks on main-pg');

    $slow = $issues->firstWhere('title', 'Slow query: SELECT * FROM orders WHERE customer_id = $1');
    expect($slow->occurrences)->toBe(10)
        ->and($slow->server_id)->toBe($this->pg->server_id)
        ->and($slow->meta['instance_id'])->toBe($this->pg->id)
        ->and($slow->fingerprint)->toBe(hash('sha256', $this->pg->id.'|pg:42'));

    // Repeated auth failures make an issue from 5 a minute.
    observed($this, [pg_report($this->pg, ['statements' => [], 'errors' => [['kind' => 'auth_failed', 'count' => 6]]])])->assertOk();
    expect(Issue::query()->where('kind', IssueKind::Database)->count())->toBe(3);

    $slow->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now()])->save();
    Carbon::setTestNow(now()->addHour());
    observed($this, [pg_report($this->pg)])->assertOk();
    expect($slow->refresh()->status)->toBe(IssueStatus::Open)->and($slow->regressions)->toBe(1)->and($slow->occurrences)->toBe(20);
});

it('alerts on slow query spikes, deadlocks, replication lag and memory pressure', function () {
    config(['databases.observability.alert_seconds' => 0]);
    $kv = databases_instance($this->organization, 'redis', $this->server, ['name' => 'cache']);

    observed($this, [
        pg_report($this->pg, ['deltas' => ['slow_queries' => 45, 'deadlocks' => 3], 'gauges' => ['replication_lag_s' => 120]]),
        ['id' => $kv->id, 'engine' => 'redis', 'at' => now()->toIso8601String(), 'window_s' => 60, 'gauges' => ['used_memory' => 95, 'maxmemory' => 100], 'labels' => ['maxmemory_policy' => 'noeviction']],
    ])->assertOk()->assertJson(['accepted' => 2]);

    $types = Alert::query()->where('recovery', false)->pluck('type')->all();
    expect($types)->toContain('databases.slow_queries_spike', 'databases.deadlocks', 'databases.replication_lag', 'databases.memory_high');

    // Memory: a Redis at maxmemory with an eviction policy is working as meant; a container is measured without its
    // page cache (the agent reports used_bytes without it), so a busy cache is no alert either.
    $lru = databases_instance($this->organization, 'valkey', $this->server, ['name' => 'lru']);
    $cached = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'cached']);
    observed($this, [
        ['id' => $lru->id, 'engine' => 'valkey', 'at' => now()->toIso8601String(), 'window_s' => 60, 'gauges' => ['used_memory' => 99, 'maxmemory' => 100], 'labels' => ['maxmemory_policy' => 'allkeys-lru'], 'memory' => ['used_bytes' => 100, 'limit_bytes' => 1000]],
        pg_report($cached, ['memory' => ['used_bytes' => 300 * 1024 ** 2, 'limit_bytes' => 512 * 1024 ** 2]]),
    ])->assertOk();
    $memoryAlerts = Alert::query()->where('type', 'databases.memory_high')->where('recovery', false)->get();
    expect($memoryAlerts->filter(fn (Alert $a) => in_array($a->context['instance_id'] ?? null, [$lru->id, $cached->id], true)))->toHaveCount(0);

    // Deadlocks raise once an hour per instance.
    observed($this, [pg_report($this->pg, ['deltas' => ['deadlocks' => 1]])])->assertOk();
    expect(Alert::query()->where('type', 'databases.deadlocks')->where('outcome', '!=', 'deduplicated')->count())->toBe(1);
});

it('serves the insights tab: status, issues, top queries and recommendations', function () {
    observed($this, [pg_report($this->pg)])->assertOk();

    $this->getJson("/databases/instances/{$this->pg->id}/insights?range=7d")->assertOk()
        ->assertJsonPath('reporting', 'ok')
        ->assertJsonPath('query_insights.status', 'on')
        ->assertJsonPath('top_queries.0.fingerprint', 'pg:42')
        ->assertJsonPath('top_queries.0.mean_ms', 2500)
        ->assertJsonPath('top_queries.1.fingerprint', 'pg:43')
        ->assertJsonPath('issues.0.title', 'Slow query: SELECT * FROM orders WHERE customer_id = $1')
        ->assertJsonFragment(['key' => 'seq_scan:shop.orders'])
        ->assertJsonFragment(['key' => 'unused_index:shop.orders.orders_note_idx']);

    // A stale instance: its image predates phase 3 (no report): query insights can't be turned on yet, and says why.
    $quiet = databases_instance($this->organization, 'mariadb', $this->server, ['memory_bytes' => 2 * 1024 ** 3]);
    $this->getJson("/databases/instances/{$quiet->id}/insights")->assertOk()
        ->assertJsonPath('reporting', 'image_outdated')
        ->assertJsonPath('query_insights.can_enable', false)
        ->assertJsonPath('query_insights.blocked', 'Update the database image first (a minor upgrade): this one does not report query insights yet.');
});

it('lets viewers read the tabs but not enable query insights; other organizations see nothing', function () {
    Http::preventStrayRequests();
    config(['telemetry.metrics.backend' => 'victoriametrics', 'telemetry.metrics.query_url' => 'http://vm:8428', 'telemetry.loki.url' => 'http://loki:3100']);
    Http::fake([
        'vm:8428/api/v1/query_range' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['falak_db_connection_state' => 'active'], 'values' => [[1_790_000_000, '3']]],
        ]]]),
        'loki:3100/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['service_name' => $this->pg->container(), 'falak_log_kind' => 'db.slow'], 'values' => [
                ['1790000000000000002', '1500 ms SELECT * FROM orders WHERE id = ?', ['falak_db_duration_ms' => '1500', 'db_namespace' => 'shop']],
            ]],
        ]]]),
    ]);
    [, $this->organization] = actingAsMember(Role::Viewer, $this->organization);

    $this->getJson("/databases/instances/{$this->pg->id}/metrics/data?range=6h")->assertOk()
        ->assertJsonPath('step', 120)
        ->assertJsonPath('charts.connections.0.points.0', [1_790_000_000, 3]);
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'falak_db_instance_id="'.$this->pg->id.'"'));

    $this->getJson("/databases/instances/{$this->pg->id}/logs/data?kind=slow&search=orders")->assertOk()
        ->assertJsonPath('lines.0.kind', 'slow')
        ->assertJsonPath('lines.0.duration_ms', 1500)
        ->assertJsonPath('lines.0.database', 'shop');
    Http::assertSent(fn (Request $r) => str_contains((string) $r['query'], 'service_name="'.$this->pg->container().'", falak_log_kind="db.slow"} |= "orders"'));

    $this->getJson("/databases/instances/{$this->pg->id}/insights")->assertOk();
    $this->postJson("/databases/instances/{$this->pg->id}/query-insights")->assertForbidden();

    actingAsMember(Role::Owner);
    $this->getJson("/databases/instances/{$this->pg->id}/insights")->assertNotFound();
    $this->getJson("/databases/instances/{$this->pg->id}/metrics/data")->assertNotFound();
});

it('enables query insights on request only, with apply_query_insights on that one db.instance.update', function () {
    $agents = FakeAgentGateway::install();
    observed($this, [pg_report($this->pg, ['query_insights' => ['status' => 'off', 'reason' => 'not loaded']])])->assertOk();
    expect($this->pg->refresh()->query_insights)->toBeFalse();
    $this->getJson("/databases/instances/{$this->pg->id}/insights")->assertJsonPath('query_insights.can_enable', true)->assertJsonPath('query_insights.pending', false);

    $this->postJson("/databases/instances/{$this->pg->id}/query-insights")->assertOk()->assertJson(['query_insights' => true]);

    expect($this->pg->refresh()->query_insights)->toBeTrue();
    $sent = $agents->last('db.instance.update');
    expect($sent['payload']['instance']['query_insights'])->toBeTrue()->and($sent['payload']['apply_query_insights'])->toBeTrue();
    // Until a report says it is on: pending, and it can be applied again (never stuck).
    $this->getJson("/databases/instances/{$this->pg->id}/insights")->assertJsonPath('query_insights.pending', true)->assertJsonPath('query_insights.can_enable', true);
    observed($this, [pg_report($this->pg, ['at' => now()->addMinute()->toIso8601String()])])->assertOk();
    $this->getJson("/databases/instances/{$this->pg->id}/insights")->assertJsonPath('query_insights.pending', false)->assertJsonPath('query_insights.can_enable', false);

    // Every other update (settings, network convergence, PITR, certificates) never carries it: no restart for it.
    $this->putJson("/databases/instances/{$this->pg->id}", ['settings' => ['slow_query_ms' => 500]])->assertSuccessful();
    expect($agents->last('db.instance.update')['payload'])->not->toHaveKey('apply_query_insights');
    app(ApplyInstance::class)($this->pg->refresh(), background: true);
    expect($agents->last('db.instance.update')['payload'])->not->toHaveKey('apply_query_insights')
        ->and($agents->last('db.instance.update')['payload']['instance']['query_insights'])->toBeTrue();

    $kv = databases_instance($this->organization, 'redis', $this->server);
    $this->postJson("/databases/instances/{$kv->id}/query-insights")->assertUnprocessable();
});

it('refuses query insights without an agent and an image that apply it, and for MariaDB below 1 GiB', function () {
    $agents = FakeAgentGateway::install();
    $off = ['query_insights' => ['status' => 'off']];

    // No agent with db.observability on the server.
    $this->postJson("/databases/instances/{$this->pg->id}/query-insights")->assertUnprocessable()->assertJsonValidationErrors(['instance' => 'Update the agent']);

    // An agent with it, but no report from the image yet.
    $other = databases_server($this->organization);
    $noReport = databases_instance($this->organization, 'postgresql', $other);
    fleet_enroll($this->organization->id, $other->id, ['features' => ['db.observability']]);
    $this->postJson("/databases/instances/{$noReport->id}/query-insights")->assertUnprocessable()->assertJsonValidationErrors(['instance' => 'Update the database image']);

    // MariaDB with 512 MiB: performance_schema would not fit.
    $small = databases_instance($this->organization, 'mariadb', $this->server, ['memory_bytes' => 512 * 1024 ** 2]);
    observed($this, [[...pg_report($small, $off), 'engine' => 'mariadb', 'statements' => []]])->assertOk();
    $this->postJson("/databases/instances/{$small->id}/query-insights")->assertUnprocessable()->assertJsonValidationErrors(['instance' => '1 GiB']);
    $big = databases_instance($this->organization, 'mariadb', $this->server, ['memory_bytes' => 1024 ** 3]);
    observed($this, [[...pg_report($big, $off), 'engine' => 'mariadb', 'statements' => []]])->assertOk();
    $this->postJson("/databases/instances/{$big->id}/query-insights")->assertOk();

    expect($small->refresh()->query_insights)->toBeFalse()->and($big->refresh()->query_insights)->toBeTrue()
        ->and($agents->dispatched('db.instance.update'))->toHaveCount(1);
});

it('creates PostgreSQL and MariaDB (from 1 GiB) instances with query insights through agents that pass it on', function () {
    $agents = FakeAgentGateway::install();

    // An agent without db.observability: nobody gets it (a later update would carry it to an upgraded agent).
    $this->post('/databases/instances', ['engine' => 'postgresql', 'server_id' => $this->server->id, 'name' => 'db-old-agent'])->assertSessionHasNoErrors();
    expect(DatabaseInstance::query()->where('name', 'db-old-agent')->firstOrFail()->query_insights)->toBeFalse();

    fleet_enroll($this->organization->id, $this->server->id, ['features' => ['db.observability']]);

    foreach (['postgresql' => [512, true], 'mariadb' => [1024, true], 'mysql' => [1024, false], 'redis' => [128, false]] as $engine => [$mb, $on]) {
        $this->post('/databases/instances', ['engine' => $engine, 'server_id' => $this->server->id, 'name' => "db-{$engine}", 'memory_mb' => $mb])->assertSessionHasNoErrors();
        $instance = DatabaseInstance::query()->where('name', "db-{$engine}")->firstOrFail();
        $payload = $agents->last('db.instance.create')['payload']['instance'];

        expect($instance->query_insights)->toBe($on, $engine);
        $on ? expect($payload['query_insights'])->toBeTrue() : expect($payload)->not->toHaveKey('query_insights');
    }

    $this->post('/databases/instances', ['engine' => 'mariadb', 'server_id' => $this->server->id, 'name' => 'db-small-maria', 'memory_mb' => 512])->assertSessionHasNoErrors();
    expect(DatabaseInstance::query()->where('name', 'db-small-maria')->firstOrFail()->query_insights)->toBeFalse();
});

it('keeps query insights through a major upgrade where the agent applies it', function () {
    $agents = FakeAgentGateway::install();
    [, , $instance] = databases_service($this->organization, 'postgresql', 'shop', $this->server, ['version' => '16', 'image' => 'ghcr.io/falak-sh/falak-postgres:16', 'query_insights' => true]);
    fleet_enroll($this->organization->id, $this->server->id, ['features' => ['db.observability']]);

    $this->post("/databases/instances/{$instance->id}/upgrade", ['version' => '17'])->assertSessionHasNoErrors();

    $target = DatabaseInstance::query()->where('upgrade_of', $instance->id)->firstOrFail();
    expect($target->query_insights)->toBeTrue()
        ->and($agents->last('db.instance.create')['payload']['instance']['query_insights'])->toBeTrue();
});

it('raises a slow query issue only once its shape repeats within the hour', function () {
    config(['databases.observability.issue_min_calls' => 5]);
    $once = fn (int $calls, string $at) => pg_report($this->pg, ['at' => $at, 'statements' => [
        ['fingerprint' => 'pg:77', 'database' => 'shop', 'query' => 'SELECT pg_sleep($1)', 'calls' => $calls, 'total_ms' => 2000.0 * $calls, 'max_ms' => 2000, 'source' => 'pg_stat_statements'],
    ]]);

    observed($this, [$once(2, now()->toIso8601String())])->assertOk();
    expect(Issue::query()->where('fingerprint', hash('sha256', $this->pg->id.'|pg:77'))->exists())->toBeFalse();

    // The hour now has 6 slow calls: an Issue.
    observed($this, [$once(4, now()->addMinutes(5)->toIso8601String())])->assertOk();
    expect(Issue::query()->where('fingerprint', hash('sha256', $this->pg->id.'|pg:77'))->value('occurrences'))->toBe(4);
});

it('prunes old stats,stale shapes, old samples and what deleted instances left', function () {
    observed($this, [pg_report($this->pg)])->assertOk();
    $gone = databases_instance($this->organization, 'postgresql', $this->server);
    observed($this, [pg_report($gone)])->assertOk();
    $gone->delete();

    Carbon::setTestNow(now()->addDays(31));
    observed($this, [pg_report($this->pg, ['statements' => [['fingerprint' => 'pg:42', 'query' => 'SELECT * FROM orders WHERE customer_id = $1', 'calls' => 1, 'total_ms' => 1, 'source' => 'pg_stat_statements']]])])->assertOk();
    dispatch_sync(new PruneObservations);

    expect(DB::table('databases_query_shapes')->pluck('fingerprint')->all())->toBe(['pg:42'])
        ->and(DB::table('databases_query_stats')->count())->toBe(1)
        ->and(DB::table('databases_size_samples')->count())->toBe(1);
});
