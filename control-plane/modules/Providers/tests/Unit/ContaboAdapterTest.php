<?php

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Infrastructure\Adapters\ContaboAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../fixtures.php';

const CONTABO_AUTH = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token';

beforeEach(fn () => Http::preventStrayRequests());

function contabo(): ContaboAdapter
{
    return new ContaboAdapter(
        ['client_id' => 'INT-123', 'client_secret' => 'csecret', 'user' => 'me@example.com', 'password' => 'p@ss w0rd'],
        'https://api.contabo.com/v1',
        CONTABO_AUTH,
        PROVIDERS_TEST_HTTP,
    );
}

/** @param  array<string, mixed>  $api */
function fakeContabo(array $api): void
{
    Http::fake([CONTABO_AUTH => Http::response(['access_token' => 'tok', 'expires_in' => 300]), ...$api]);
}

function contaboInstance(array $overrides = []): array
{
    return array_replace_recursive([
        'instanceId' => 2001,
        'displayName' => 'web-1',
        'status' => 'installing',
        'region' => 'EU',
        'ipConfig' => ['v4' => ['ip' => '203.0.113.20'], 'v6' => ['ip' => '2001:db8::20']],
        'cancelDate' => '',
    ], $overrides);
}

it('reads the four values from everything a user is likely to paste', function (string $pasted) {
    expect(ContaboAdapter::parseCredentials($pasted))->toBe([
        'client_id' => 'INT-12345678',
        'client_secret' => 'Sec-ret_1',
        'user' => 'me@example.com',
        'password' => 'My Pass!1',
    ]);
})->with([
    'cntb command' => ["cntb config set-credentials --oauth2-clientid=INT-12345678 --oauth2-client-secret=Sec-ret_1 --oauth2-user=me@example.com --oauth2-password='My Pass!1'"], // gitleaks:allow (fake test values)
    'cntb command, space separated' => ['cntb config set-credentials --oauth2-clientid INT-12345678 --oauth2-client-secret Sec-ret_1 --oauth2-user me@example.com --oauth2-password "My Pass!1"'],
    'panel labels' => ["ClientId: INT-12345678\nClient Secret: Sec-ret_1\nAPI User: me@example.com\nAPI Password: 'My Pass!1'"],
    'panel labels, unquoted password with spaces' => ["ClientId: INT-12345678\nClient Secret: Sec-ret_1\nAPI User: me@example.com\nAPI Password: My Pass!1\n"],
    'panel labels on one line' => ["ClientId INT-12345678 Client Secret Sec-ret_1 API User me@example.com API Password 'My Pass!1'"],
    'cntb yaml' => ["oauth2-clientid: INT-12345678\noauth2-client-secret: Sec-ret_1\noauth2-user: me@example.com\noauth2-password: 'My Pass!1'\noauth2-tokenurl: https://auth.contabo.com/x"], // gitleaks:allow (fake test values)
    'shell variables' => ["CLIENT_ID=INT-12345678\nCLIENT_SECRET=Sec-ret_1\nAPI_USER=me@example.com\nAPI_PASSWORD='My Pass!1'"],
    'json' => ['{"client_id":"INT-12345678","client_secret":"Sec-ret_1","api_user":"me@example.com","api_password":"My Pass!1"}'],
]);

it('names what is missing, and treats doc placeholders as missing', function () {
    expect(fn () => ContaboAdapter::parseCredentials("ClientId: INT-1\nClient Secret: s\nAPI User: me@example.com"))
        ->toThrow(ProviderException::class, 'Missing API Password.')
        ->and(fn () => ContaboAdapter::parseCredentials("CLIENT_ID=<ClientId from Customer Control Panel>\nCLIENT_SECRET=s\nAPI_USER=\$API_USER\nAPI_PASSWORD=p"))
        ->toThrow(ProviderException::class, 'Missing Client ID, API User.');
});

it('logs in with the password grant and sends the token and a request id', function () {
    fakeContabo(['api.contabo.com/v1/compute/instances*' => Http::response(['data' => [], '_pagination' => ['totalPages' => 1]])]);

    contabo()->verify();

    Http::assertSent(fn (Request $r) => $r->url() === CONTABO_AUTH
        && $r['grant_type'] === 'password' && $r['client_id'] === 'INT-123' && $r['username'] === 'me@example.com' && $r['password'] === 'p@ss w0rd');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.contabo.com/v1/compute/instances')
        && $r->hasHeader('Authorization', 'Bearer tok')
        && preg_match('/^[0-9a-f-]{36}$/', $r->header('x-request-id')[0] ?? ''));
});

