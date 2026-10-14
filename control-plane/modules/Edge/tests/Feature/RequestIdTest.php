<?php

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

it('asks every edge to stamp X-Request-Id, and strips the flag for agents without the feature', function () {
    ['sites' => $sites, 'servers' => $servers] = edge_fakes();
    $org = (string) Str::ulid();
    $server = edge_server($servers, $org);
    $site = edge_site($sites, $org, [$server->id], ['runtime' => SiteRuntime::Docker, 'appPort' => 8080]);
    Domain::query()->create(['organization_id' => $org, 'site_id' => $site->id, 'name' => 'api.example.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    $payload = edge_compile($server->id);
    expect($payload['request_id'])->toBeTrue();

    // An agent without edge.request_id gets the payload of before the feature (and so renders the same Caddy config).
    $adapt = fn (array $features) => (array) PayloadCompatibility::adapt('edge.caddy.apply', json_decode((string) json_encode($payload)), $features);
    expect($adapt(['edge.access_log']))->not->toHaveKey('request_id')
        ->and($adapt(['edge.access_log', 'edge.request_id']))->toHaveKey('request_id');

    config(['edge.request_id' => false]);
    expect(app(EdgeRoutes::class)->compile($server->id))->not->toHaveKey('request_id');
});
