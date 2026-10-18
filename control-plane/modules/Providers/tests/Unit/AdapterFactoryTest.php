<?php

use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Infrastructure\AdapterFactory;
use Falak\Providers\Infrastructure\Adapters\ContaboAdapter;
use Falak\Providers\Infrastructure\Adapters\CustomAdapter;
use Falak\Providers\Infrastructure\Adapters\DigitalOceanAdapter;
use Falak\Providers\Infrastructure\Adapters\HetznerAdapter;
use Falak\Providers\Infrastructure\Adapters\LightsailAdapter;
use Falak\Providers\Infrastructure\Adapters\LinodeAdapter;
use Falak\Providers\Infrastructure\Adapters\VultrAdapter;
use Illuminate\Support\Facades\Http;

it('builds the adapter for each provider type', function (ProviderType $type, array $credentials, string $class) {
    $adapter = app(AdapterFactory::class)->make($type, $credentials);

    expect($adapter)->toBeInstanceOf($class)
        ->and($adapter->type())->toBe($type);
})->with([
    [ProviderType::Hetzner, ['token' => 't'], HetznerAdapter::class],
    [ProviderType::DigitalOcean, ['token' => 't'], DigitalOceanAdapter::class],
    [ProviderType::Vultr, ['api_key' => 'k'], VultrAdapter::class],
    [ProviderType::Linode, ['token' => 't'], LinodeAdapter::class],
    [ProviderType::Aws, ['access_key_id' => 'a', 'secret_access_key' => 's', 'region' => 'us-east-1'], LightsailAdapter::class],
    [ProviderType::Contabo, ['api_details' => 'ClientId: INT-1 Client Secret: s API User: me@example.com API Password: p'], ContaboAdapter::class],
    [ProviderType::Custom, [], CustomAdapter::class],
]);

it('rejects missing credential fields', function () {
    app(AdapterFactory::class)->make(ProviderType::Aws, ['access_key_id' => 'a']);
})->throws(ProviderException::class, 'Missing credential field [secret_access_key]');

it('uses configured endpoints', function () {
    config(['providers.endpoints.hetzner' => 'https://hetzner.mock/v1']);
    Http::fake(['hetzner.mock/*' => Http::response(['locations' => [], 'meta' => ['pagination' => ['next_page' => null]]])]);

    app(AdapterFactory::class)->make(ProviderType::Hetzner, ['token' => 't'])->regions();

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://hetzner.mock/v1/locations'));
});

it('lets demo credentials pin an unroutable .invalid endpoint, and ignores any other override', function () {
    Http::fake(['*' => Http::response(['locations' => [], 'meta' => ['pagination' => ['next_page' => null]]])]);

    app(AdapterFactory::class)->make(ProviderType::Hetzner, ['token' => 't', 'endpoint' => 'https://api.hetzner.invalid/v1'])->regions();
    app(AdapterFactory::class)->make(ProviderType::Hetzner, ['token' => 't', 'endpoint' => 'https://attacker.example/v1'])->regions();

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.hetzner.invalid/v1/locations'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'attacker.example'));
});

it('custom adapter has no catalog and refuses API operations', function () {
    $adapter = new CustomAdapter;
    $adapter->verify();

    expect($adapter->regions())->toBe([])
        ->and($adapter->sizes())->toBe([])
        ->and($adapter->images())->toBe([])
        ->and($adapter->getServer('x'))->toBeNull()
        ->and(fn () => $adapter->createServer(new MachineSpec('a', 'r', 's', 'i')))->toThrow(ProviderException::class, 'install command')
        ->and(fn () => $adapter->uploadSshKey('a', 'ssh-ed25519 AAAA'))->toThrow(ProviderException::class, 'install command');
});
