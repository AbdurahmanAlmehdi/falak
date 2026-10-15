<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Terminal\Application\Actions\StartNetCapture;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Domain\Data\NetCaptureSpec;
use Falak\Terminal\Domain\Models\NetCapture;
use Falak\Terminal\Infrastructure\NetCaptureStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    Storage::fake('local');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization, ['name' => 'main-1']);
    $this->agent = fleet_enroll($this->organization->id, $this->server->id, ['features' => ['net.tools', 'net.capture']]);
    $this->mtls = fleet_mtls($this->agent['fingerprint']);
});

/** Start a capture on the test server. */
function capture_start(array $input = []): NetCapture
{
    test()->postJson('/servers/'.test()->server->id.'/net-captures', ['acknowledged' => true, ...$input])->assertCreated();

    return NetCapture::query()->latest('id')->firstOrFail();
}

/** A pcap: the global header and $n records of $size bytes. */
function capture_pcap(int $n = 3, int $size = 40): string
{
    $pcap = pack('VvvlVVV', 0xA1B2C3D4, 2, 4, 0, 0, 262144, 1);

    for ($i = 0; $i < $n; $i++) {
        $pcap .= pack('VVVV', $i, 0, $size, $size).str_repeat(chr(65 + $i), $size);
    }

    return $pcap;
}

/**
 * POST a raw body with headers (call() takes server variables, not headers).
 *
 * @param  array<string, string>  $headers
 */
function capture_post(string $uri, string $body, array $headers): TestResponse
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}"] = $value;
    }

    return test()->call('POST', $uri, [], [], [], $server, $body);
}

/** Post one chunk as an agent. */
function capture_upload(NetCapture $capture, int $offset, string $chunk, string $file, ?array $mtls = null, ?string $chunkSha = null): TestResponse
{
    return capture_post("/agent/v1/uploads/net.capture/{$capture->id}?offset={$offset}", $chunk, [
        ...($mtls ?? test()->mtls),
        'Content-Type' => 'application/octet-stream',
        'Content-Length' => (string) strlen($chunk),
        'X-Falak-Chunk-Sha256' => $chunkSha ?? hash('sha256', $chunk),
        'X-Falak-Upload-Size' => (string) strlen($file),
        'X-Falak-Upload-Sha256' => hash('sha256', $file),
    ]);
}

/** Upload a whole file in two chunks. */
function capture_upload_all(NetCapture $capture, string $file): void
{
    $half = intdiv(strlen($file), 2);
    capture_upload($capture, 0, substr($file, 0, $half), $file)->assertOk()->assertJson(['received' => $half, 'status' => 'uploading']);
    capture_upload($capture, $half, substr($file, $half), $file)->assertOk()->assertJson(['received' => strlen($file), 'status' => 'ready']);
}

function capture_progress(NetCapture $capture, int $bytes, int $packets, ?array $mtls = null): TestResponse
{
    return test()->postJson('/agent/v1/requests/net.capture.progress', ['capture_id' => $capture->id, 'bytes' => $bytes, 'packets' => $packets], $mtls ?? test()->mtls);
}

