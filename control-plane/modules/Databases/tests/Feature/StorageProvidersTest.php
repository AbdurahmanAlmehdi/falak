<?php

use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\Aws\SigV4Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

dataset('drivers', [
    's3' => [['driver' => 's3', 'region' => 'eu-central-1'], 'https://s3.eu-central-1.amazonaws.com', false, 'https://falak-backups.s3.eu-central-1.amazonaws.com/acme/x.sql.gz'],
    'r2' => [['driver' => 'r2', 'account_id' => str_repeat('ab', 16)], 'https://'.str_repeat('ab', 16).'.r2.cloudflarestorage.com', true, 'https://'.str_repeat('ab', 16).'.r2.cloudflarestorage.com/falak-backups/acme/x.sql.gz'],
    'b2' => [['driver' => 'b2', 'region' => 'us-west-004'], 'https://s3.us-west-004.backblazeb2.com', false, 'https://falak-backups.s3.us-west-004.backblazeb2.com/acme/x.sql.gz'],
    'spaces' => [['driver' => 'spaces', 'region' => 'fra1'], 'https://fra1.digitaloceanspaces.com', false, 'https://falak-backups.fra1.digitaloceanspaces.com/acme/x.sql.gz'],
    'minio' => [['driver' => 'minio', 'endpoint' => 'https://minio.example.com:9000'], 'https://minio.example.com:9000', true, 'https://minio.example.com:9000/falak-backups/acme/x.sql.gz'],
]);

it('creates providers with derived endpoints and encrypted credentials', function (array $input, string $endpoint, bool $pathStyle, string $objectUrl) {
    $this->post('/databases/storage', [
        'name' => 'Primary',
        'bucket' => 'falak-backups',
        'prefix' => '/acme/',
        'access_key_id' => 'AKIAEXAMPLEKEY123456',
        'secret_access_key' => 'very-secret-value',
        ...$input,
    ])->assertSessionHasNoErrors();

    $provider = StorageProvider::query()->firstOrFail();
    $store = app(ObjectStores::class)->for($provider);

    expect($provider->endpoint)->toBe($endpoint)
        ->and($provider->path_style)->toBe($pathStyle)
        ->and($provider->prefix)->toBe('acme')
        ->and($store->url($store->key('x.sql.gz')))->toBe($objectUrl)
        ->and(DB::table('databases_storage_providers')->value('secret_access_key'))->not->toContain('very-secret-value');
})->with('drivers');

it('never sends credentials to the UI', function () {
    databases_provider($this->organization, ['secret_access_key' => 'do-not-leak-me', 'access_key_id' => 'AKIALEAKCHECK0001234']);

    $response = $this->get('/settings/storage')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Databases/Storage', false)
        ->where('providers.0.access_key_hint', '…1234'));

    expect($response->getContent())->not->toContain('do-not-leak-me')->not->toContain('AKIALEAKCHECK0001234');
});

it('requires https endpoints and the r2 account id', function () {
    $base = ['name' => 'X', 'bucket' => 'falak-backups', 'access_key_id' => 'a', 'secret_access_key' => 'b'];

    $this->post('/databases/storage', [...$base, 'driver' => 'minio', 'endpoint' => 'http://minio.local'])->assertSessionHasErrors('endpoint');
    $this->post('/databases/storage', [...$base, 'driver' => 'r2'])->assertSessionHasErrors('account_id');
    $this->post('/databases/storage', [...$base, 'driver' => 's3'])->assertSessionHasErrors('region');
});

