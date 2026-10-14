<?php

use Carbon\CarbonImmutable;
use Falak\Telemetry\Application\Queries\LogQueryBuilder;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\LogsQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['telemetry.loki.url' => 'http://loki:3100', 'telemetry.loki.tenant' => null]);
});

it('queries Loki with nanosecond bounds and merges streams newest first', function () {
    Http::fake(['loki:3100/loki/api/v1/query_range*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['service_name' => 'shop', 'falak_org_id' => 'ORG'], 'values' => [
                ['1700000000000000003', 'third', ['trace_id' => 'abc123', 'severity_text' => 'ERROR']],
                ['1700000000000000001', 'first'],
            ]],
            ['stream' => ['service_name' => 'falak-agent'], 'values' => [
                ['1700000000000000002', 'second', ['structuredMetadata' => ['span_id' => 'ff'], 'parsed' => ['level' => 'info']]],
            ]],
        ]],
    ])]);

    $lines = app(LogsQuery::class)->queryRange('{falak_org_id="ORG"}', CarbonImmutable::createFromTimestamp(1_700_000_000), CarbonImmutable::createFromTimestamp(1_700_000_060), 2);

    expect(array_map(fn ($l) => $l->line, $lines))->toBe(['third', 'second'])
        ->and($lines[0]->metadata)->toBe(['trace_id' => 'abc123', 'severity_text' => 'ERROR'])
        ->and($lines[0]->traceId())->toBe('abc123')
        ->and($lines[0]->labels['service_name'])->toBe('shop')
        ->and($lines[1]->metadata)->toBe(['span_id' => 'ff', 'level' => 'info'])
        ->and($lines[0]->toArray()['at'])->toStartWith('2023-11-14T22:13:20.000000');

    Http::assertSent(fn (Request $request) => $request['query'] === '{falak_org_id="ORG"}'
        && $request['start'] === '1700000000000000000'
        && $request['end'] === '1700000060000000000'
        && (int) $request['limit'] === 2
        && $request['direction'] === 'backward');
});

it('orders forward queries oldest first and sends the tenant header', function () {
    config(['telemetry.loki.tenant' => 'falak']);
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => [], 'values' => [['1700000000000000009', 'b'], ['999999999000000000', 'a']]],
    ]]])]);

    $lines = app(LogsQuery::class)->queryRange('{a="b"}', now()->subHour(), now(), 10, 'forward');

    expect(array_map(fn ($l) => $l->line, $lines))->toBe(['a', 'b']);
    Http::assertSent(fn (Request $request) => $request->header('X-Scope-OrgID') === ['falak'] && $request['direction'] === 'forward');
});

it('surfaces Loki errors', function () {
    Http::fake(['*' => Http::sequence()
        ->push('parse error at line 1, col 2: syntax error', 400)
        ->push(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]])]);

    expect(fn () => app(LogsQuery::class)->queryRange('{', now()->subHour(), now()))->toThrow(TelemetryQueryFailed::class, 'syntax error')
        ->and(fn () => app(LogsQuery::class)->queryRange('rate({a="b"}[1m])', now()->subHour(), now()))->toThrow(TelemetryQueryFailed::class, 'stream');

    config(['telemetry.loki.url' => null]);
    expect(fn () => app(LogsQuery::class)->queryRange('{a="b"}', now()->subHour(), now()))->toThrow(TelemetryUnavailable::class);
});

it('always scopes LogQL to the organization and escapes user input', function () {
    $query = LogQueryBuilder::build('01jorg0000000000000000000a', [
        'server_id' => '01jsrv0000000000000000000b',
        'service' => 'shop"} or {falak_org_id=~".+',
        'search' => 'say "hi"\\',
        'trace_id' => 'ABCDEF0123456789',
        'level' => 'error',
    ]);

    expect($query)->toStartWith('{falak_org_id="01JORG0000000000000000000A", falak_server_id="01JSRV0000000000000000000B", service_name="shop\"} or {falak_org_id=~\".+"}')
        ->toContain(' |= "say \"hi\"\\\\"')
        ->toContain(' | trace_id="abcdef0123456789"')
        ->toContain('severity_text=~"(?i)error.*" or detected_level=~"(?i)error.*"');

    expect(LogQueryBuilder::build('org', ['search' => 'a.*b', 'regex' => true]))->toBe('{falak_org_id="ORG"} |~ "a.*b"')
        ->and(fn () => LogQueryBuilder::build('org', ['search' => '(unclosed', 'regex' => true]))->toThrow(InvalidArgumentException::class);
});

it('filters compose container logs by compose service (structured metadata)', function () {
    expect(LogQueryBuilder::build('org', ['site_id' => 'site', 'compose_service' => 'redis', 'search' => 'ready']))
        ->toBe('{falak_org_id="ORG", falak_site_id="SITE"} | falak_compose_service="redis" |= "ready"');
});

it('selects a site log kind and builds access log queries by slug', function () {
    expect(LogQueryBuilder::build('org', ['site_id' => 'abc', 'kind' => 'app']))->toBe('{falak_org_id="ORG", falak_site_id="ABC", falak_log_kind!="access"}')
        ->and(LogQueryBuilder::build('org', ['site_id' => 'abc', 'kind' => 'access']))->toBe('{falak_org_id="ORG", falak_site_id="ABC", falak_log_kind="access"}')
        ->and(LogQueryBuilder::access('org', 'shop', ['server_id' => 'srv', 'status' => 404, 'client_ip' => '203.0.113.9']))
        ->toBe('{falak_org_id="ORG", service_name="shop", falak_log_kind="access", falak_server_id="SRV"} | http_response_status_code="404" | client_address="203.0.113.9"');

    LogQueryBuilder::access('org', 'shop', ['status' => '6xx']);
})->throws(InvalidArgumentException::class);

it('filters a site\'s records by where they come from, with structured metadata only', function () {
    $base = '{falak_org_id="ORG", falak_site_id="SITE", falak_log_kind!="access"}';
    expect(LogQueryBuilder::build('org', ['site_id' => 'site', 'kind' => 'app', 'origin' => 'output']))->toBe($base.' | log_iostream=~"stdout|stderr"')
        ->and(LogQueryBuilder::build('org', ['site_id' => 'site', 'kind' => 'app', 'origin' => 'file']))->toBe($base.' | log_file_path!="" | falak_log_origin=~"file|"')
        ->and(LogQueryBuilder::build('org', ['site_id' => 'site', 'kind' => 'app', 'origin' => 'container_file', 'compose_service' => 'app']))
        ->toBe($base.' | falak_compose_service="app" | falak_log_origin="container_file"')
        ->and(LogQueryBuilder::build('org', ['site_id' => 'site', 'kind' => 'app', 'origin' => 'php_fpm_slow', 'level' => 'warn']))
        ->toStartWith($base.' | falak_log_origin="php_fpm_slow" | severity_text')
        ->and(fn () => LogQueryBuilder::build('org', ['origin' => 'journal']))->toThrow(InvalidArgumentException::class)
        // Without a kind, an origin still leaves the edge's access records out (they are log files too).
        ->and(LogQueryBuilder::build('org', ['site_id' => 'site', 'origin' => 'file']))->toBe($base.' | log_file_path!="" | falak_log_origin=~"file|"')
        ->and(fn () => LogQueryBuilder::build('org', ['kind' => 'access', 'origin' => 'file']))->toThrow(InvalidArgumentException::class);
});
