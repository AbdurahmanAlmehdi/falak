<?php

use Falak\Identity\Contracts\Role;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'telemetry.metrics.backend' => 'victoriametrics',
        'telemetry.metrics.query_url' => 'http://vm:8428',
        'telemetry.loki.url' => 'http://loki:3100',
        'telemetry.tempo.url' => 'http://tempo:3200',
    ]);
    [$this->user, $this->organization] = actingAsMember(Role::Viewer);
});

function explorer_trace(string $orgId): array
{
    return ['trace' => ['resourceSpans' => [[
        'resource' => ['attributes' => [
            ['key' => 'service.name', 'value' => ['stringValue' => 'shop']],
            ['key' => 'falak.org.id', 'value' => ['stringValue' => strtoupper($orgId)]],
        ]],
        'scopeSpans' => [['spans' => [[
            'traceId' => '0af7651916cd43dd8448eb211c80319c', 'spanId' => '00f067aa0ba902b7', 'name' => 'GET /',
            'kind' => 2, 'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000100000000',
        ]]]],
    ]]]];
}

it('renders the explorer pages', function () {
    $server = Server::factory()->create(['organization_id' => $this->organization->id]);

    $this->get('/observability/logs?site_id=01JSQTE000000000000000000A&search=error')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Telemetry/Logs', false)->where('filters.search', 'error')->where('configured', true)->has('servers', 1)->has('sites', 0));
    $this->get('/observability/traces?min_duration_ms=1000')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Telemetry/Traces', false)->where('filters.min_duration_ms', '1000'));
    $this->get('/observability/traces/0af7651916cd43dd8448eb211c80319c')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Telemetry/Trace', false)->where('traceId', '0af7651916cd43dd8448eb211c80319c'));

    // Legacy explorer URLs redirect (query string kept).
    $this->get('/telemetry/logs?site_id=01JSQTE000000000000000000A')->assertRedirect('/observability/logs?site_id=01JSQTE000000000000000000A');
    $this->get('/telemetry/traces?status=error')->assertRedirect('/observability/traces?status=error');
    $this->get('/telemetry/traces/0af7651916cd43dd8448eb211c80319c')->assertRedirect('/observability/traces/0af7651916cd43dd8448eb211c80319c');
    $this->get("/telemetry/servers/{$server->id}/metrics")->assertRedirect("/servers/{$server->id}/metrics");
    $this->get('/observability/traces/not-a-trace')->assertNotFound();
});

it('serves server metrics scoped to the server and hides other organizations\' servers', function () {
    $server = Server::factory()->create(['organization_id' => $this->organization->id]);
    $foreign = Server::factory()->create();
    Http::fake(['vm:8428/api/v1/query_range' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
        ['metric' => [], 'values' => [[1_700_000_000, '12.5']]],
    ]]])]);

    $response = $this->getJson("/telemetry/servers/{$server->id}/metrics/data?range=6h")->assertOk()
        ->assertJsonPath('range', '6h')
        ->assertJsonPath('step', 120)
        ->assertJsonPath('charts.cpu.0.points.0', [1_700_000_000, 12.5]);
    expect(array_keys($response->json('charts')))->toBe(['cpu', 'memory', 'disk', 'load1', 'load5', 'load15', 'network', 'disk_io']);

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'falak_server_id="'.strtoupper($server->id).'"'));
    Http::assertNotSent(fn (Request $r) => ! str_contains($r['query'], 'falak_server_id="'.strtoupper($server->id).'"'));

    $this->getJson("/telemetry/servers/{$foreign->id}/metrics/data")->assertNotFound();
    $this->getJson("/telemetry/servers/{$foreign->id}/metrics")->assertNotFound();
    $this->getJson("/telemetry/servers/{$server->id}/metrics/data?range=1y")->assertUnprocessable();
});

it('reports an unavailable metrics backend as 503 and per-chart query errors inline', function () {
    $server = Server::factory()->create(['organization_id' => $this->organization->id]);
    Http::fake(['vm:8428/*' => Http::sequence()->push(['status' => 'error', 'error' => 'unknown func'], 422)->whenEmpty(Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]))]);

    $this->getJson("/telemetry/servers/{$server->id}/metrics/data")->assertOk()
        ->assertJsonPath('errors.cpu', 'VictoriaMetrics query failed: unknown func')
        ->assertJsonPath('charts.memory', []);

    config(['telemetry.metrics.query_url' => null]);
    $this->getJson("/telemetry/servers/{$server->id}/metrics/data")->assertStatus(503)->assertJsonPath('message', 'VictoriaMetrics is not configured.');
});

