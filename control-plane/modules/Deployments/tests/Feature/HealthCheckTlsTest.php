<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Health\SiteHealthProbe;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Edge\Contracts\TlsMode;
use Falak\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Deploys through every phase and returns the (single) health check request with its client options.
 *
 * @return array{url: string, options: array<string, mixed>}
 */
function health_request(DeployWorld $world): array
{
    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $requests = $GLOBALS['deploy_http_requests'];
    expect($requests)->toHaveCount(1);

    return $requests[0];
}

it('verifies ACME certificates on the primary domain, resolved to the server', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];

    $r = health_request($world);

    expect($r['url'])->toStartWith('https://shop.example.com/')
        ->and($r['options']['verify'])->toBeTrue()
        ->and($r['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:203.0.113.1']);
});

it('does not verify internal-CA or uploaded certificates', function (TlsMode $mode) {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.internal'];
    $world->edge->domainTls['shop.internal'] = $mode;

    expect(health_request($world)['options']['verify'])->toBeFalse();
})->with([TlsMode::Internal, TlsMode::Custom]);

it('uses plain HTTP on port 80 when TLS is off', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['intranet.lan'];
    $world->edge->domainTls['intranet.lan'] = TlsMode::Off;

    $r = health_request($world);

    expect($r['url'])->toStartWith('http://intranet.lan/')
        ->and($r['options']['curl'][CURLOPT_RESOLVE])->toBe(['intranet.lan:80:203.0.113.1']);
});

it('follows the configured TLS of hosted test domains', function () {
    $world = deploy_world(site: ['test_domain_enabled' => true]);
    $world->edge->testDomainTlsMode = TlsMode::Internal;
    config(['sites.test_domain' => 'sites.falak.test']);

    $r = health_request($world);

    expect($r['url'])->toContain('.sites.falak.test/')->and($r['options']['verify'])->toBeFalse();
});

/**
 * Deploys through every phase; returns every health check request and the deployment's final status.
 *
 * @return array{0: list<array{url: string, options: array<string, mixed>}>, 1: string}
 */
function health_requests(DeployWorld $world): array
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return [$GLOBALS['deploy_http_requests'], $deployment->refresh()->status->value];
}

it('falls back to the next domain when the primary gets no HTTP answer on the server (per-server DNS)', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['api.18-194-183-232.sslip.io', 'api.63-182-218-247.sslip.io'];
    $GLOBALS['deploy_http'] = ['https://api.18-194-183-232.sslip.io/' => 0];

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://api.18-194-183-232.sslip.io/up', 'https://api.63-182-218-247.sslip.io/up'])
        ->and($requests[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['api.63-182-218-247.sslip.io:443:203.0.113.1'])
        ->and($status)->toBe('succeeded');
});

it('never skips over an application error on the primary domain', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com', 'www.shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 500];
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_retries' => 1])->save();

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://shop.example.com/up'])->and($status)->toBe('failed');
});

it('checks load-balancer backends over plain HTTP when HTTPS gets no answer', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 0];

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://shop.example.com/up', 'http://shop.example.com/up'])
        ->and($requests[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:80:203.0.113.1'])
        ->and($status)->toBe('succeeded');
});

it('fails with every attempt in the message when nothing answers', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 0, 'http://shop.example.com/' => 0];
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_retries' => 1])->save();

    [, $status] = health_requests($world);

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($status)->toBe('failed')
        ->and($deployment->error)->toContain('https://shop.example.com/')->toContain('http://shop.example.com/');
});

it('tries the private address when nothing answers on the public one (web ports closed to the internet)', function () {
    $world = deploy_world();
    $world->servers[0]->forceFill(['private_ipv4' => '10.0.0.5'])->save();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['via 203.0.113.1' => 0];

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://shop.example.com/up', 'http://shop.example.com/up', 'https://shop.example.com/up'])
        ->and($requests[2]['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:10.0.0.5'])
        ->and($status)->toBe('succeeded');

    [$healthy, $message] = app(SiteHealthProbe::class)->probe($world->site->id, $world->servers[0]->id, ['path' => '/up']);

    expect($healthy)->toBeTrue()
        ->and($message)->toStartWith('GET https://shop.example.com/up via 10.0.0.5 (private) → 200')
        ->and($message)->toContain('via 203.0.113.1 failed');
});

it('never tries the private address after an answer on the public one', function () {
    $world = deploy_world();
    $world->servers[0]->forceFill(['private_ipv4' => '10.0.0.5'])->save();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['via 203.0.113.1' => 500];

    [$healthy, $message] = app(SiteHealthProbe::class)->probe($world->site->id, $world->servers[0]->id, ['path' => '/up']);

    expect($healthy)->toBeFalse()
        ->and(array_column($GLOBALS['deploy_http_requests'], 'url'))->toBe(['https://shop.example.com/up'])
        ->and($message)->not->toContain('10.0.0.5');
});

it('fails on an application error on the private address', function () {
    $world = deploy_world();
    $world->servers[0]->forceFill(['private_ipv4' => '10.0.0.5'])->save();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['via 203.0.113.1' => 0, 'via 10.0.0.5' => 500];

    [$healthy, $message] = app(SiteHealthProbe::class)->probe($world->site->id, $world->servers[0]->id, ['path' => '/up']);

    expect($healthy)->toBeFalse()
        ->and($message)->toStartWith('GET https://shop.example.com/up via 10.0.0.5 (private) → 500');
});

it('checks a server without a public address on its private one', function () {
    $world = deploy_world();
    $world->servers[0]->forceFill(['ipv4' => null, 'private_ipv4' => '10.0.0.5'])->save();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];

    [$healthy, $message] = app(SiteHealthProbe::class)->probe($world->site->id, $world->servers[0]->id, ['path' => '/up']);

    expect($healthy)->toBeTrue()
        ->and($GLOBALS['deploy_http_requests'][0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:10.0.0.5'])
        ->and($message)->toContain('via 10.0.0.5 (private)');
});

it('never falls back to the private address in managed mode (a custom server reports it)', function () {
    config(['controlplane.managed' => true]);
    $world = deploy_world();
    $world->servers[0]->forceFill(['private_ipv4' => '10.0.0.5'])->save();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['via 203.0.113.1' => 0];

    [$healthy, $message] = app(SiteHealthProbe::class)->probe($world->site->id, $world->servers[0]->id, ['path' => '/up']);

    expect($healthy)->toBeFalse()
        ->and($message)->not->toContain('10.0.0.5');
});
