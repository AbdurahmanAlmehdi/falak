<?php

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\Data\DatabaseInstanceData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Jobs\SweepSandboxes;
use Falak\Sandboxes\Application\Jobs\SyncSandboxes;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

// Sandboxes v0.21 (docs/plans/SANDBOXES.md "Contracts (v0.21)"): snapshots, forks, pause/resume, idle pause, database copies.

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = snap_server($this->organization, 'main-1');
    $this->token = snap_token($this->user, $this->organization);
});

/** @param  list<string>  $features */
function snap_server(Organization $organization, string $name, array $features = ['sandbox.v1', 'sandbox.v2', 'terminal.v2']): Server
{
    $server = Server::factory()->create(['organization_id' => $organization->id, 'name' => $name, 'type' => ServerType::App]);
    Agent::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'facts' => ['features' => $features, 'memory_bytes' => 16384 * 1024 ** 2, 'cpus' => 8],
        'metrics' => ['memory_used_bytes' => 0],
    ]);

    return $server;
}

/** @param  list<string>  $abilities */
function snap_token(User $user, Organization $organization, array $abilities = ['*']): string
{
    $token = app(CreateApiToken::class)($user, $organization->id, 'agent-bot', $abilities)->plainTextToken;
    auth()->forgetGuards();

    return $token;
}

function snap_sandbox(array $attributes = []): Sandbox
{
    return Sandbox::query()->create([
        'organization_id' => test()->organization->id,
        'server_id' => test()->server->id,
        'name' => 'calm-otter',
        'image' => 'node:22',
        'image_digest' => 'sha256:node',
        'status' => Sandbox::RUNNING,
        'cpus' => 2,
        'memory_mb' => 2048,
        'disk_mb' => 20480,
        'network' => 'allowlist',
        'allow_hosts' => ['github.com'],
        'env' => ['API_KEY' => 'hunter2-secret'],
        'ttl_seconds' => 3600,
        'expires_at' => now()->addHour(),
        'created_by_type' => 'user',
        'created_by_id' => test()->user->id,
        'created_by_name' => test()->user->name,
        'last_activity_at' => now(),
        ...$attributes,
    ]);
}

function snap_snapshot(Sandbox $sandbox, array $attributes = []): SandboxSnapshot
{
    return SandboxSnapshot::query()->create([
        'organization_id' => $sandbox->organization_id,
        'sandbox_id' => $sandbox->id,
        'server_id' => $sandbox->server_id,
        'name' => 'node-ready',
        'status' => SandboxSnapshot::READY,
        'image' => $sandbox->image,
        'image_digest' => $sandbox->image_digest,
        'cpus' => $sandbox->cpus,
        'memory_mb' => $sandbox->memory_mb,
        'disk_mb' => $sandbox->disk_mb,
        'network' => $sandbox->network,
        'allow_hosts' => $sandbox->allow_hosts,
        'env' => $sandbox->env,
        'size_bytes' => 31457280,
        'created_by_type' => 'user',
        'created_by_id' => $sandbox->created_by_id,
        'created_by_name' => $sandbox->created_by_name,
        ...$attributes,
    ]);
}

/** @return array<string, list<string>> */
function snap_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

function snap_api(): TestCase
{
    return test()->withToken(test()->token);
}

function snap_running(): array
{
    return ['status' => 'running', 'ip' => '10.88.0.9', 'image_digest' => 'sha256:node', 'started_at' => now()->toIso8601String()];
}

/** A database directory with one postgres instance "pg" (database "shop") on $serverId and a redis one. */
function snap_databases(string $organizationId, string $serverId): string
{
    $instanceId = strtolower((string) Str::ulid());
    app()->instance(DatabaseDirectory::class, new class($organizationId, $serverId, $instanceId) implements DatabaseDirectory
    {
        public function __construct(private string $org, private string $server, private string $id) {}

        public function find(string $databaseId): ?DatabaseData
        {
            return null;
        }

        public function findMany(array $databaseIds): array
        {
            return [];
        }

        public function forOrganization(string $organizationId): array
        {
            return $this->forServer($this->server);
        }

        public function forServer(string $serverId): array
        {
            return $serverId === $this->server ? [new DatabaseData('db1', $this->org, $this->server, 'shop', 'postgresql', '17', 5432, 'active', null, instanceId: $this->id)] : [];
        }

        public function forSite(string $organizationId, string $siteId): array
        {
            return [];
        }

        public function instance(string $instanceId): ?DatabaseInstanceData
        {
            return collect($this->instancesForOrganization($this->org))->first(fn ($i) => $i->id === $instanceId);
        }

        public function instancesForOrganization(string $organizationId): array
        {
            return $organizationId !== $this->org ? [] : [
                new DatabaseInstanceData($this->id, $this->org, $this->server, 'main-1', 'pg', 'postgresql', '17', 'active', "falak-db-{$this->id}"),
                new DatabaseInstanceData(strtolower((string) Str::ulid()), $this->org, $this->server, 'main-1', 'cache', 'redis', '7', 'active', 'falak-db-cache'),
            ];
        }
    });

    return $instanceId;
}

