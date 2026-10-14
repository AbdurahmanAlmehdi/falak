<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Application\Jobs\PruneInsightsJob;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteDeleted;
use Falak\Telemetry\Application\Http\EvaluateHttpTraffic;
use Falak\Telemetry\Application\Http\RecordBandwidth;
use Falak\Telemetry\Application\Jobs\AnalyzeHttpTraffic;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fakes Loki (and VictoriaMetrics) with one answer per query fragment; $state is read on every request, so a test can
 * change what Loki answers between evaluations.
 */
function http_fake_backends(ArrayObject $state): void
{
    $answer = function (Request $r) use ($state) {
        foreach ($state as $needle => $rows) {
            if (str_contains((string) $r['query'], $needle)) {
                if ($rows === 'fail') {
                    return Http::response(['status' => 'error', 'error' => 'maximum of series reached'], 400);
                }

                $result = array_map(fn (array $row) => ['metric' => $row[0], 'values' => [[1_790_000_000, $row[1]]]], $rows);

                return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]]);
            }
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]);
    };
    $vector = function (Request $r) use ($state) {
        foreach ($state as $needle => $rows) {
            if (str_contains((string) $r['query'], $needle)) {
                if ($rows === 'fail') {
                    return Http::response(['status' => 'error', 'error' => 'maximum of series reached'], 400);
                }

                $result = array_map(fn (array $row) => ['metric' => $row[0], 'value' => [1_790_000_000, $row[1]]], $rows);

                return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $result]]);
            }
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    };
    Http::fake(['loki:3100/*' => $answer, 'vm:8428/api/v1/query_range*' => $answer, 'vm:8428/api/v1/query*' => $vector]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-15 12:00:30');
    config(['telemetry.loki.url' => 'http://loki:3100', 'telemetry.metrics.backend' => 'victoriametrics', 'telemetry.metrics.query_url' => 'http://vm:8428']);
    [, $this->organization] = actingAsMember(Role::Admin);
    $make = fn (string $slug) => Site::query()->create([
        'organization_id' => $this->organization->id, 'name' => ucfirst($slug), 'slug' => $slug, 'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native, 'framework' => Framework::Laravel, 'php_version' => '8.4', 'web_directory' => 'public',
        'unix_user' => $slug, 'isolated' => true, 'deploy_script' => '$FALAK_FETCH', 'laravel' => new LaravelSettings,
    ]);
    $this->shop = $make('shop');
    $this->blog = $make('blog');
    $this->state = new ArrayObject;
    http_fake_backends($this->state);
});

afterEach(fn () => Carbon::setTestNow());

it('opens slow endpoint issues per site and route, and resolves them once fast again', function () {
    HttpSettings::query()->create(['site_id' => $this->blog->id, 'organization_id' => $this->organization->id, 'slow_ms' => 3000]);
    $this->state['quantile_over_time'] = [
        [['service_name' => 'shop', 'url_route' => '/orders/:id'], '1800'],
        [['service_name' => 'shop', 'url_route' => '/rare'], '9000'],
        [['service_name' => 'shop', 'url_route' => '/fast'], '40'],
        [['service_name' => 'blog', 'url_route' => '/feed'], '2000'], // under the blog's own 3 s
        [['service_name' => 'other-org-site', 'url_route' => '/x'], '9000'],
    ];
    $this->state['sum by (service_name, url_route)'] = [
        [['service_name' => 'shop', 'url_route' => '/orders/:id'], '120'],
        [['service_name' => 'shop', 'url_route' => '/rare'], '3'], // too few requests
        [['service_name' => 'shop', 'url_route' => '/fast'], '500'],
        [['service_name' => 'blog', 'url_route' => '/feed'], '300'],
    ];

    app(EvaluateHttpTraffic::class)($this->organization->id);

    $issues = Issue::query()->where('kind', IssueKind::Performance)->get();
    expect($issues)->toHaveCount(1);
    $issue = $issues->first();
    expect($issue->title)->toBe('Slow endpoint: /orders/:id')
        ->and($issue->site_id)->toBe($this->shop->id)
        ->and($issue->meta)->toMatchArray(['source' => 'edge', 'route' => '/orders/:id', 'p95_ms' => 1800, 'threshold_ms' => 1000, 'count' => 120])
        ->and(Alert::query()->where('type', 'http.slow_endpoint')->where('recovery', false)->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'loki') && $r['start'] === $r['end']
        && str_starts_with($r['query'], 'topk(1000, quantile_over_time(0.95, {falak_org_id="'.strtoupper($this->organization->id).'", falak_log_kind="access"} | user_agent_original!~"Falak-HealthCheck/.*" | url_route!=""') && str_ends_with($r['query'], 'by (service_name, url_route) >= 800)'));

    // Still slow: one more occurrence, no new alert.
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->occurrences)->toBe(2)->and(Alert::query()->where('type', 'http.slow_endpoint')->where('recovery', false)->count())->toBe(1);

    // Fast again: resolved automatically, the alert recovers.
    $this->state['quantile_over_time'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '300']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and(Alert::query()->where('type', 'http.slow_endpoint')->where('recovery', true)->count())->toBe(1);

    // Slow again later: the issue regresses.
    Carbon::setTestNow(now()->addHour());
    $this->state['quantile_over_time'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '2500']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open)->and($issue->regressions)->toBe(1);
});

