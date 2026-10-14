<?php

use Falak\Edge\Application\Listeners\ReapplyOnRequestIdLinking;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Telemetry\Events\RequestIdLinkingChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

it('stamps X-Request-Id only for organizations that turned request ID linking on, and only on agents with the feature', function () {
    ['sites' => $sites, 'servers' => $servers] = edge_fakes();
    $org = strtolower((string) Str::ulid());
    $server = edge_server($servers, $org);
    $site = edge_site($sites, $org, [$server->id], ['runtime' => SiteRuntime::Docker, 'appPort' => 8080]);
    Domain::query()->create(['organization_id' => $org, 'site_id' => $site->id, 'name' => 'api.example.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    // Off by default: the payload (and so the rendered Caddy config) is what it was before the feature.
    expect(edge_compile($server->id))->not->toHaveKey('request_id');

    DB::table('telemetry_settings')->insert(['organization_id' => $org, 'request_id_linking' => true]);
    $payload = edge_compile($server->id);
    expect($payload['request_id'])->toBeTrue();

    $adapt = fn (array $features) => (array) PayloadCompatibility::adapt('edge.caddy.apply', json_decode((string) json_encode($payload)), $features);
    expect($adapt(['edge.access_log']))->not->toHaveKey('request_id')
        ->and($adapt(['edge.access_log', 'edge.request_id']))->toHaveKey('request_id');

    // Turning it off or on re-applies the organization's edges.
    $routes = Mockery::mock(EdgeRoutes::class);
    $routes->shouldReceive('apply')->once()->with($server->id);
    (new ReapplyOnRequestIdLinking($routes, $servers))->handle(new RequestIdLinkingChanged($org, false));
});