it('queries logs with the organization matcher and paginates with a cursor', function () {
    Http::fake(['loki:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['service_name' => 'shop'], 'values' => [['1700000000000000002', 'b', ['trace_id' => 'abc']], ['1700000000000000001', 'a']]],
    ]]])]);

    $this->getJson('/telemetry/logs/data?search=boom&limit=2&range=24h&service=shop')->assertOk()
        ->assertJsonPath('lines.0.line', 'b')
        ->assertJsonPath('lines.0.trace_id', 'abc')
        ->assertJsonPath('next_before', '1700000000000000001')
        ->assertJsonPath('query', '{falak_org_id="'.strtoupper($this->organization->id).'", service_name="shop"} |= "boom"');

    $this->getJson('/telemetry/logs/data?limit=2&before=1700000000000000002&from=2023-11-14T00:00:00Z&to=2023-11-15T00:00:00Z')->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.line', 'a')
        ->assertJsonPath('next_before', null);

    Http::assertSent(fn (Request $r) => str_starts_with($r['query'], '{falak_org_id="'.strtoupper($this->organization->id).'"'));
    Http::assertNotSent(fn (Request $r) => ! str_starts_with($r['query'], '{falak_org_id="'.strtoupper($this->organization->id).'"'));
});

it('rejects raw LogQL, bad filters and invalid regexes', function () {
    Http::fake(['loki:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]])]);

    $this->getJson('/telemetry/logs/data?server_id=not-a-ulid')->assertUnprocessable()->assertJsonValidationErrors('server_id');
    $this->getJson('/telemetry/logs/data?level=verbose')->assertUnprocessable();
    $this->getJson('/telemetry/logs/data?search=(oops&regex=1')->assertUnprocessable()->assertJsonValidationErrors('search');
    // Access records have no origin: the conflict is reported on origin, not on the search box.
    $this->getJson('/telemetry/logs/data?kind=access&origin=container_file')->assertUnprocessable()->assertJsonValidationErrors('origin')->assertJsonMissingValidationErrors('search');
    $this->getJson('/telemetry/logs/data?from=2026-01-01&to=2025-01-01')->assertUnprocessable();
    $this->getJson('/telemetry/logs/data?query={falak_org_id=~".%2B"}')->assertOk()->assertJsonPath('query', '{falak_org_id="'.strtoupper($this->organization->id).'"}');

    Http::assertNotSent(fn (Request $r) => str_contains((string) $r['query'], '=~".+"'));
});

it('searches traces within the organization', function () {
    Http::fake(['tempo:3200/api/search*' => Http::response(['traces' => [['traceID' => '0af7651916cd43dd8448eb211c80319c', 'rootServiceName' => 'shop', 'rootTraceName' => 'GET /', 'startTimeUnixNano' => '1', 'durationMs' => 5]]])]);

    $this->getJson('/telemetry/traces/search?status=error&min_duration_ms=250&service=shop')->assertOk()
        ->assertJsonPath('traces.0.trace_id', '0af7651916cd43dd8448eb211c80319c')
        ->assertJsonPath('query', '{ resource.falak.org.id = "'.strtoupper($this->organization->id).'" && resource.service.name = "shop" && duration >= 250ms && status = error }');
});

it('returns traces of the current organization only', function () {
    [, $other] = memberOf();
    Http::fake([
        'tempo:3200/api/v2/traces/0af7651916cd43dd8448eb211c80319c' => Http::response(explorer_trace($this->organization->id)),
        'tempo:3200/api/v2/traces/1af7651916cd43dd8448eb211c80319c' => Http::response(explorer_trace($other->id)),
        'tempo:3200/api/v2/traces/*' => Http::response('trace not found', 404),
    ]);

    $this->getJson('/telemetry/traces/0af7651916cd43dd8448eb211c80319c/data')->assertOk()
        ->assertJsonPath('trace.trace_id', '0af7651916cd43dd8448eb211c80319c')
        ->assertJsonPath('trace.spans.0.name', 'GET /')
        ->assertJsonPath('trace.spans.0.duration_ms', 100);
    $this->getJson('/telemetry/traces/1af7651916cd43dd8448eb211c80319c/data')->assertNotFound();
    $this->getJson('/telemetry/traces/2af7651916cd43dd8448eb211c80319c/data')->assertNotFound();

    config(['telemetry.tempo.url' => null]);
    $this->getJson('/telemetry/traces/0af7651916cd43dd8448eb211c80319c/data')->assertStatus(503);
});

it('requires authentication and telemetry.view', function () {
    auth()->logout();
    $this->getJson('/telemetry/logs/data')->assertUnauthorized();
    $this->get('/observability/traces')->assertRedirect('/login');
});