it('keeps a slow endpoint issue open through quiet hours and the band under the threshold', function () {
    $this->state['quantile_over_time'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '1800']];
    $this->state['sum by (service_name, url_route)'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '120']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    $issue = Issue::query()->where('kind', IssueKind::Performance)->sole();
    $alert = fn () => Alert::query()->where('type', 'http.slow_endpoint')->where('recovery', true)->count();

    // A quiet hour: too few requests (or none) to tell, whatever the p95.
    $this->state['quantile_over_time'] = [];
    $this->state['sum by (service_name, url_route)'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '4']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    $this->state['sum by (service_name, url_route)'] = [];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open)->and($issue->occurrences)->toBe(1)->and($alert())->toBe(0);

    // Under the threshold but above 80% of it: neither slow nor recovered.
    $this->state['quantile_over_time'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '900']];
    $this->state['sum by (service_name, url_route)'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '120']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open)->and($issue->occurrences)->toBe(1);

    // A cut p95 answer (as many series as the limit): a counted route missing from it is unknown, not fast.
    config(['telemetry.http.max_slow_series' => 1]);
    $this->state['quantile_over_time'] = [[['service_name' => 'blog', 'url_route' => '/x'], '900']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open);

    // Busy and missing from a complete p95 answer (under the query's lowest bound): recovered.
    config(['telemetry.http.max_slow_series' => 1000]);
    $this->state['quantile_over_time'] = [];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)->and($alert())->toBe(1);
});

it('keeps evaluating when one of Loki\'s answers fails', function () {
    // The slow-endpoint p95 is refused (too many series): nothing about slow endpoints is decided...
    $this->state['quantile_over_time'] = 'fail';
    $this->state['sum by (service_name, url_route)'] = [[['service_name' => 'shop', 'url_route' => '/orders/:id'], '120']];
    // ...and the 404 query is refused too; the threats and the error spike are still evaluated.
    $this->state['http_response_status_code="404"'] = 'fail';
    $this->state['sum by (service_name, falak_threat_rule)'] = [[['service_name' => 'shop', 'falak_threat_rule' => 'git'], '25']];

    app(EvaluateHttpTraffic::class)($this->organization->id);

    expect(Issue::query()->where('kind', IssueKind::Performance)->count())->toBe(0)
        ->and(Issue::query()->where('kind', IssueKind::Security)->pluck('title')->all())->toBe(['Scanner probing for version control folders (/.git)']);
});

