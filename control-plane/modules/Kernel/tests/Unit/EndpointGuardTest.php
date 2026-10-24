<?php

use Falak\Kernel\Network\EndpointGuard;
use Falak\Kernel\Network\EndpointRefused;

it('checks IPv4 addresses embedded in IPv6 ones, and refuses site-local IPv6', function () {
    $guard = new EndpointGuard(fn (string $host) => match ($host) {
        'mapped-hex.example.com' => ['::ffff:a9fe:a9fe'],
        'compat.example.com' => ['::169.254.169.254'],
        'nat64.example.com' => ['64:ff9b::a9fe:a9fe'],
        '6to4.example.com' => ['2002:a9fe:a9fe::1'],
        '6to4-private.example.com' => ['2002:c0a8:0101::1'],
        'site-local.example.com' => ['fec0::1'],
        default => [],
    });

    foreach (['mapped-hex', 'compat', 'nat64', '6to4', 'site-local'] as $host) {
        expect(fn () => $guard->check("https://{$host}.example.com", true))->toThrow(EndpointRefused::class, 'never allowed');
    }

    expect(fn () => $guard->check('https://6to4-private.example.com', false))->toThrow(EndpointRefused::class, 'private or reserved')
        ->and($guard->check('https://6to4-private.example.com', true))->toBe(['2002:c0a8:0101::1'])
        ->and(fn () => $guard->check('https://[::ffff:127.0.0.1]', false))->toThrow(EndpointRefused::class, 'private or reserved')
        ->and(fn () => $guard->check('https://[::ffff:7f00:1]', false))->toThrow(EndpointRefused::class, 'private or reserved')
        ->and(fn () => $guard->check('https://[::1]', false))->toThrow(EndpointRefused::class, 'private or reserved')
        ->and(fn () => $guard->check('https://10.1.2.3', false))->toThrow(EndpointRefused::class, 'private or reserved')
        ->and($guard->check('https://[::1]', true))->toBe(['::1']);
});

it('refuses numeric hosts that HTTP clients read as addresses', function (string $host) {
    $guard = new EndpointGuard(fn () => ['93.184.216.34']);

    expect(fn () => $guard->check("https://{$host}", true))->toThrow(EndpointRefused::class, 'not a canonical address');
})->with(['2130706433', '0x7f.1', '0177.0.0.1', '127.1']);

it('keeps requests off metadata, link-local and (unless allowed) private addresses', function () {
    $guard = new EndpointGuard(fn (string $host) => match ($host) {
        'vault.example.com' => ['93.184.216.34'],
        'internal.example.com' => ['10.0.0.5'],
        'rebind.example.com' => ['93.184.216.34', '169.254.169.254'],
        'mapped.example.com' => ['::ffff:169.254.169.254'],
        'ipv6-metadata.example.com' => ['fd00:ec2::254'],
        default => [],
    });

    expect($guard->check('https://vault.example.com:8200', false))->toBe(['93.184.216.34'])
        ->and($guard->check('https://internal.example.com', true))->toBe(['10.0.0.5'])
        ->and($guard->check('https://127.0.0.1:8200', true))->toBe(['127.0.0.1']);

    foreach ([
        ['http://vault.example.com', true, 'must use https'],
        ['https://internal.example.com', false, 'private or reserved'],
        ['https://127.0.0.1', false, 'private or reserved'],
        ['https://169.254.169.254/latest', true, 'never allowed'],
        ['https://rebind.example.com', true, 'never allowed'],
        ['https://mapped.example.com', true, 'never allowed'],
        ['https://ipv6-metadata.example.com', true, 'never allowed'],
        ['https://[fe80::1]', true, 'never allowed'],
        ['https://100.100.100.200', true, 'never allowed'],
        ['https://nowhere.example.com', true, 'does not resolve'],
    ] as [$url, $allowPrivate, $message]) {
        expect(fn () => $guard->check($url, $allowPrivate))->toThrow(EndpointRefused::class, $message);
    }
});

it('answers refusal() with the reason, or null when allowed', function () {
    $guard = new EndpointGuard(fn (string $host) => ['10.0.0.5']);

    expect($guard->refusal('https://minio.lan', false))->toBe('The host minio.lan resolves to 10.0.0.5, a private or reserved address (allowed only where private networks are enabled).')
        ->and($guard->refusal('https://minio.lan', true))->toBeNull();
});

it('takes other schemes only when the caller allows them', function () {
    $guard = new EndpointGuard(fn () => ['93.184.216.34']);

    expect(fn () => $guard->check('http://hooks.example.com', false))->toThrow(EndpointRefused::class, 'https://')
        ->and($guard->check('http://hooks.example.com', false, ['http', 'https']))->toBe(['93.184.216.34'])
        ->and(fn () => $guard->check('ftp://hooks.example.com', false, ['http', 'https']))->toThrow(EndpointRefused::class, 'http:// or https://');
});

it('pins a request to the checked address, whatever host curl derives', function () {
    expect(EndpointGuard::pin('https://hooks.example.com/x', '93.184.216.34'))->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']])
        ->and(EndpointGuard::pin('http://hooks.example.com:8080/x', '2001:db8::1'))->toBe([CURLOPT_CONNECT_TO => ['::[2001:db8::1]:8080']])
        ->and(EndpointGuard::pin('http://hooks.example.com/x', '93.184.216.34'))->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:80']]);
});