it('keeps stored credentials when the secret is left blank on update', function () {
    $provider = databases_provider($this->organization, ['verified_at' => now()]);

    $this->put("/databases/storage/{$provider->id}", ['name' => 'Renamed', 'driver' => 's3', 'region' => 'eu-central-1', 'bucket' => 'falak-backups'])->assertSessionHasNoErrors();

    expect($provider->refresh())
        ->name->toBe('Renamed')
        ->secret_access_key->toBe('super-secret-access-key-value')
        ->verified_at->not->toBeNull();

    $this->put("/databases/storage/{$provider->id}", ['name' => 'Renamed', 'driver' => 's3', 'region' => 'eu-central-1', 'bucket' => 'falak-backups', 'secret_access_key' => 'rotated'])->assertSessionHasNoErrors();
    expect($provider->refresh())->secret_access_key->toBe('rotated')->verified_at->toBeNull();
});

it('verifies a provider with a signed PUT and DELETE of a probe object', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $provider = databases_provider($this->organization);

    $this->post("/databases/storage/{$provider->id}/verify")->assertSessionHasNoErrors();

    expect($provider->refresh()->verified_at)->not->toBeNull();
    Http::assertSentInOrder([
        fn (Request $r) => $r->method() === 'PUT'
            && str_starts_with($r->url(), 'https://falak-backups.s3.eu-central-1.amazonaws.com/acme/.falak-verify-')
            && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLEKEY123456/')
            && $r->header('x-amz-content-sha256')[0] === hash('sha256', $r->body()),
        fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->header('Authorization')[0], 'SignedHeaders=host;x-amz-content-sha256;x-amz-date'),
    ]);
});

it('sends a PUT whose headers are exactly the ones it signed (one Content-Type)', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $provider = databases_provider($this->organization);

    app(ObjectStores::class)->for($provider)->put('acme/probe.txt', 'falak', 'text/plain');

    Http::assertSent(function (Request $r) use ($provider) {
        expect($r->method())->toBe('PUT')->and($r->toPsrRequest()->getHeader('content-type'))->toBe(['text/plain']);

        // Re-sign from what is actually on the wire, as the store does.
        preg_match('/SignedHeaders=([^,]+), Signature=([0-9a-f]{64})/', $r->header('Authorization')[0], $m);
        $signedNames = explode(';', $m[1]);
        expect($signedNames)->toContain('content-type');
        $wire = [];
        foreach ($signedNames as $name) {
            $wire[$name] = $r->toPsrRequest()->getHeaderLine($name); // duplicates come out joined, as on the wire
        }
        $signer = new SigV4Signer($provider->access_key_id, $provider->secret_access_key, $provider->region);
        $now = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $r->header('x-amz-date')[0], new DateTimeZone('UTC'));
        $canonical = $signer->canonicalRequest('PUT', (string) parse_url($r->url(), PHP_URL_PATH), '', $wire, $m[1], hash('sha256', $r->body()));
        expect($signer->signature($canonical, $now))->toBe($m[2]);

        return true;
    });
});

