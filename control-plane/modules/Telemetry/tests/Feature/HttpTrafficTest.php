<?php

use Falak\Identity\Contracts\Role;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Falak\Telemetry\Tests\Support\MmdbWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Loki / VictoriaMetrics answer per query: the first entry of $routes whose key the query contains.
 *
 * @param  array<string, list<array{0: array<string, string>, 1: string}>>  $routes  substring => [[labels, value], …]
 */
function http_matrix(array $routes): Closure
{
    return function (Request $r) use ($routes) {
        foreach ($routes as $needle => $rows) {
            if (str_contains((string) $r['query'], $needle)) {
                $result = array_map(fn (array $row) => ['metric' => $row[0], 'values' => [[1_790_000_000, $row[1]]]], $rows);

                return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]]);
            }
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]);
    };
}

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'telemetry.metrics.backend' => 'victoriametrics',
        'telemetry.metrics.query_url' => 'http://vm:8428',
        'telemetry.loki.url' => 'http://loki:3100',
        'telemetry.geoip.path' => sys_get_temp_dir().'/falak-geoip-http-'.bin2hex(random_bytes(4)),
    ]);
    [$this->user, $this->organization] = actingAsMember(Role::Viewer);
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id]);
    $this->site = Site::query()->create([
        'organization_id' => $this->organization->id, 'name' => 'Shop', 'slug' => 'shop', 'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native, 'framework' => Framework::Laravel, 'php_version' => '8.4', 'web_directory' => 'public',
        'unix_user' => 'shop', 'isolated' => true, 'deploy_script' => '$FALAK_FETCH', 'laravel' => new LaravelSettings,
    ]);
    SiteTarget::query()->create(['site_id' => $this->site->id, 'server_id' => $this->server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);
    $this->org = strtoupper($this->organization->id);
});

it('charts the site from the agent aggregates', function () {
    Http::fake(['vm:8428/*' => http_matrix(['falak_net_http_requests_total' => [[['http_response_status_class' => '2xx'], '4.5']]])]);

    $response = $this->getJson("/telemetry/sites/{$this->site->id}/http/data?range=6h")->assertOk()
        ->assertJsonPath('source', 'agent')
        ->assertJsonPath('charts.requests.0.labels.http_response_status_class', '2xx')
        ->assertJsonPath('can_manage', false);
    expect(array_keys($response->json('charts')))->toBe(['requests', 'bytes', 'p95', 'protocols', 'tls', 'threats']);

    $selector = 'falak_org_id="'.$this->org.'",service_name="shop"';
    Http::assertSent(fn (Request $r) => $r['query'] === "sum by (http_response_status_class) (rate(falak_net_http_requests_total{{$selector}}[300s]))");
    Http::assertSent(fn (Request $r) => $r['query'] === "histogram_quantile(0.95, sum by (le) (rate(falak_net_http_server_duration_milliseconds_bucket{{$selector}}[300s])))");
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'loki'));
});

it('falls back to the access log for agents without aggregates', function () {
    Http::fake([
        'vm:8428/*' => http_matrix([]),
        'loki:3100/*' => http_matrix(['label_replace' => [[['http_response_status_class' => '5xx'], '0.2']], 'unwrap http_response_body_size' => [[[], '2048']]]),
    ]);

    $this->getJson("/telemetry/sites/{$this->site->id}/http/data?server={$this->server->id}")->assertOk()
        ->assertJsonPath('source', 'edge-logs')
        ->assertJsonPath('charts.requests.0.labels.http_response_status_class', '5xx')
        ->assertJsonPath('charts.bytes.0.points.0.1', 2048);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'loki') && str_contains($r['query'], 'falak_server_id="'.strtoupper($this->server->id).'"')
        && str_contains($r['query'], 'user_agent_original!~"Falak-HealthCheck/.*"'));
});