function capture_site(Server $server, array $attributes = []): Site
{
    $site = Site::query()->create([
        'organization_id' => $server->organization_id, 'name' => 'API', 'slug' => 'api', 'runtime' => SiteRuntime::Docker, 'build_mode' => BuildMode::Docker,
        'framework' => Framework::Docker, 'unix_user' => 'api', 'isolated' => true, 'deploy_script' => '$FALAK_FETCH', 'laravel' => new LaravelSettings, ...$attributes,
    ]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

    return $site->load('targets');
}

// ---- starting -----------------------------------------------------------------------------------------------

it('starts a capture on a server: payload, row, audit entry', function () {
    $response = $this->postJson("/servers/{$this->server->id}/net-captures", [
        'acknowledged' => true, 'interface' => 'wireguard', 'duration_s' => 10, 'max_mb' => 5, 'filter' => ['host' => '10.0.0.0/24', 'port' => '5432', 'protocol' => 'tcp'],
    ])->assertCreated();

    $capture = NetCapture::query()->sole();
    $command = $this->agents->last('net.capture');
    expect($command['payload'])->toBe([
        'capture_id' => $capture->id, 'interface' => 'wireguard', 'filter' => ['host' => '10.0.0.0/24', 'protocol' => 'tcp', 'port' => 5432],
        'snaplen' => 128, 'duration_s' => 10, 'max_bytes' => 5 * 1024 * 1024,
    ])->and($command['timeout'])->toBe(10 + StartNetCapture::COMMAND_GRACE_SECONDS)
        ->and($response->json('data'))->toMatchArray(['id' => $capture->id, 'status' => 'queued', 'server_name' => 'main-1', 'download_url' => null])
        ->and($response->json('data'))->not->toHaveKey('data_key')
        ->and($capture->expires_at->diffInHours(now(), true))->toBeGreaterThan(23.9);

    $audit = AuditEntry::query()->where('action', 'network.capture_started')->sole();
    expect($audit->context)->toMatchArray(['target_type' => 'server', 'server_name' => 'main-1', 'interface' => 'wireguard', 'duration_s' => 10, 'snaplen' => 128]);
});

it('captures in a container for docker sites and databases, never with an interface', function () {
    $site = capture_site($this->server);
    $this->postJson("/sites/{$site->id}/net-captures", ['acknowledged' => true, 'full_packets' => true])->assertCreated();
    expect($this->agents->last('net.capture')['payload'])->toMatchArray(['container' => ['labels' => ['falak.site' => 'api']], 'snaplen' => 262144])
        ->and($this->agents->last('net.capture')['payload'])->not->toHaveKey('interface');

    $this->postJson("/sites/{$site->id}/net-captures", ['acknowledged' => true, 'interface' => 'default'])->assertJsonValidationErrors('interface');

    $instance = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-db']);
    $this->postJson("/api/v1/databases/instances/{$instance->id}/net-captures", ['acknowledged' => true])->assertCreated();
    expect($this->agents->last('net.capture')['payload']['container'])->toBe(['name' => "falak-db-{$instance->id}"]);

    $fn = capture_site($this->server, ['slug' => 'hello', 'name' => 'Hello', 'runtime' => SiteRuntime::Function]);
    $this->postJson("/sites/{$fn->id}/net-captures", ['acknowledged' => true])->assertJsonValidationErrors(['site' => 'Functions run on demand']);
});

it('needs the acknowledgement and validates like the agent', function (array $input, string $field) {
    $this->postJson("/servers/{$this->server->id}/net-captures", ['acknowledged' => true, ...$input])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->agents->assertNothingDispatched('net.capture');
})->with([
    'not acknowledged' => [['acknowledged' => false], 'acknowledged'],
    'a host name' => [['filter' => ['host' => 'example.com']], 'filter.host'],
    'option injection' => [['filter' => ['host' => '-w /etc/passwd']], 'filter.host'],
    'bpf injection' => [['filter' => ['host' => '10.0.0.1 or port 22']], 'filter.host'],
    'bad prefix' => [['filter' => ['host' => '10.0.0.0/33']], 'filter.host'],
    'raw bpf' => [['filter' => ['expression' => 'tcp']], 'filter'],
    'icmp port' => [['filter' => ['protocol' => 'icmp', 'port' => 1]], 'filter.port'],
    'protocol' => [['filter' => ['protocol' => 'arp']], 'filter.protocol'],
    'direction' => [['filter' => ['direction' => 'inout']], 'filter.direction'],
    'duration' => [['duration_s' => 61], 'duration_s'],
    'size' => [['max_mb' => 101], 'max_mb'],
    'interface' => [['interface' => 'eth0'], 'interface'],
]);

it('builds payloads with defaults', function () {
    expect(NetCaptureSpec::payload([], false))->toBe(['interface' => 'default', 'snaplen' => 128, 'duration_s' => 30, 'max_bytes' => 20 * 1024 * 1024])
        ->and(NetCaptureSpec::payload(['filter' => ['host' => '2001:db8::/32', 'direction' => 'in']], true))->toBe(['filter' => ['host' => '2001:db8::/32', 'direction' => 'in'], 'snaplen' => 128, 'duration_s' => 30, 'max_bytes' => 20 * 1024 * 1024])
        ->and(fn () => NetCaptureSpec::payload(['filter' => ['host' => 'fe80::1%eth0']], false))->toThrow(ValidationException::class);
});

it('needs network.capture: admins yes, developers and viewers no', function (Role $role, int $status) {
    [, $organization] = actingAsMember($role);
    $server = terminal_server($organization, ['name' => 'x-1']);
    fleet_enroll($organization->id, $server->id, ['features' => ['net.capture']]);

    $this->postJson("/servers/{$server->id}/net-captures", ['acknowledged' => true])->assertStatus($status);
    $this->postJson("/api/v1/servers/{$server->id}/net-captures", ['acknowledged' => true])->assertStatus($status === 201 ? 201 : 403);
})->with([
    'admin' => [Role::Admin, 201],
    'developer' => [Role::Developer, 403],
    'viewer' => [Role::Viewer, 403],
]);

it('refuses old agents and other organizations', function () {
    $old = terminal_server($this->organization, ['name' => 'old-1']);
    fleet_enroll($this->organization->id, $old->id, ['features' => ['net.tools']]);
    $this->postJson("/servers/{$old->id}/net-captures", ['acknowledged' => true])->assertJsonValidationErrors(['server' => 'too old for packet capture']);

    [, $other] = memberOf(null, Role::Owner);
    $foreign = terminal_server($other, ['name' => 'theirs']);
    $this->postJson("/servers/{$foreign->id}/net-captures", ['acknowledged' => true])->assertNotFound();
    $this->agents->assertNothingDispatched('net.capture');
});

it('limits captures per organization: 3 in progress, 10 an hour', function () {
    capture_start();
    capture_start();
    capture_start();
    $this->postJson("/servers/{$this->server->id}/net-captures", ['acknowledged' => true])->assertStatus(429)->assertJson(['message' => StartNetCapture::BUSY]);

    NetCapture::query()->update(['status' => NetCapture::READY]);
    for ($i = 0; $i < 7; $i++) {
        NetCapture::query()->first()->replicate()->fill(['id' => strtolower((string) Str::ulid()), 'command_id' => null])->save();
    }
    expect(NetCapture::query()->count())->toBe(10);
    $this->postJson("/api/v1/servers/{$this->server->id}/net-captures", ['acknowledged' => true])->assertStatus(429)->assertJson(['message' => 'At most 10 captures an hour per organization.']);
});

// ---- progress, cancel ---------------------------------------------------------------------------------------

it('records progress and tells the agent to stop once cancelled', function () {
    $capture = capture_start();
    capture_progress($capture, 4096, 12)->assertOk()->assertExactJson(['cancel' => false]);
    expect($capture->fresh()->only(['status', 'bytes_captured', 'packets_captured']))->toBe(['status' => 'capturing', 'bytes_captured' => 4096, 'packets_captured' => 12]);

    // Another server's agent learns nothing and changes nothing.
    $intruder = terminal_server($this->organization, ['name' => 'other']);
    capture_progress($capture, 1, 1, fleet_mtls(fleet_enroll($this->organization->id, $intruder->id)['fingerprint']))->assertExactJson(['cancel' => true]);
    expect($capture->fresh()->bytes_captured)->toBe(4096);

    // Delivered already: Cancel waits for the agent's next report.
    $this->agents->started($capture->command_id);
    $this->postJson("/net-captures/{$capture->id}/cancel")->assertOk()->assertJsonPath('data.cancel_requested', true);
    expect($capture->fresh()->status)->toBe('capturing');
    capture_progress($capture, 5000, 13)->assertExactJson(['cancel' => true]);
    capture_upload($capture, 0, 'x', 'x'.str_repeat('y', 30))->assertStatus(409);

    $this->agents->fail($capture->command_id, 'capture cancelled', 130);
    expect($capture->fresh()->status)->toBe('cancelled')
        ->and(AuditEntry::query()->where('action', 'network.capture_cancelled')->count())->toBe(1);
});

it('cancels a capture the agent has not picked up yet right away', function () {
    $capture = capture_start();
    $this->postJson("/api/v1/net-captures/{$capture->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

// ---- upload, storage, download ------------------------------------------------------------------------------

it('takes the upload in chunks, keeps it encrypted and serves it decrypted to the organization', function () {
    $capture = capture_start();
    $file = capture_pcap();
    capture_upload_all($capture, $file);

    $capture->refresh();
    expect($capture->only(['status', 'size_bytes', 'bytes_received', 'sha256']))->toBe(['status' => 'ready', 'size_bytes' => strlen($file), 'bytes_received' => strlen($file), 'sha256' => hash('sha256', $file)]);

    // At rest: sealed chunks, no plaintext.
    $stored = collect(Storage::disk('local')->allFiles("net-captures/{$capture->id}"))->map(fn ($path) => Storage::disk('local')->get($path))->implode('');
    expect($stored)->not->toContain('AAAA')->and(strlen($stored))->toBeGreaterThan(strlen($file));

    $this->agents->succeed($capture->command_id, ['capture_id' => $capture->id, 'bytes' => strlen($file), 'packets' => 3, 'sha256' => hash('sha256', $file), 'ended' => 'duration', 'interface' => 'eth0', 'namespace' => 'host', 'expression' => '']);
    expect($capture->fresh()->only(['status', 'packets_captured']))->toBe(['status' => 'ready', 'packets_captured' => 3]);

    $download = $this->get("/net-captures/{$capture->id}/download")->assertOk();
    expect($download->streamedContent())->toBe($file)
        ->and($download->headers->get('Content-Type'))->toBe('application/vnd.tcpdump.pcap')
        ->and($download->headers->get('Content-Disposition'))->toContain('falak-main-1-');
    expect(AuditEntry::query()->where('action', 'network.capture_downloaded')->sole()->context)->toMatchArray(['size_bytes' => strlen($file), 'sha256' => hash('sha256', $file)]);

    // Admins of the organization can download too; other organizations and developers can't.
    actingAsMember(Role::Admin, $this->organization);
    $this->get("/net-captures/{$capture->id}/download")->assertOk();
    actingAsMember(Role::Developer, $this->organization);
    $this->get("/net-captures/{$capture->id}/download")->assertForbidden();
    actingAsMember(Role::Owner);
    $this->get("/net-captures/{$capture->id}/download")->assertNotFound();
    $this->getJson("/net-captures/{$capture->id}")->assertNotFound();
});

it('binds each chunk to its capture and offset', function () {
    $a = capture_start();
    $b = capture_start();
    $file = capture_pcap();
    capture_upload_all($a, $file);
    capture_upload($b, 0, substr($file, 0, 10), $file)->assertOk();

    // A's first chunk copied over B's: B's key and AAD don't open it.
    Storage::disk('local')->put(NetCaptureStore::path($b->id, 0), Storage::disk('local')->get(NetCaptureStore::path($a->id, 0)));
    expect(fn () => app(NetCaptureStore::class)->sha256($b->fresh()))->toThrow(DecryptionFailed::class);
});

it('accepts chunks only from the server’s agent, in order, within the size, once', function () {
    $capture = capture_start(['max_mb' => 1]);
    $file = capture_pcap();

    $intruder = terminal_server($this->organization, ['name' => 'other']);
    capture_upload($capture, 0, substr($file, 0, 10), $file, fleet_mtls(fleet_enroll($this->organization->id, $intruder->id)['fingerprint']))->assertStatus(409)->assertJson(['error' => 'unknown_capture']);
    capture_post("/agent/v1/uploads/net.capture/{$capture->id}?offset=0", 'x', ['Content-Type' => 'application/octet-stream', 'Content-Length' => '1'])->assertUnauthorized();

    capture_upload($capture, 0, substr($file, 0, 10), $file, chunkSha: str_repeat('0', 64))->assertStatus(422)->assertJson(['error' => 'chunk_checksum']);
    capture_upload($capture, 5, substr($file, 0, 10), $file)->assertStatus(409)->assertJson(['error' => 'offset_mismatch']);
    capture_upload($capture, 0, 'x', str_repeat('x', 1024 * 1024 + 1))->assertStatus(409)->assertJson(['error' => 'too_large']);

    capture_upload($capture, 0, substr($file, 0, 10), $file)->assertOk()->assertJson(['received' => 10]);
    // A retry of a chunk that landed is answered again, not stored twice.
    capture_upload($capture, 0, substr($file, 0, 10), $file)->assertOk()->assertJson(['received' => 10]);
    // The declared file can't change midway.
    capture_upload($capture, 10, substr($file, 10, 10), $file.'x')->assertStatus(409)->assertJson(['error' => 'upload_changed']);
    capture_upload($capture, 10, substr($file, 10), $file)->assertOk()->assertJson(['status' => 'ready']);
    expect(app(NetCaptureStore::class)->offsets($capture->id))->toBe([0, 10]);

    // Ready: a replayed earlier chunk is refused, the final one is answered idempotently.
    capture_upload($capture, 0, substr($file, 0, 10), $file)->assertStatus(409)->assertJson(['error' => 'not_accepting']);
    capture_upload($capture, 10, substr($file, 10), $file)->assertOk();
});

it('fails a capture whose file does not match its checksum', function () {
    $capture = capture_start();
    $file = capture_pcap();
    capture_post("/agent/v1/uploads/net.capture/{$capture->id}?offset=0", $file, [...$this->mtls, 'Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($file),
        'X-Falak-Chunk-Sha256' => hash('sha256', $file), 'X-Falak-Upload-Size' => (string) strlen($file), 'X-Falak-Upload-Sha256' => str_repeat('a', 64),
    ])->assertStatus(409)->assertJson(['error' => 'checksum_mismatch']);

    expect($capture->fresh()->status)->toBe('failed')
        ->and(Storage::disk('local')->allFiles("net-captures/{$capture->id}"))->toBe([]);
});

it('settles from the command outcome', function () {
    $missing = capture_start();
    $this->agents->succeed($missing->command_id, ['bytes' => 24]);
    expect($missing->fresh()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'The capture ended without a complete upload.']);

    $failed = capture_start();
    capture_upload($failed, 0, 'abcd', capture_pcap())->assertOk();
    $this->agents->fail($failed->command_id, 'tcpdump exited with 1: eth9: No such device exists', 1);
    expect($failed->fresh()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'tcpdump exited with 1: eth9: No such device exists'])
        ->and(Storage::disk('local')->allFiles("net-captures/{$failed->id}"))->toBe([]);

    $history = $this->getJson("/net-captures?target_type=server&target_id={$this->server->id}")->assertOk()->json('data');
    expect(array_column($history, 'id'))->toEqualCanonicalizing([$missing->id, $failed->id]);
});

it('expires captures after 24 hours and fails stuck ones', function () {
    $capture = capture_start();
    $file = capture_pcap();
    capture_upload_all($capture, $file);
    $stuck = capture_start();
    NetCapture::query()->whereKey($stuck->id)->update(['created_at' => now()->subMinutes(20)]);

    $this->travel(25)->hours();
    app()->call([new SweepTerminalSessions, 'handle']);

    expect($capture->fresh()->status)->toBe('expired')
        ->and(Storage::disk('local')->allFiles("net-captures/{$capture->id}"))->toBe([])
        ->and($stuck->fresh()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'The capture did not finish in time.']);
    $this->get("/net-captures/{$capture->id}/download")->assertStatus(410);

    $this->travel(31)->days();
    app()->call([new SweepTerminalSessions, 'handle']);
    expect(NetCapture::query()->count())->toBe(0);
});