// ---- snapshots -----------------------------------------------------------------------------------------------

it('takes a snapshot that becomes ready, and lists, shows and renames it', function () {
    $sandbox = snap_sandbox();

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->name}/snapshots", ['name' => 'node-ready'])->assertCreated()
        ->assertJsonPath('data.status', 'creating')
        ->assertJsonPath('data.name', 'node-ready')
        ->assertJsonPath('data.sandbox', ['id' => $sandbox->id, 'name' => 'calm-otter'])
        ->assertJsonPath('data.server', ['id' => $this->server->id, 'name' => 'main-1'])
        ->assertJsonPath('data.image', 'node:22')
        ->assertJsonPath('data.cpus', 2)
        ->assertJsonPath('data.memory_mb', 2048)
        ->assertJsonPath('data.network', 'allowlist')
        ->assertJsonPath('data.created_by.name', 'agent-bot');

    $snapshot = SandboxSnapshot::query()->sole();
    $command = $this->agents->last('sandbox.snapshot');
    expect(snap_schema_errors($command))->toBe([])
        ->and($command['payload'])->toBe(['id' => $sandbox->id, 'snapshot_id' => $snapshot->id, 'leave_running' => true])
        ->and($command['handle']->idempotencyKey)->toBe("sandbox.snapshot:{$snapshot->id}")
        ->and(AuditEntry::query()->where('action', 'sandbox.snapshot_created')->exists())->toBeTrue();

    $this->agents->succeed($command['handle'], ['snapshot_id' => $snapshot->id, 'size_bytes' => 1234, 'image_digest' => 'sha256:node', 'created_at' => now()->toIso8601String()]);
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::READY)
        ->and($snapshot->size_bytes)->toBe(1234)
        ->and($sandbox->refresh()->status)->toBe(Sandbox::RUNNING);

    snap_api()->getJson('/api/v1/sandboxes/snapshots')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $snapshot->id);
    snap_api()->getJson('/api/v1/sandboxes/snapshots?sandbox=calm-otter')->assertOk()->assertJsonCount(1, 'data');
    snap_api()->getJson('/api/v1/sandboxes/snapshots?sandbox=nope')->assertOk()->assertJsonCount(0, 'data');
    snap_api()->getJson('/api/v1/sandboxes/snapshots/node-ready')->assertOk()->assertJsonPath('data.size_bytes', 1234);
    snap_api()->patchJson('/api/v1/sandboxes/snapshots/node-ready', ['name' => 'template'])->assertOk()->assertJsonPath('data.name', 'template');

    // Names are unique among snapshots that aren't deleted.
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots", ['name' => 'template'])->assertUnprocessable()->assertJsonValidationErrors('name');
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots", ['name' => 'other'])->assertCreated();
    snap_api()->patchJson('/api/v1/sandboxes/snapshots/other', ['name' => 'template'])->assertUnprocessable()->assertJsonValidationErrors('name');
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots", [])->assertCreated()->assertJsonPath('data.name', null);
});