it('turns a rejected login into an authentication error with a hint', function () {
    Http::fake([CONTABO_AUTH => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid user credentials'], 401)]);

    try {
        contabo()->verify();
        $this->fail('expected exception');
    } catch (ProviderException $e) {
        expect($e->isAuthenticationError())->toBeTrue()
            ->and($e->getMessage())->toContain('Invalid user credentials')
            ->and($e->getMessage())->toContain('my.contabo.com');
    }
});

it('lists the API regions, documented products and Ubuntu LTS images', function () {
    fakeContabo(['api.contabo.com/v1/compute/images*' => Http::response([
        'data' => [
            ['imageId' => 'u24', 'name' => 'ubuntu-24.04', 'version' => '24.04', 'standardImage' => true],
            ['imageId' => 'u20', 'name' => 'ubuntu-20.04', 'version' => '20.04', 'standardImage' => true],
            ['imageId' => 'deb', 'name' => 'debian-12', 'version' => '12', 'standardImage' => true],
        ],
        '_pagination' => ['totalPages' => 1],
    ])]);

    $adapter = contabo();
    $sizes = $adapter->sizes('EU');
    $images = $adapter->images();

    expect(array_column(array_map(fn ($r) => (array) $r, $adapter->regions()), 'id'))->toContain('EU', 'US-east', 'SIN')
        ->and($sizes[0]->id)->toBe('V153')
        ->and($sizes[0]->diskGb)->toBe(100)
        ->and(array_map(fn ($s) => $s->id, $sizes))->not->toContain('V93') // Storage VPS: not orderable via the API
        ->and($images)->toHaveCount(1)
        ->and($images[0]->id)->toBe('u24')
        ->and($images[0]->version)->toBe('24.04');
});

it('creates a monthly instance with ssh secrets and user data, then reads its IP', function () {
    fakeContabo([
        'api.contabo.com/v1/compute/instances/2001' => Http::response(['data' => [contaboInstance(['status' => 'running'])]]),
        'api.contabo.com/v1/compute/instances' => Http::response(['data' => [['instanceId' => 2001, 'status' => 'provisioning']]], 201),
    ]);

    $adapter = contabo();
    $machine = $adapter->createServer(new MachineSpec('Web 1', 'EU', 'V153', 'u24', ['77'], "#!/bin/sh\necho hi\n"));

    expect($machine->id)->toBe('2001')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING)
        ->and($machine->ipv4)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.contabo.com/v1/compute/instances'
        && $r['productId'] === 'V153' && $r['region'] === 'EU' && $r['imageId'] === 'u24'
        && $r['sshKeys'] === [77] && $r['period'] === 1 && $r['displayName'] === 'web-1' && str_contains($r['userData'], 'echo hi'));

    $server = $adapter->getServer('2001');
    expect($server->status)->toBe(Machine::STATUS_RUNNING)->and($server->ipv4)->toBe('203.0.113.20');
});

it('never retries a failed create', function () {
    fakeContabo(['api.contabo.com/v1/compute/instances' => Http::response(['message' => 'boom'], 500)]);

    expect(fn () => contabo()->createServer(new MachineSpec('a', 'EU', 'V153', 'u24')))->toThrow(ProviderException::class, 'Contabo: boom');

    Http::assertSentCount(2); // login + one create
});

it('cancels on destroy, and treats cancelled or missing instances as done', function () {
    fakeContabo([
        'api.contabo.com/v1/compute/instances/1/cancel' => Http::response(['data' => [['instanceId' => 1, 'cancelDate' => '2026-11-18']]], 201),
        'api.contabo.com/v1/compute/instances/1' => Http::response(['data' => [contaboInstance(['instanceId' => 1])]]),
        'api.contabo.com/v1/compute/instances/2' => Http::response(['data' => [contaboInstance(['instanceId' => 2, 'cancelDate' => '2026-11-18'])]]),
        'api.contabo.com/v1/compute/instances/3' => Http::response(['message' => 'not found'], 404),
    ]);

    $adapter = contabo();
    $adapter->destroyServer('1');
    $adapter->destroyServer('2');
    $adapter->destroyServer('3');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/compute/instances/1/cancel') && $r->body() === '{}');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/2/cancel') || str_ends_with($r->url(), '/3/cancel'));
    expect($adapter->getServer('3'))->toBeNull();
});

it('reuses an identical ssh secret, otherwise creates one', function () {
    fakeContabo([
        'api.contabo.com/v1/secrets?*' => Http::sequence()
            ->push(['data' => [['secretId' => 9, 'type' => 'ssh', 'value' => PROVIDERS_TEST_KEY]], '_pagination' => ['totalPages' => 1]])
            ->push(['data' => [], '_pagination' => ['totalPages' => 1]]),
        'api.contabo.com/v1/secrets' => Http::response(['data' => [['secretId' => 10]]], 201),
    ]);

    $adapter = contabo();

    expect($adapter->uploadSshKey('falak-dev', PROVIDERS_TEST_KEY))->toBe('9')
        ->and($adapter->uploadSshKey('falak-dev', PROVIDERS_TEST_KEY))->toBe('10');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.contabo.com/v1/secrets' && $r['type'] === 'ssh');
});