it('records scanners and 404 storms as security issues, from a minimum of hits', function () {
    $this->state['falak_threat_rule, client_address'] = [[['service_name' => 'shop', 'falak_threat_rule' => 'env', 'client_address' => '203.0.113.7'], '35']];
    $this->state['sum by (service_name, falak_threat_rule)'] = [
        [['service_name' => 'shop', 'falak_threat_rule' => 'env'], '40'],
        [['service_name' => 'shop', 'falak_threat_rule' => 'sqli'], '3'], // background noise
        [['service_name' => 'blog', 'falak_threat_rule' => 'unknown-rule'], '99'],
    ];
    $this->state['http_response_status_code="404"'] = [
        [['service_name' => 'blog', 'client_address' => '198.51.100.9'], '150'],
        [['service_name' => 'blog', 'client_address' => '198.51.100.10'], '20'],
    ];

    app(EvaluateHttpTraffic::class)($this->organization->id);

    $issues = Issue::query()->where('kind', IssueKind::Security)->orderBy('title')->get();
    expect($issues->pluck('title')->all())->toBe(['404 storm from single clients', 'Scanner probing for .env files']);
    $env = $issues->last();
    expect($env->site_id)->toBe($this->shop->id)
        ->and($env->occurrences)->toBe(40)
        ->and($env->meta)->toMatchArray(['source' => 'http', 'rule' => 'env'])
        ->and($env->sample['clients'])->toBe(['203.0.113.7' => 35])
        ->and($issues->first()->sample['clients'])->toBe(['198.51.100.9' => 150]);
    // 40 hits stay below the surge alert.
    expect(Alert::query()->where('type', 'http.scanner_surge')->count())->toBe(0);

    config(['telemetry.http.scanner_surge' => 40]);
    app(EvaluateHttpTraffic::class)($this->organization->id);
    // Both sites: the blog's hits of a rule without an Issue count towards the surge too.
    expect(Alert::query()->where('type', 'http.scanner_surge')->where('recovery', false)->pluck('title')->sort()->values()->all())
        ->toBe(['Blog: 99 probe and attack requests in 5 minutes', 'Shop: 43 probe and attack requests in 5 minutes'])
        ->and($env->refresh()->occurrences)->toBe(80);
});

it('alerts on a 5xx spike held over two windows', function () {
    $this->state['http_response_status_code >= 500'] = [[['service_name' => 'shop'], '60']];
    $this->state['sum by (service_name) (count_over_time'] = [[['service_name' => 'shop'], '200'], [['service_name' => 'blog'], '10']];

    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect(Alert::query()->where('type', 'http.errors_spike')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(5));
    app(EvaluateHttpTraffic::class)($this->organization->id);
    $alert = Alert::query()->where('type', 'http.errors_spike')->where('recovery', false)->sole();
    expect($alert->title)->toBe('Shop: 30% of requests fail with 5xx');

    // A few errors on a quiet site are no spike.
    $this->state['http_response_status_code >= 500'] = [[['service_name' => 'shop'], '5']];
    Carbon::setTestNow(now()->addMinutes(5));
    app(EvaluateHttpTraffic::class)($this->organization->id);
    expect(Alert::query()->where('type', 'http.errors_spike')->where('recovery', true)->count())->toBe(1);
});

it('records bandwidth per site and hour from the aggregates, else the access log, and alerts once a month', function () {
    $this->state['falak_net_http_io_bytes_total'] = [
        [['service_name' => 'shop', 'network_io_direction' => 'transmit'], '6000000'],
        [['service_name' => 'shop', 'network_io_direction' => 'receive'], '1000'],
    ];
    $this->state['falak_net_http_requests_total'] = [[['service_name' => 'shop'], '50.4']];
    $this->state['unwrap http_response_body_size'] = [[['service_name' => 'blog'], '2000'], [['service_name' => 'shop'], '1']];
    $this->state['count_over_time'] = [[['service_name' => 'blog'], '7']];
    HttpSettings::query()->create(['site_id' => $this->shop->id, 'organization_id' => $this->organization->id, 'bandwidth_alert_bytes' => 10_000_000]);

    app(RecordBandwidth::class)($this->organization->id);
    app(RecordBandwidth::class)($this->organization->id); // idempotent

    $rows = DB::table('telemetry_http_bandwidth')->orderBy('site_slug')->orderBy('hour')->get();
    expect($rows)->toHaveCount(4)
        ->and((int) $rows[2]->bytes_out)->toBe(6_000_000)->and((int) $rows[2]->bytes_in)->toBe(1000)->and((int) $rows[2]->requests)->toBe(50)
        ->and($rows[0]->site_slug)->toBe('blog')->and((int) $rows[0]->bytes_out)->toBe(2000)->and((int) $rows[0]->requests)->toBe(7);
    // 12 MB this month: over the 10 MB threshold, alerted once.
    expect(Alert::query()->where('type', 'http.bandwidth')->where('outcome', '!=', 'deduplicated')->count())->toBe(1);

    $months = RecordBandwidth::months($this->organization->id, 'shop');
    expect($months)->toBe([['month' => '2026-10', 'bytes_out' => 12_000_000, 'bytes_in' => 2000, 'requests' => 100]]);
});