it('stops the sandbox with stop, waits with ?wait=1 and fails a snapshot the agent could not take', function () {
    $sandbox = snap_sandbox();
    $this->agents->answer('sandbox.snapshot', fn (array $payload) => ['snapshot_id' => $payload['snapshot_id'], 'size_bytes' => 99, 'image_digest' => 'sha256:node', 'created_at' => now()->toIso8601String()]);

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots?wait=1", ['stop' => true])->assertCreated()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.size_bytes', 99);
    $command = $this->agents->last('sandbox.snapshot');
    expect($command['payload']['leave_running'])->toBeFalse()
        ->and($command['handle']->idempotencyKey)->toEndWith(':stop')
        ->and($sandbox->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($sandbox->kill_reason)->toBe('snapshot')
        ->and($sandbox->kill_confirmed_at)->not->toBeNull()
        ->and($this->agents->dispatched('sandbox.kill'))->toBe([]);

    $this->agents = FakeAgentGateway::install();
    $other = snap_sandbox(['name' => 'other']);
    snap_api()->postJson("/api/v1/sandboxes/{$other->id}/snapshots")->assertCreated();
    $this->agents->fail($this->agents->last('sandbox.snapshot')['handle'], 'checkpoint: no space left');
    expect(SandboxSnapshot::query()->where('sandbox_id', $other->id)->sole())->status->toBe(SandboxSnapshot::FAILED)
        ->error->toBe('checkpoint: no space left')
        ->and($other->refresh()->status)->toBe(Sandbox::RUNNING);
});

it('enforces the snapshot quotas', function () {
    expect(config('sandboxes.max_snapshots'))->toBe(20)->and(config('sandboxes.max_snapshot_storage_mb'))->toBe(51200);
    $sandbox = snap_sandbox();
    config(['sandboxes.max_snapshots' => 2, 'sandboxes.max_snapshot_storage_mb' => 10]);

    snap_snapshot($sandbox, ['name' => 'gone', 'status' => SandboxSnapshot::DELETED, 'size_bytes' => 50 * 1024 ** 2]);
    snap_snapshot($sandbox, ['name' => 'a', 'size_bytes' => 5 * 1024 ** 2]);
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertCreated();
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertUnprocessable()
        ->assertJsonValidationErrors(['snapshot' => 'keeps 2 of its 2 snapshots']);

    config(['sandboxes.max_snapshots' => 10]);
    SandboxSnapshot::query()->where('status', SandboxSnapshot::CREATING)->delete();
    SandboxSnapshot::query()->where('name', 'a')->update(['size_bytes' => 11 * 1024 ** 2]);
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertUnprocessable()
        ->assertJsonValidationErrors(['snapshot' => 'may take 10 MB in all; 11 MB are taken']);
});

it('counts snapshots still being taken against the storage quota with their sandbox\'s memory and disk', function () {
    $sandbox = snap_sandbox();
    config(['sandboxes.max_snapshot_storage_mb' => 20000]);

    // The first is being taken (size unknown): it reserves 2048 + 20480 MB, so a second one at the same time is refused.
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertCreated();
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertUnprocessable()
        ->assertJsonValidationErrors(['snapshot' => 'may take 20000 MB in all; 22528 MB are taken']);

    // Once taken, only its size counts.
    SandboxSnapshot::query()->update(['status' => SandboxSnapshot::READY, 'size_bytes' => 1024 ** 2]);
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertCreated();
});

it('deletes snapshots and sends sandbox.snapshot.delete until the agent confirmed it', function () {
    $snapshot = snap_snapshot(snap_sandbox());
    $this->agents->unavailable($this->server->id);

    snap_api()->deleteJson('/api/v1/sandboxes/snapshots/node-ready')->assertNoContent();
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::DELETED)
        ->and(AuditEntry::query()->where('action', 'sandbox.snapshot_deleted')->count())->toBe(1);
    snap_api()->getJson('/api/v1/sandboxes/snapshots')->assertOk()->assertJsonCount(0, 'data');
    snap_api()->getJson('/api/v1/sandboxes/snapshots/node-ready')->assertNotFound();

    // The name is free again.
    snap_snapshot(Sandbox::query()->sole(), ['name' => 'node-ready']);

    $this->agents->available($this->server->id);
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $delete = $this->agents->last('sandbox.snapshot.delete');
    expect(snap_schema_errors($delete))->toBe([])->and($delete['payload'])->toBe(['snapshot_id' => $snapshot->id]);
    $this->agents->succeed($delete['handle'], []);
    expect($snapshot->refresh()->delete_confirmed_at)->not->toBeNull();

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.snapshot.delete'))->toHaveCount(1);
    snap_api()->deleteJson("/api/v1/sandboxes/snapshots/{$snapshot->id}")->assertNoContent();
    expect($this->agents->dispatched('sandbox.snapshot.delete'))->toHaveCount(1);
});

// ---- fork ------------------------------------------------------------------------------------------------------