it('follows no redirects from a storage endpoint', function () {
    Http::fake(['*' => Http::response('', 307, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    $provider = databases_provider($this->organization);

    expect(fn () => app(ObjectStores::class)->for($provider)->put('acme/probe.txt', 'falak', 'text/plain'))->toThrow(StorageRequestFailed::class, 'HTTP 307');
    Http::assertSentCount(1);
});

it('reports storage errors without leaking secrets', function () {
    Http::fake(['*' => Http::response('<Error><Code>SignatureDoesNotMatch</Code></Error>', 403)]);
    $provider = databases_provider($this->organization, ['verified_at' => now()]);

    $response = $this->post("/databases/storage/{$provider->id}/verify")->assertSessionHasErrors('provider');

    expect(session('errors')->first('provider'))->toContain('HTTP 403 (SignatureDoesNotMatch)')->not->toContain('super-secret')
        ->and($provider->refresh()->verified_at)->toBeNull();
});

it('restricts provider management to admins', function () {
    $provider = databases_provider($this->organization);
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);

    $this->post('/databases/storage', ['name' => 'X', 'driver' => 's3', 'region' => 'eu-central-1', 'bucket' => 'b-b-b', 'access_key_id' => 'a', 'secret_access_key' => 'b'])->assertForbidden();
    $this->delete("/databases/storage/{$provider->id}")->assertForbidden();
    $this->get('/settings/storage')->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
});

it('refuses to delete providers used by schedules', function () {
    $provider = databases_provider($this->organization);
    $engine = databases_instance($this->organization);
    $db = databases_active_db($engine);
    $this->post("/databases/instances/{$engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $provider->id, 'database_ids' => [$db->id], 'cron' => '0 3 * * *'])->assertSessionHasNoErrors();

    $this->delete("/databases/storage/{$provider->id}")->assertSessionHasErrors('provider');
    expect(StorageProvider::query()->count())->toBe(1)->and(StorageDriver::S3->label())->toBe('Amazon S3');
});

it('refuses storage endpoints on private addresses unless allowed', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $base = ['name' => 'Lan', 'driver' => 'minio', 'bucket' => 'falak-backups', 'access_key_id' => 'a', 'secret_access_key' => 'b'];

    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://127.0.0.1:9000'])->assertSessionHasErrors('endpoint');
    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://169.254.169.254'])->assertSessionHasErrors('endpoint');

    // Saved while allowed, then the instance setting is turned off: requests are refused at send time.
    config(['databases.allow_private_endpoints' => true]);
    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://10.1.2.3'])->assertSessionHasNoErrors();
    $provider = StorageProvider::query()->firstOrFail();

    config(['databases.allow_private_endpoints' => false]);
    $this->post("/databases/storage/{$provider->id}/verify")->assertSessionHasErrors('provider');
    Http::assertNothingSent();
});

it('ignores the private-endpoint setting on a managed install', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $base = ['name' => 'Lan', 'driver' => 'minio', 'bucket' => 'falak-backups', 'access_key_id' => 'a', 'secret_access_key' => 'b'];
    config(['databases.allow_private_endpoints' => true]);
    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://10.1.2.3'])->assertSessionHasNoErrors();
    $provider = StorageProvider::query()->firstOrFail();

    config(['controlplane.managed' => true]);
    $this->post('/databases/storage', [...$base, 'name' => 'Lan 2', 'endpoint' => 'https://10.1.2.4'])->assertSessionHasErrors('endpoint');
    $this->post("/databases/storage/{$provider->id}/verify")->assertSessionHasErrors('provider');
    Http::assertNothingSent();
});

it('refuses storage endpoints whose IPv6 addresses embed metadata or private IPv4 ones', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $base = ['name' => 'Lan', 'driver' => 'minio', 'bucket' => 'falak-backups', 'access_key_id' => 'a', 'secret_access_key' => 'b'];

    // Metadata in an IPv4-mapped or NAT64 address, or site-local IPv6: refused even where private endpoints are allowed.
    config(['databases.allow_private_endpoints' => true]);
    foreach (['::ffff:a9fe:a9fe', '64:ff9b::a9fe:a9fe', 'fec0::1'] as $address) {
        databases_fake_dns($address);
        $this->post('/databases/storage', [...$base, 'endpoint' => 'https://minio.example.com'])->assertSessionHasErrors('endpoint');
        expect(session('errors')->first('endpoint'))->toContain('never allowed');
    }

    // A private IPv4 address behind 6to4: only where private endpoints are allowed.
    databases_fake_dns('2002:c0a8:0101::1');
    config(['databases.allow_private_endpoints' => false]);
    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://minio.example.com'])->assertSessionHasErrors('endpoint');
    config(['databases.allow_private_endpoints' => true]);
    $this->post('/databases/storage', [...$base, 'endpoint' => 'https://minio.example.com'])->assertSessionHasNoErrors();

    // Rebound to metadata before the request: refused at send time.
    databases_fake_dns('::ffff:169.254.169.254');
    $this->post('/databases/storage/'.StorageProvider::query()->firstOrFail()->id.'/verify')->assertSessionHasErrors('provider');
    Http::assertNothingSent();
});