it('runs every organization and skips the evaluation without Loki', function () {
    $this->state['quantile_over_time'] = [[['service_name' => 'shop', 'url_route' => '/a'], '5000']];
    $this->state['sum by (service_name, url_route)'] = [[['service_name' => 'shop', 'url_route' => '/a'], '50']];

    (new AnalyzeHttpTraffic('evaluate'))->handle(...array_map(app(...), [OrganizationDirectory::class, EvaluateHttpTraffic::class, RecordBandwidth::class]));
    expect(Issue::query()->where('kind', IssueKind::Performance)->count())->toBe(1);

    config(['telemetry.loki.url' => '']);
    Http::fake(fn () => throw new RuntimeException('no request expected'));
    dispatch_sync(new AnalyzeHttpTraffic('evaluate'));
});

it('counts a site on old and new agents from each edge\'s own source, never twice', function () {
    // Server A's agent sends counters; server B's predates them: only its access log has its traffic.
    $this->state['falak_net_http_io_bytes_total'] = [[['service_name' => 'shop', 'falak_server_id' => 'A', 'network_io_direction' => 'transmit'], '5000']];
    $this->state['falak_net_http_requests_total'] = [[['service_name' => 'shop', 'falak_server_id' => 'A'], '10']];
    $this->state['unwrap http_response_body_size'] = [
        [['service_name' => 'shop', 'falak_server_id' => 'A'], '5000'], // also in the log: the counters win
        [['service_name' => 'shop', 'falak_server_id' => 'B'], '700'],
    ];
    $this->state['count_over_time'] = [[['service_name' => 'shop', 'falak_server_id' => 'A'], '10'], [['service_name' => 'shop', 'falak_server_id' => 'B'], '3']];

    app(RecordBandwidth::class)($this->organization->id);

    expect(RecordBandwidth::months($this->organization->id, 'shop'))->toBe([['month' => '2026-10', 'bytes_out' => 11_400, 'bytes_in' => 0, 'requests' => 26]]);
});

it('forgets a deleted site\'s HTTP rows and old client IPs of scanner issues', function () {
    HttpSettings::query()->create(['site_id' => $this->shop->id, 'organization_id' => $this->organization->id, 'slow_ms' => 500]);
    DB::table('telemetry_http_bandwidth')->insert([
        ['organization_id' => $this->organization->id, 'site_slug' => 'shop', 'hour' => now()->startOfHour(), 'bytes_out' => 1, 'bytes_in' => 0, 'requests' => 1],
        ['organization_id' => $this->organization->id, 'site_slug' => 'blog', 'hour' => now()->startOfHour(), 'bytes_out' => 1, 'bytes_in' => 0, 'requests' => 1],
    ]);

    event(new SiteDeleted($this->shop->id, $this->organization->id, 'shop', []));

    expect(HttpSettings::query()->count())->toBe(0)
        ->and(DB::table('telemetry_http_bandwidth')->pluck('site_slug')->all())->toBe(['blog']);

    // Scanner issues keep the client IPs of their latest window, and none once quiet for the retention.
    $this->state['sum by (service_name, falak_threat_rule)'] = [[['service_name' => 'blog', 'falak_threat_rule' => 'env'], '30']];
    $this->state['falak_threat_rule, client_address'] = [[['service_name' => 'blog', 'falak_threat_rule' => 'env', 'client_address' => '203.0.113.7'], '30']];
    app(EvaluateHttpTraffic::class)($this->organization->id);
    $issue = Issue::query()->where('kind', IssueKind::Security)->sole();
    expect($issue->sample['clients'])->toBe(['203.0.113.7' => 30]);

    Carbon::setTestNow(now()->addDays(20));
    dispatch_sync(new PruneInsightsJob);
    expect($issue->refresh()->sample)->not->toBeNull();

    Carbon::setTestNow(now()->addDays(11));
    dispatch_sync(new PruneInsightsJob);
    expect($issue->refresh()->sample)->toBeNull();
});