it('forks a snapshot into N sandboxes on its server with its sizes, and creates one from a snapshot', function () {
    $snapshot = snap_snapshot(snap_sandbox(['status' => Sandbox::KILLED]));
    snap_server($this->organization, 'roomy');

    $response = snap_api()->postJson('/api/v1/sandboxes/snapshots/node-ready/fork', ['count' => 3, 'name' => 'worker', 'ttl' => 1800, 'env' => ['JOB' => '1'], 'idle_pause' => '10m'])
        ->assertCreated()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'worker-1')
        ->assertJsonPath('data.2.name', 'worker-3')
        ->assertJsonPath('data.0.from_snapshot', ['id' => $snapshot->id, 'name' => 'node-ready'])
        ->assertJsonPath('data.0.idle_pause_seconds', 600)
        ->assertJsonPath('data.0.server.name', 'main-1')
        ->assertJsonPath('data.0.ttl_seconds', 1800)
        ->assertJsonPath('data.0.env_keys', ['API_KEY', 'JOB']);
    expect($response->json('data.0.last_activity_at'))->not->toBeNull();

    $creates = $this->agents->dispatched('sandbox.create');
    expect($creates)->toHaveCount(3);

    foreach ($creates as $create) {
        expect(snap_schema_errors($create))->toBe([])
            ->and($create['handle']->serverId)->toBe($this->server->id)
            ->and($create['payload'])->toMatchArray(['image' => 'node:22', 'cpus' => 2, 'memory_mb' => 2048, 'disk_mb' => 20480, 'network' => 'allowlist',
                'allow_hosts' => ['github.com'], 'env' => ['JOB' => '1'], 'from_snapshot' => $snapshot->id])
            ->and($create['payload'])->not->toHaveKey('repo');
    }

    // POST /sandboxes {snapshot} = a fork of one; the snapshot fixes image, size, network and server.
    snap_api()->postJson('/api/v1/sandboxes?wait=1', ['snapshot' => 'node-ready', 'name' => 'single'])->assertCreated()
        ->assertJsonPath('data.name', 'single')
        ->assertJsonPath('data.from_snapshot.id', $snapshot->id);
    snap_api()->postJson('/api/v1/sandboxes', ['snapshot' => 'node-ready', 'image' => 'ubuntu:24.04', 'memory_mb' => 512, 'server' => 'roomy'])->assertUnprocessable()
        ->assertJsonValidationErrors(['image', 'memory_mb', 'server']);
    snap_api()->postJson('/api/v1/sandboxes', ['snapshot' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('snapshot');

    $this->agents->succeed($creates[0]['handle'], snap_running());
    expect(Sandbox::query()->where('name', 'worker-1')->sole()->status)->toBe(Sandbox::RUNNING);
});

it('counts all forks against the quotas together and refuses snapshots that are not ready', function () {
    $snapshot = snap_snapshot(snap_sandbox());
    config(['sandboxes.max_running' => 3]);

    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork", ['count' => 3])->assertUnprocessable()
        ->assertJsonValidationErrors(['sandbox' => '3 more don\'t fit']);
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork", ['count' => 2])->assertCreated()->assertJsonCount(2, 'data');
    expect(Sandbox::query()->count())->toBe(3);

    config(['sandboxes.max_running' => 50, 'sandboxes.max_total_memory_mb' => 8192]);
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork", ['count' => 2])->assertUnprocessable()->assertJsonValidationErrors('memory_mb');
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork", ['count' => 11])->assertUnprocessable()->assertJsonValidationErrors('count');
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork", ['idle_pause' => 30])->assertUnprocessable()->assertJsonValidationErrors('idle_pause');

    $snapshot->forceFill(['status' => SandboxSnapshot::CREATING])->save();
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork")->assertStatus(409)->assertJsonPath('message', 'The snapshot is still being taken.');
});

it('keeps snapshots, forks, pause and database copies to the creator and sandboxes.manage, and other organizations out', function () {
    $sandbox = snap_sandbox();
    $snapshot = snap_snapshot($sandbox);
    [$developer] = memberOf($this->organization, Role::Developer);
    $dev = snap_token($developer, $this->organization);

    $this->withToken($dev)->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertForbidden();
    $this->withToken($dev)->postJson("/api/v1/sandboxes/{$sandbox->id}/pause")->assertForbidden();
    $this->withToken($dev)->postJson("/api/v1/sandboxes/{$sandbox->id}/resume")->assertForbidden();
    $this->withToken($dev)->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertForbidden();
    $this->withToken($dev)->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork")->assertForbidden();
    $this->withToken($dev)->postJson('/api/v1/sandboxes', ['snapshot' => 'node-ready'])->assertForbidden();
    $this->withToken($dev)->deleteJson("/api/v1/sandboxes/snapshots/{$snapshot->id}")->assertForbidden();
    $this->withToken($dev)->patchJson("/api/v1/sandboxes/snapshots/{$snapshot->id}", ['name' => 'mine'])->assertForbidden();
    $this->withToken($dev)->getJson('/api/v1/sandboxes/snapshots')->assertOk()->assertJsonCount(1, 'data');
    expect($this->agents->dispatched())->toBe([]);

    $viewOnly = snap_token($this->user, $this->organization, ['sandboxes.view']);
    $this->withToken($viewOnly)->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertForbidden();
    $this->withToken($viewOnly)->getJson("/api/v1/sandboxes/snapshots/{$snapshot->id}")->assertOk();

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->withToken(snap_token($admin, $this->organization))->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork")->assertCreated();

    // Last: the routes' controllers keep the organization they were first resolved with in tests.
    $other = snap_token(...memberOf());
    $this->withToken($other)->getJson("/api/v1/sandboxes/snapshots/{$snapshot->id}")->assertNotFound();
    $this->withToken($other)->getJson('/api/v1/sandboxes/snapshots')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($other)->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork")->assertNotFound();
    $this->withToken($other)->postJson("/api/v1/sandboxes/{$sandbox->id}/pause")->assertNotFound();
});

it('needs the sandbox.v2 agent feature for every v0.21 call', function () {
    $old = snap_server($this->organization, 'old', ['sandbox.v1']);
    $sandbox = snap_sandbox(['server_id' => $old->id]);
    $snapshot = snap_snapshot($sandbox);
    $message = 'The agent on old is too old for snapshots, forks, pause and database copies (they need sandbox.v2); update it first.';
    snap_databases($this->organization->id, $old->id);

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertUnprocessable()->assertJsonValidationErrors(['server' => $message]);
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/pause")->assertUnprocessable()->assertJsonValidationErrors(['server' => $message]);
    snap_api()->postJson("/api/v1/sandboxes/snapshots/{$snapshot->id}/fork")->assertUnprocessable()->assertJsonValidationErrors(['server' => $message]);
    snap_api()->postJson('/api/v1/sandboxes', ['server' => 'old', 'idle_pause' => 300])->assertUnprocessable()->assertJsonValidationErrors(['server' => $message]);
    $this->withToken(snap_token($this->user, $this->organization, ['sandboxes.view', 'sandboxes.use', 'databases.restore']))
        ->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertUnprocessable()->assertJsonValidationErrors(['server' => $message]);
    expect($this->agents->dispatched())->toBe([]);

    snap_api()->postJson('/api/v1/sandboxes', ['server' => 'old'])->assertCreated();
});

// ---- pause, resume, idle pause ---------------------------------------------------------------------------------

it('pauses and resumes a sandbox', function () {
    $sandbox = snap_sandbox();
    $this->agents->answer('sandbox.pause', fn () => ['status' => 'paused']);
    $this->agents->answer('sandbox.resume', fn () => ['status' => 'running']);

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
    expect(snap_schema_errors($this->agents->last('sandbox.pause')))->toBe([])
        ->and($this->agents->last('sandbox.pause')['payload'])->toBe(['id' => $sandbox->id]);
    snap_api()->getJson('/api/v1/sandboxes?status=paused')->assertOk()->assertJsonCount(1, 'data');

    // Paused sandboxes still count against the quotas.
    config(['sandboxes.max_running' => 1]);
    snap_api()->postJson('/api/v1/sandboxes')->assertUnprocessable()->assertJsonValidationErrors('sandbox');

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/pause")->assertOk();
    expect($this->agents->dispatched('sandbox.pause'))->toHaveCount(1);

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/resume")->assertOk()->assertJsonPath('data.status', 'running');
    expect(snap_schema_errors($this->agents->last('sandbox.resume')))->toBe([]);

    $creating = snap_sandbox(['name' => 'starting', 'status' => Sandbox::CREATING]);
    snap_api()->postJson("/api/v1/sandboxes/{$creating->id}/pause")->assertStatus(409);
    snap_api()->postJson("/api/v1/sandboxes/{$creating->id}/resume")->assertStatus(409);
});

it('pauses idle sandboxes and resumes them for exec, files and consoles', function () {
    $idle = snap_sandbox(['idle_pause_seconds' => 600, 'last_activity_at' => now()->subMinutes(11)]);
    $busy = snap_sandbox(['name' => 'busy', 'idle_pause_seconds' => 600, 'last_activity_at' => now()->subMinutes(5)]);
    snap_sandbox(['name' => 'never', 'last_activity_at' => now()->subDay()]);

    (new SweepSandboxes)->handle(app(KillSandbox::class));
    $pauses = $this->agents->dispatched('sandbox.pause');
    expect($pauses)->toHaveCount(1)
        ->and($pauses[0]['payload'])->toBe(['id' => $idle->id])
        ->and(snap_schema_errors($pauses[0]))->toBe([]);

    $this->agents->succeed($pauses[0]['handle'], ['status' => 'paused']);
    expect($idle->refresh()->status)->toBe(Sandbox::PAUSED)->and($busy->refresh()->status)->toBe(Sandbox::RUNNING);

    // An exec on a paused sandbox resumes it first.
    $this->agents->answer('sandbox.resume', fn () => ['status' => 'running']);
    $this->agents->answer('sandbox.exec', fn () => ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'truncated' => false, 'duration_ms' => 1, 'timed_out' => false]);
    snap_api()->postJson("/api/v1/sandboxes/{$idle->id}/exec", ['cmd' => 'ls'])->assertOk();
    expect($this->agents->dispatched('sandbox.resume'))->toHaveCount(1)
        ->and($idle->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($idle->last_activity_at->gt(now()->subMinute()))->toBeTrue();

    // Files too.
    $idle->forceFill(['status' => Sandbox::PAUSED])->save();
    $this->agents->answer('sandbox.files.list', fn () => ['entries' => [], 'truncated' => false]);
    snap_api()->getJson("/api/v1/sandboxes/{$idle->id}/files?path=/&list=1")->assertOk();
    expect($this->agents->dispatched('sandbox.resume'))->toHaveCount(2)->and($idle->refresh()->status)->toBe(Sandbox::RUNNING);

    // A resume the agent can't do is the caller's error, and nothing runs.
    $idle->forceFill(['status' => Sandbox::PAUSED])->save();
    $this->agents->unavailable($this->server->id);
    snap_api()->postJson("/api/v1/sandboxes/{$idle->id}/exec", ['cmd' => 'ls'])->assertStatus(503);
    expect($this->agents->dispatched('sandbox.exec'))->toHaveCount(1);
});

it('counts idle time from when a call finished or the sandbox started, and skips a pause the agent refuses as busy', function () {
    // A long call: activity is its end, not its start.
    $sandbox = snap_sandbox(['idle_pause_seconds' => 600]);
    $this->agents->answer('sandbox.files.list', function () {
        $this->travel(11)->minutes();

        return ['entries' => [], 'truncated' => false];
    });
    snap_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/&list=1")->assertOk();
    (new SweepSandboxes)->handle(app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.pause'))->toBe([]);

    // A create that took long: active from when it runs.
    $starting = snap_sandbox(['name' => 'starting', 'status' => Sandbox::CREATING, 'idle_pause_seconds' => 600, 'last_activity_at' => now()->subMinutes(20), 'command_id' => strtolower((string) Str::ulid())]);
    event(new CommandFinished($starting->command_id, $starting->organization_id, $starting->server_id, 'sandbox.create', 'sandbox.create:x', 0, snap_running()));
    expect($starting->refresh()->status)->toBe(Sandbox::RUNNING)->and($starting->last_activity_at->gt(now()->subMinute()))->toBeTrue();

    // An exec running in it: the agent refuses ("busy: …"), it stays running and a later sweep tries again.
    $this->travel(11)->minutes();
    (new SweepSandboxes)->handle(app(KillSandbox::class));
    $this->agents->fail(collect($this->agents->dispatched('sandbox.pause'))->firstWhere('payload.id', $sandbox->id)['handle'], "busy: a command is running in sandbox {$sandbox->id}");
    expect($sandbox->refresh()->status)->toBe(Sandbox::RUNNING);
    $this->travel(1)->minutes();
    (new SweepSandboxes)->handle(app(KillSandbox::class));
    expect(collect($this->agents->dispatched('sandbox.pause'))->where('payload.id', $sandbox->id))->toHaveCount(2);
});

it('resumes a paused sandbox to open a console, and an open console keeps it active', function () {
    Http::fake(['relay:8080/*' => Http::response(null, 204)]);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'app.url' => 'https://panel.example.com', 'terminal.relay.enabled' => true]);
    $sandbox = snap_sandbox(['status' => Sandbox::PAUSED, 'idle_pause_seconds' => 600, 'last_activity_at' => now()->subHour()]);
    $this->agents->answer('sandbox.resume', fn () => ['status' => 'running']);

    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/console")->assertCreated();
    expect($this->agents->dispatched('sandbox.resume'))->toHaveCount(1)
        ->and($sandbox->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($this->agents->last('terminal.open')['payload']['sandbox'])->toBe(['id' => $sandbox->id]);

    $this->travel(20)->minutes();
    app()->call([new SweepTerminalSessions, 'handle']);
    expect($sandbox->refresh()->last_activity_at->gt(now()->subMinute()))->toBeTrue()
        ->and(TerminalSession::query()->sole()->target_id)->toBe($sandbox->id);

    (new SweepSandboxes)->handle(app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.pause'))->toBe([]);
});

it('creates sandboxes with idle_pause (at least a minute)', function () {
    snap_api()->postJson('/api/v1/sandboxes', ['idle_pause' => '10m'])->assertCreated()->assertJsonPath('data.idle_pause_seconds', 600)->assertJsonPath('data.from_snapshot', null);
    snap_api()->postJson('/api/v1/sandboxes', ['idle_pause' => 59])->assertUnprocessable()->assertJsonValidationErrors('idle_pause');
    snap_api()->postJson('/api/v1/sandboxes', ['idle_pause' => 'soon'])->assertUnprocessable()->assertJsonValidationErrors('idle_pause');
    snap_api()->postJson('/api/v1/sandboxes')->assertCreated()->assertJsonPath('data.idle_pause_seconds', null);
});

// ---- reconcile -------------------------------------------------------------------------------------------------

it('reconciles paused sandboxes and snapshots with sandbox.inventory', function () {
    $paused = snap_sandbox(['name' => 'paused']);
    $resumed = snap_sandbox(['name' => 'resumed', 'status' => Sandbox::PAUSED]);
    $lostPaused = snap_sandbox(['name' => 'lost', 'status' => Sandbox::PAUSED]);
    Sandbox::query()->update(['created_at' => now()->subMinutes(10)]);

    $source = Sandbox::query()->where('name', 'paused')->sole();
    $kept = snap_snapshot($source, ['name' => 'kept']);
    $gone = snap_snapshot($source, ['name' => 'gone']);
    $gone->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    $taking = snap_snapshot($source, ['name' => 'taking', 'status' => SandboxSnapshot::CREATING]);
    $deleted = snap_snapshot($source, ['name' => 'deleted', 'status' => SandboxSnapshot::DELETED, 'deleted_at' => now()]);
    $deletedGone = snap_snapshot($source, ['name' => 'deleted-gone', 'status' => SandboxSnapshot::DELETED, 'deleted_at' => now()]);
    $orphan = strtolower((string) Str::ulid());

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $inventory = $this->agents->last('sandbox.inventory');
    $this->agents->succeed($inventory['handle'], [
        'sandboxes' => [['id' => $paused->id, 'status' => 'paused'], ['id' => $resumed->id, 'status' => 'running']],
        'snapshots' => [['snapshot_id' => $kept->id, 'size_bytes' => 1], ['snapshot_id' => $deleted->id], ['snapshot_id' => $orphan]],
    ]);

    expect($paused->refresh()->status)->toBe(Sandbox::PAUSED)
        ->and($resumed->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($lostPaused->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($lostPaused->kill_reason)->toBe('lost')
        ->and($kept->refresh()->status)->toBe(SandboxSnapshot::READY)
        ->and($gone->refresh()->status)->toBe(SandboxSnapshot::FAILED)
        ->and($gone->error)->toBe('The snapshot is gone from its server.')
        ->and($taking->refresh()->status)->toBe(SandboxSnapshot::CREATING)
        ->and($deletedGone->refresh()->delete_confirmed_at)->not->toBeNull()
        ->and($deleted->refresh()->delete_confirmed_at)->toBeNull()
        ->and(collect($this->agents->dispatched('sandbox.snapshot.delete'))->pluck('payload.snapshot_id')->all())->toContain($deleted->id, $orphan);

    // A v1 agent reports no snapshots: they are left alone.
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $this->agents->succeed($this->agents->last('sandbox.inventory')['handle'], ['sandboxes' => [['id' => $paused->id, 'status' => 'paused']]]);
    expect($kept->refresh()->status)->toBe(SandboxSnapshot::READY);
});

it('leaves a snapshot that became ready after the inventory was sent to the next inventory', function () {
    $sandbox = snap_sandbox();
    Sandbox::query()->update(['created_at' => now()->subMinutes(10)]);
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertCreated();
    $snapshot = SandboxSnapshot::query()->sole();
    $take = $this->agents->last('sandbox.snapshot')['handle'];

    // The inventory is sent, the snapshot finishes, then the (stale) inventory arrives without it.
    Str::createUlidsUsing(fn () => new Ulid(Ulid::generate(now()->subSeconds(5))));
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    Str::createUlidsNormally();
    $inventory = $this->agents->last('sandbox.inventory')['handle'];
    $this->agents->succeed($take, ['snapshot_id' => $snapshot->id, 'size_bytes' => 1, 'image_digest' => 'x', 'created_at' => now()->toIso8601String()]);
    $this->agents->succeed($inventory, ['sandboxes' => [['id' => $sandbox->id, 'status' => 'running']], 'snapshots' => []]);
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::READY);

    // The next one reports it: kept, nothing deleted.
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $this->agents->succeed($this->agents->last('sandbox.inventory')['handle'], ['sandboxes' => [['id' => $sandbox->id, 'status' => 'running']], 'snapshots' => [['snapshot_id' => $snapshot->id, 'size_bytes' => 1]]]);
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::READY)
        ->and($this->agents->dispatched('sandbox.snapshot.delete'))->toBe([]);
});

it('fails snapshots stuck being taken', function () {
    $snapshot = snap_snapshot(snap_sandbox(), ['status' => SandboxSnapshot::CREATING]);
    $snapshot->forceFill(['created_at' => now()->subMinutes(31)])->save();

    (new SweepSandboxes)->handle(app(KillSandbox::class));
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::FAILED);
});

it('removes a snapshot deleted while it was being taken once the agent has it', function () {
    $sandbox = snap_sandbox();
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/snapshots")->assertCreated();
    $snapshot = SandboxSnapshot::query()->sole();
    snap_api()->deleteJson("/api/v1/sandboxes/snapshots/{$snapshot->id}")->assertNoContent();

    $this->agents->succeed($this->agents->last('sandbox.snapshot')['handle'], ['snapshot_id' => $snapshot->id, 'size_bytes' => 1, 'image_digest' => 'x', 'created_at' => now()->toIso8601String()]);
    expect($snapshot->refresh()->status)->toBe(SandboxSnapshot::DELETED)
        ->and($this->agents->last('sandbox.snapshot.delete')['handle']->idempotencyKey)->toBe("sandbox.snapshot.delete:{$snapshot->id}:late");
});

// ---- database copy ---------------------------------------------------------------------------------------------

it('copies a database of an instance on the same server into the sandbox, for who may download its backups', function () {
    expect(DatabaseDirectory::BACKUP_DOWNLOAD_PERMISSION)->toBe(DatabasesPolicy::RESTORE);
    $sandbox = snap_sandbox();
    $instanceId = snap_databases($this->organization->id, $this->server->id);
    $this->agents->answer('sandbox.db.import', fn (array $payload) => ['path' => $payload['path'], 'size_bytes' => 4096]);

    // A "*" token is not enough: the ability must be granted by name.
    snap_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertForbidden();
    // A developer's own sandbox: still not without the permission (developers can't download backups).
    [$developer] = memberOf($this->organization, Role::Developer);
    $own = snap_sandbox(['name' => 'own', 'created_by_id' => $developer->id]);
    $this->withToken(snap_token($developer, $this->organization))
        ->postJson("/api/v1/sandboxes/{$own->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertForbidden()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'databases.restore'));
    expect($this->agents->dispatched())->toBe([]);

    $restorer = snap_token($this->user, $this->organization, ['sandboxes.view', 'sandboxes.use', 'databases.restore']);
    $this->withToken($restorer)->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertOk()
        ->assertExactJson(['data' => ['path' => '/workspace/db/shop.dump', 'size_bytes' => 4096]]);
    $import = $this->agents->last('sandbox.db.import');
    expect(snap_schema_errors($import))->toBe([])
        ->and($import['payload'])->toBe(['id' => $sandbox->id, 'instance' => $instanceId, 'engine' => 'postgres', 'database' => 'shop', 'path' => '/workspace/db/shop.dump'])
        ->and(SandboxExec::query()->sole()->cmd)->toBe('db.import pg/shop → /workspace/db/shop.dump')
        ->and(AuditEntry::query()->where('action', 'sandbox.db_imported')->exists())->toBeTrue();

    $this->withToken($restorer)->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => $instanceId, 'database' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('database');
    $this->withToken($restorer)->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'cache', 'database' => 'shop'])->assertUnprocessable()->assertJsonValidationErrors('instance');
    $this->withToken($restorer)->postJson("/api/v1/sandboxes/{$sandbox->id}/db", ['instance' => 'missing', 'database' => 'shop'])->assertUnprocessable()->assertJsonValidationErrors('instance');

    $elsewhere = snap_sandbox(['name' => 'elsewhere', 'server_id' => snap_server($this->organization, 'other')->id]);
    $this->withToken($restorer)->postJson("/api/v1/sandboxes/{$elsewhere->id}/db", ['instance' => 'pg', 'database' => 'shop'])->assertUnprocessable()
        ->assertJsonValidationErrors(['instance' => 'on its own server']);
});