it('serves drill-down tables with countries and networks', function () {
    $dir = config('telemetry.geoip.path');
    mkdir($dir);
    (new MmdbWriter)->add('203.0.113.0/24', ['country' => ['iso_code' => 'DE', 'names' => ['en' => 'Germany']]])
        ->add('198.51.100.0/24', ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]])->write($dir.'/country.mmdb');
    (new MmdbWriter)->add('203.0.113.0/24', ['autonomous_system_number' => 64500, 'autonomous_system_organization' => 'Example Net'])->write($dir.'/asn.mmdb');

    Http::fake(['loki:3100/*' => http_matrix([
        'falak_threat_rule!=""' => [[['client_address' => '203.0.113.7', 'falak_threat_rule' => 'env'], '40'], [['client_address' => '203.0.113.7', 'falak_threat_rule' => 'git'], '3']],
        'http_response_status_code="404"' => [[['client_address' => '198.51.100.9'], '120']],
        'sum by (client_address)' => [[['client_address' => '203.0.113.7'], '90'], [['client_address' => '198.51.100.9'], '10'], [['client_address' => '192.0.2.1'], '100']],
        'sum(count_over_time' => [[[], '400']],
        'quantile_over_time' => [[['url_route' => '/orders/:id'], '1500'], [['url_route' => '/rare'], '9000']],
        'sum by (url_route)' => [[['url_route' => '/orders/:id'], '30'], [['url_route' => '/rare'], '2']],
    ])]);
    $top = fn (string $table) => $this->getJson("/telemetry/sites/{$this->site->id}/http/top?table={$table}&range=24h")->assertOk();

    $top('paths')->assertJsonPath('rows.0', ['route' => '/orders/:id', 'requests' => 30]);
    // Rarely called routes are left out of the slowest.
    $top('slow')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0', ['route' => '/orders/:id', 'p95_ms' => 1500, 'requests' => 30]);
    $top('clients')->assertJsonPath('rows.0.ip', '192.0.2.1')->assertJsonPath('rows.0.country', null)
        ->assertJsonPath('rows.1', ['ip' => '203.0.113.7', 'requests' => 90, 'country' => 'DE', 'country_name' => 'Germany', 'asn' => 64500, 'as_org' => 'Example Net', 'unblockable' => null])
        ->assertJsonPath('geo.attribution', 'IP geolocation by DB-IP');
    $top('countries')->assertJsonPath('rows.0', ['country' => 'DE', 'country_name' => 'Germany', 'requests' => 90, 'clients' => 1])
        ->assertJsonPath('networks.0.as_org', 'Example Net')
        ->assertJsonPath('coverage', 0.25);
    $top('threats')->assertJsonPath('rows.0.ip', '198.51.100.9')->assertJsonPath('rows.0.not_found', 120)
        ->assertJsonPath('rows.1.rules', ['env' => 40, 'git' => 3])->assertJsonPath('rows.1.country', 'DE');

    Http::assertSent(fn (Request $r) => $r['query'] === 'topk(20, sum by (url_route) (count_over_time({falak_org_id="'.$this->org.'", service_name="shop", falak_log_kind="access"} | user_agent_original!~"Falak-HealthCheck/.*" | url_route!="" [86400s])))'
        && $r['start'] === $r['end']);
    $this->getJson("/telemetry/sites/{$this->site->id}/http/top?table=nope")->assertUnprocessable();

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('links a request id to its access line, the app logs and the trace', function () {
    Http::fake(['loki:3100/*' => function (Request $r) {
        $access = str_contains($r['query'], 'http_request_id=');

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [[
            'stream' => ['service_name' => 'shop'],
            'values' => [$access
                ? ['1790000000000000000', 'GET /cart 500 4.0ms', ['http_request_method' => 'GET', 'url_path' => '/cart', 'http_response_status_code' => '500', 'http_request_id' => 'abc-12345-request']]
                : ['1790000000100000000', 'production.ERROR: boom {"request_id":"abc-12345-request"}', ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736']]],
        ]]]]);
    }]);

    $this->getJson("/telemetry/sites/{$this->site->id}/requests/abc-12345-request")->assertOk()
        ->assertJsonPath('access.0.request_id', 'abc-12345-request')
        ->assertJsonPath('app.0.line', 'production.ERROR: boom {"request_id":"abc-12345-request"}')
        ->assertJsonPath('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'falak_log_kind!="access"') && str_contains($r['query'], '|= "abc-12345-request"')
        && str_contains($r['query'], 'falak_site_id="'.strtoupper($this->site->id).'"'));
    $this->getJson("/telemetry/sites/{$this->site->id}/requests/bad%20id!")->assertNotFound();
});

it('keeps per-site settings for those who manage the site', function () {
    $this->getJson("/telemetry/sites/{$this->site->id}/http/settings")->assertOk()->assertJsonPath('slow_ms', 1000)->assertJsonPath('slow_min_requests', 20);
    $this->putJson("/telemetry/sites/{$this->site->id}/http/settings", ['slow_ms' => 500])->assertForbidden();

    actingAsMember(Role::Developer, $this->organization);
    $this->putJson("/telemetry/sites/{$this->site->id}/http/settings", ['slow_ms' => 50])->assertUnprocessable()->assertJsonValidationErrors('slow_ms');
    $this->putJson("/telemetry/sites/{$this->site->id}/http/settings", ['slow_ms' => 500, 'bandwidth_alert_bytes' => 10 * 1024 ** 3])->assertOk()
        ->assertJsonPath('slow_ms', 500)->assertJsonPath('slow_min_requests', 20)->assertJsonPath('bandwidth_alert_bytes', 10 * 1024 ** 3);
    expect(HttpSettings::query()->find($this->site->id)->slow_ms)->toBe(500);

    $this->getJson("/telemetry/sites/{$this->site->id}/http/bandwidth")->assertOk()->assertJsonPath('threshold_bytes', 10 * 1024 ** 3)->assertJsonPath('months', []);
});

it('charts a server\'s edge over every site it serves', function () {
    Http::fake(['vm:8428/*' => http_matrix(['by (service_name)' => [[['service_name' => 'shop'], '2']]])]);

    $this->getJson("/telemetry/servers/{$this->server->id}/http/data")->assertOk()->assertJsonPath('charts.sites.0.labels.service_name', 'shop');
    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'falak_org_id="'.$this->org.'",falak_server_id="'.strtoupper($this->server->id).'"'));
});

it('hides other organizations\' sites and servers', function () {
    actingAsMember(Role::Owner);

    $this->getJson("/telemetry/sites/{$this->site->id}/http/data")->assertNotFound();
    $this->getJson("/telemetry/sites/{$this->site->id}/http/top?table=paths")->assertNotFound();
    $this->getJson("/telemetry/sites/{$this->site->id}/requests/abc-12345-request")->assertNotFound();
    $this->putJson("/telemetry/sites/{$this->site->id}/http/settings", ['slow_ms' => 500])->assertNotFound();
    $this->getJson("/telemetry/servers/{$this->server->id}/http/data")->assertNotFound();
});
