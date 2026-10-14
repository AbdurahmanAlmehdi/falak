<?php

use Falak\Fleet\Application\PayloadCompatibility;

/*
 * Optional protocol fields are removed for agents that don't report the feature that introduced them.
 */

function compat_swap(): object
{
    return json_decode(json_encode([
        'site' => 'shop-api',
        'networks' => [
            ['name' => 'shop_default', 'aliases' => ['api'], 'compose' => ['project' => 'shop', 'network' => 'default']],
            ['name' => 'shared', 'aliases' => ['api']],
        ],
    ]));
}

it('keeps a compose network for agents that create them', function () {
    $out = PayloadCompatibility::adapt('deploy.container.swap', compat_swap(), ['docker.networks', 'docker.networks.create']);

    expect($out->networks[0]->compose->project)->toBe('shop');
});

it('strips the compose marker for agents that only join existing networks (they keep waiting for it)', function () {
    $out = PayloadCompatibility::adapt('deploy.container.swap', compat_swap(), ['docker.networks']);

    expect(property_exists($out->networks[0], 'compose'))->toBeFalse()
        ->and($out->networks[0]->name)->toBe('shop_default')
        ->and($out->networks[0]->aliases)->toBe(['api']);
});

it('strips the networks for agents that join none', function () {
    expect(property_exists(PayloadCompatibility::adapt('docker.run', compat_swap(), []), 'networks'))->toBeFalse();
});

it('strips machine-check decisions from provision.apply for agents without provision.v2', function () {
    $plan = fn () => json_decode(json_encode(['hostname' => 'app-1', 'components' => [['name' => 'docker', 'decision' => 'adopt', 'packages' => ['docker-ce']]]]));

    expect(property_exists(PayloadCompatibility::adapt('provision.apply', $plan(), ['compose.up.services']), 'components'))->toBeFalse()
        ->and(PayloadCompatibility::adapt('provision.apply', $plan(), ['provision.v2'])->components[0]->decision)->toBe('adopt')
        ->and(PayloadCompatibility::adapt('provision.apply', $plan(), [])->hostname)->toBe('app-1');
});

it('keeps a site\'s unix_user for agents without telemetry.workloads.shared only where the site is isolated', function () {
    $configure = fn () => json_decode(json_encode(['sites' => [
        ['slug' => 'iso', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'iso', 'isolated' => true],
        ['slug' => 'blog', 'site_id' => '01JSQTE000000000000000000B', 'unix_user' => 'falak'],
        ['slug' => 'odd', 'site_id' => '01JSQTE000000000000000000C', 'unix_user' => 'odd', 'isolated' => false],
    ]]));

    $old = PayloadCompatibility::adapt('telemetry.configure', $configure(), ['telemetry.workloads']);
    $new = PayloadCompatibility::adapt('telemetry.configure', $configure(), ['telemetry.workloads', 'telemetry.workloads.shared']);

    expect(json_decode(json_encode($old), true)['sites'])->toBe([
        ['slug' => 'iso', 'site_id' => '01JSQTE000000000000000000A', 'unix_user' => 'iso'],
        ['slug' => 'blog', 'site_id' => '01JSQTE000000000000000000B'],
        ['slug' => 'odd', 'site_id' => '01JSQTE000000000000000000C'],
    ])
        ->and($new->sites[1]->unix_user)->toBe('falak')
        ->and($new->sites[0]->isolated)->toBeTrue()
        ->and(property_exists($new->sites[2], 'isolated'))->toBeTrue();
});

it('drops container log sources whole for agents without logs.container_files and strips the other new fields', function () {
    $configure = fn () => json_decode(json_encode(['log_sources' => [
        ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'site' => 'shop', 'multiline' => 'laravel'],
        ['path' => '/var/www/html/storage/logs/*.log', 'site' => 'stack', 'container' => true, 'compose_service' => 'app'],
        ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'site' => 'shop', 'origin' => 'php_fpm_slow', 'multiline_pattern' => '^\['],
    ]]));

    // Without it, the container source's path would be tailed on the host: it is not sent at all.
    expect(json_decode(json_encode(PayloadCompatibility::adapt('telemetry.configure', $configure(), ['telemetry.log_kind'])), true)['log_sources'])->toBe([
        ['path' => '/srv/falak/sites/shop/shared/storage/logs/*.log', 'site' => 'shop', 'multiline' => 'laravel'],
        ['path' => '/var/log/falak/php-fpm-slow/shop.log', 'site' => 'shop'],
    ])
        ->and(json_decode(json_encode(PayloadCompatibility::adapt('telemetry.configure', $configure(), ['telemetry.log_kind', 'logs.container_files'])), true)['log_sources'])
        ->toBe(json_decode(json_encode($configure()), true)['log_sources'])
        ->and(PayloadCompatibility::adapt('telemetry.configure', json_decode('{"sites":[]}'), []))->toEqual(json_decode('{"sites":[]}'));
});

it('strips the pool\'s slow log for agents without runtime.fpm.slowlog', function () {
    $pool = fn () => json_decode(json_encode(['php_version' => '8.4', 'pool' => 'shop', 'user' => 'shop', 'slowlog_timeout' => 5]));

    expect(property_exists(PayloadCompatibility::adapt('runtime.fpm.pool', $pool(), []), 'slowlog_timeout'))->toBeFalse()
        ->and(PayloadCompatibility::adapt('runtime.fpm.pool', $pool(), ['runtime.fpm.slowlog'])->slowlog_timeout)->toBe(5);
});