it('copies a database in on create once the sandbox runs, placed on the instance\'s server', function () {
    snap_server($this->organization, 'roomy');
    $instanceId = snap_databases($this->organization->id, $this->server->id);
    $restorer = snap_token($this->user, $this->organization, ['sandboxes.view', 'sandboxes.use', 'databases.restore']);

    snap_api()->postJson('/api/v1/sandboxes', ['db' => ['instance' => 'pg', 'database' => 'shop']])->assertForbidden();
    auth()->forgetGuards(); // the same user with another token
    $this->withToken($restorer)->postJson('/api/v1/sandboxes', ['db' => ['instance' => 'pg', 'database' => 'shop'], 'server' => 'roomy'])->assertUnprocessable()
        ->assertJsonValidationErrors('db.instance');
    $this->withToken($restorer)->postJson('/api/v1/sandboxes', ['db' => ['instance' => 'pg', 'database' => 'nope']])->assertUnprocessable()
        ->assertJsonValidationErrors('db.database');

    $this->withToken($restorer)->postJson('/api/v1/sandboxes', ['name' => 'with-db', 'db' => ['instance' => 'pg', 'database' => 'shop']])->assertCreated()
        ->assertJsonPath('data.server.name', 'main-1');
    $sandbox = Sandbox::query()->sole();
    expect($this->agents->dispatched('sandbox.db.import'))->toBe([]);

    $this->agents->succeed($this->agents->last('sandbox.create')['handle'], snap_running());
    $import = $this->agents->last('sandbox.db.import');
    expect(snap_schema_errors($import))->toBe([])
        ->and($import['payload'])->toBe(['id' => $sandbox->id, 'instance' => $instanceId, 'engine' => 'postgres', 'database' => 'shop', 'path' => '/workspace/db/shop.dump'])
        ->and($import['handle']->idempotencyKey)->toBe("sandbox.db.import:{$sandbox->id}:create");

    $this->agents->succeed($import['handle'], ['path' => '/workspace/db/shop.dump', 'size_bytes' => 10]);
    expect(SandboxExec::query()->sole())->cmd->toBe('db.import pg/shop → /workspace/db/shop.dump')->exit_code->toBe(0);
});

// ---- dashboard -------------------------------------------------------------------------------------------------

it('renders snapshots on the dashboard pages', function () {
    $this->actingAs($this->user);
    $sandbox = snap_sandbox();
    snap_snapshot($sandbox);
    snap_snapshot($sandbox, ['name' => 'old', 'status' => SandboxSnapshot::DELETED]);

    $this->get('/sandboxes')->assertOk()->assertInertia(fn ($page) => $page->component('Sandboxes/Index', false)
        ->has('snapshots', 1)->where('snapshots.0.name', 'node-ready'));
    $this->get("/sandboxes/{$sandbox->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Sandboxes/Show', false)
        ->has('snapshots', 1)->where('can.operate', true)->where('can.db', true));
    $this->postJson("/sandboxes/{$sandbox->id}/snapshots", ['name' => 'from-ui'])->assertCreated();
});
