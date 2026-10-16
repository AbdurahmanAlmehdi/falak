<?php

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Data\CommandOutput;
use Falak\Fleet\Contracts\Data\CommandResult;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Sandboxes\Application\Actions\CreateSandbox;
use Falak\Sandboxes\Application\Actions\ExecInSandbox;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Jobs\SweepSandboxes;
use Falak\Sandboxes\Application\Jobs\SyncSandboxes;
use Falak\Sandboxes\Application\Listeners\HandleCommandOutcome;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = sbx_server($this->organization, 'main-1');
    $this->token = sbx_token($this->user, $this->organization);
});

/**
 * An active app server whose agent reports $features.
 *
 * @param  list<string>  $features
 */
function sbx_server(Organization $organization, string $name, array $features = ['sandbox.v1', 'terminal.v2'], int $freeMb = 4096, array $attributes = []): Server
{
    $server = Server::factory()->create(['organization_id' => $organization->id, 'name' => $name, 'type' => ServerType::App, ...$attributes]);
    Agent::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'facts' => ['features' => $features, 'memory_bytes' => 8192 * 1024 ** 2, 'cpus' => 4],
        'metrics' => ['memory_used_bytes' => (8192 - $freeMb) * 1024 ** 2],
    ]);

    return $server;
}

/** @param  list<string>  $abilities */
function sbx_token(User $user, Organization $organization, array $abilities = ['*']): string
{
    $token = app(CreateApiToken::class)($user, $organization->id, 'agent-bot', $abilities)->plainTextToken;
    auth()->forgetGuards();

    return $token;
}

function sbx_sandbox(Organization $organization, Server $server, array $attributes = []): Sandbox
{
    return Sandbox::query()->create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
        'name' => 'calm-otter',
        'image' => 'ubuntu:24.04',
        'status' => Sandbox::RUNNING,
        'cpus' => 1,
        'memory_mb' => 1024,
        'disk_mb' => 10240,
        'network' => 'internet',
        'allow_hosts' => [],
        'env' => ['API_KEY' => 'hunter2-secret'],
        'ttl_seconds' => 3600,
        'expires_at' => now()->addHour(),
        'created_by_type' => 'user',
        'created_by_id' => test()->user->id,
        'created_by_name' => test()->user->name,
        ...$attributes,
    ]);
}

/** @return array<string, list<string>> */
function sbx_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

function sbx_api(): TestCase
{
    return test()->withToken(test()->token);
}

// ---- create --------------------------------------------------------------------------------------------------

it('creates a sandbox with the defaults and runs it when sandbox.create succeeds', function () {
    $response = sbx_api()->postJson('/api/v1/sandboxes', ['env' => ['FOO' => 'bar']])->assertCreated()
        ->assertJsonPath('data.status', 'creating')
        ->assertJsonPath('data.image', 'ubuntu:24.04')
        ->assertJsonPath('data.server.name', 'main-1')
        ->assertJsonPath('data.cpus', 1)
        ->assertJsonPath('data.memory_mb', 1024)
        ->assertJsonPath('data.disk_mb', 10240)
        ->assertJsonPath('data.network', 'internet')
        ->assertJsonPath('data.ttl_seconds', 3600)
        ->assertJsonPath('data.created_by', ['type' => 'token', 'name' => 'agent-bot'])
        ->assertJsonPath('data.env_keys', ['FOO'])
        ->assertJsonMissingPath('data.env');

    expect($response->json('data.name'))->toMatch('/^[a-z]+-[a-z]+$/');
    $sandbox = Sandbox::query()->sole();
    $command = $this->agents->last('sandbox.create');

    expect(sbx_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['id' => $sandbox->id, 'image' => 'ubuntu:24.04', 'memory_mb' => 1024, 'disk_mb' => 10240, 'pids' => 1024,
            'network' => 'internet', 'env' => ['FOO' => 'bar'], 'expires_at' => $sandbox->expires_at->getTimestamp()])
        ->and($command['handle']->idempotencyKey)->toBe("sandbox.create:{$sandbox->id}")
        ->and(AuditEntry::query()->where('action', 'sandbox.created')->exists())->toBeTrue();

    $this->agents->succeed($command['handle'], ['status' => 'running', 'ip' => '10.88.0.5', 'image_digest' => 'sha256:abc', 'started_at' => now()->toIso8601String()]);

    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}")->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.ip', '10.88.0.5');
    sbx_api()->getJson('/api/v1/sandboxes/'.$sandbox->name)->assertOk()->assertJsonPath('data.id', $sandbox->id);
    sbx_api()->getJson('/api/v1/sandboxes')->assertOk()->assertJsonCount(1, 'data');
    sbx_api()->getJson('/api/v1/sandboxes?status=killed')->assertOk()->assertJsonCount(0, 'data');
});

it('waits for the sandbox with ?wait=1, and a failed create carries the agent error as its kill reason', function () {
    $this->agents->answer('sandbox.create', fn () => ['status' => 'running', 'ip' => '10.88.0.9', 'image_digest' => 'sha256:x', 'started_at' => now()->toIso8601String()]);

    sbx_api()->postJson('/api/v1/sandboxes?wait=1', ['name' => 'my-box', 'image' => 'node:22'])->assertCreated()
        ->assertJsonPath('data.name', 'my-box')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.ip', '10.88.0.9');

    $this->agents = FakeAgentGateway::install();
    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'broken'])->assertCreated();
    $this->agents->fail($this->agents->last('sandbox.create')['handle'], 'image not found: nope:1');

    sbx_api()->getJson('/api/v1/sandboxes/broken')->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.kill_reason', 'image not found: nope:1');
});

it('parses durations', function () {
    expect(CreateSandbox::seconds(90, 'ttl'))->toBe(90)
        ->and(CreateSandbox::seconds('120', 'ttl'))->toBe(120)
        ->and(CreateSandbox::seconds('30m', 'ttl'))->toBe(1800)
        ->and(CreateSandbox::seconds('2h', 'ttl'))->toBe(7200)
        ->and(CreateSandbox::seconds('1h30m', 'ttl'))->toBe(5400)
        ->and(CreateSandbox::seconds('45s', 'ttl'))->toBe(45)
        ->and(fn () => CreateSandbox::seconds('soon', 'ttl'))->toThrow(ValidationException::class)
        ->and(fn () => CreateSandbox::seconds('', 'ttl'))->toThrow(ValidationException::class);

    sbx_api()->postJson('/api/v1/sandboxes', ['ttl' => '2h', 'cpus' => 0.5, 'network' => 'allowlist', 'allow_hosts' => ['github.com', '*.npmjs.org']])->assertCreated()
        ->assertJsonPath('data.ttl_seconds', 7200)
        ->assertJsonPath('data.cpus', 0.5)
        ->assertJsonPath('data.allow_hosts', ['github.com', '*.npmjs.org']);
    expect(sbx_schema_errors($this->agents->last('sandbox.create')))->toBe([]);

    sbx_api()->postJson('/api/v1/sandboxes', ['ttl' => '25h'])->assertUnprocessable()->assertJsonValidationErrors('ttl');
    sbx_api()->postJson('/api/v1/sandboxes', ['ttl' => 'later'])->assertUnprocessable()->assertJsonValidationErrors('ttl');
    sbx_api()->postJson('/api/v1/sandboxes', ['network' => 'allowlist'])->assertUnprocessable()->assertJsonValidationErrors('allow_hosts');
    sbx_api()->postJson('/api/v1/sandboxes', ['env' => ['1BAD' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('env');
});

it('enforces quotas with clear messages', function () {
    config(['sandboxes.max_running' => 2, 'sandboxes.max_total_memory_mb' => 3000]);

    sbx_api()->postJson('/api/v1/sandboxes', ['cpus' => 8])->assertUnprocessable()->assertJsonValidationErrors(['cpus' => 'between 0.1 and 4']);
    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 9000])->assertUnprocessable()->assertJsonValidationErrors('memory_mb');
    sbx_api()->postJson('/api/v1/sandboxes', ['disk_mb' => 100])->assertUnprocessable()->assertJsonValidationErrors('disk_mb');

    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 2048])->assertCreated();
    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 2048])->assertUnprocessable()
        ->assertJsonValidationErrors(['memory_mb' => '3000 MB of memory in all; 2048 MB are in use']);
    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 512])->assertCreated();
    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 64])->assertUnprocessable()
        ->assertJsonValidationErrors(['sandbox' => 'already runs 2 of its 2 sandboxes']);

    // Within the quota but beyond the server (agent facts: 8 GB, 4 CPUs).
    config(['sandboxes.max_running' => 10, 'sandboxes.max_total_memory_mb' => 100000, 'sandboxes.max_memory_mb' => 100000]);
    sbx_api()->postJson('/api/v1/sandboxes', ['memory_mb' => 9000])->assertUnprocessable()->assertJsonValidationErrors(['memory_mb' => 'main-1 has']);
});

it('enforces the organization disk quota over its live sandboxes', function () {
    expect(config('sandboxes.max_total_disk_mb'))->toBe(102400);
    config(['sandboxes.max_total_disk_mb' => 25000]);
    sbx_sandbox($this->organization, $this->server, ['name' => 'gone', 'disk_mb' => 50000, 'status' => Sandbox::KILLED]);

    sbx_api()->postJson('/api/v1/sandboxes', ['disk_mb' => 20480])->assertCreated();
    sbx_api()->postJson('/api/v1/sandboxes', ['disk_mb' => 10240])->assertUnprocessable()
        ->assertJsonValidationErrors(['disk_mb' => '25000 MB of disk in all; 20480 MB are in use']);
    sbx_api()->postJson('/api/v1/sandboxes', ['disk_mb' => 4096])->assertCreated();
});

it('places sandboxes on the active app server with sandbox.v1, sandboxes on and the most free memory', function () {
    $roomy = sbx_server($this->organization, 'roomy', freeMb: 7000);
    sbx_server($this->organization, 'old-agent', ['terminal.v2'], freeMb: 8000);
    sbx_server($this->organization, 'opted-out', freeMb: 8000, attributes: ['sandboxes_enabled' => false]);
    sbx_server($this->organization, 'web', freeMb: 8000, attributes: ['type' => ServerType::Web]);

    sbx_api()->postJson('/api/v1/sandboxes')->assertCreated()->assertJsonPath('data.server.name', 'roomy');
    expect($this->agents->last('sandbox.create')['handle']->serverId)->toBe($roomy->id);

    sbx_api()->postJson('/api/v1/sandboxes', ['server' => 'main-1'])->assertCreated()->assertJsonPath('data.server.id', $this->server->id);
    sbx_api()->postJson('/api/v1/sandboxes', ['server' => $this->server->id])->assertCreated();
    sbx_api()->postJson('/api/v1/sandboxes', ['server' => 'old-agent'])->assertUnprocessable()->assertJsonValidationErrors(['server' => 'too old for sandboxes']);
    sbx_api()->postJson('/api/v1/sandboxes', ['server' => 'opted-out'])->assertUnprocessable()->assertJsonValidationErrors(['server' => 'turned off']);
    sbx_api()->postJson('/api/v1/sandboxes', ['server' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('server');

    Server::query()->whereIn('name', ['main-1', 'roomy'])->update(['sandboxes_enabled' => false]);
    sbx_api()->postJson('/api/v1/sandboxes')->assertUnprocessable()->assertJsonValidationErrors(['server' => 'No server can run sandboxes']);
});

it('checks permissions, token abilities and organizations', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $viewOnly = sbx_token($this->user, $this->organization, ['sandboxes.view']);

    $this->withToken($viewOnly)->getJson('/api/v1/sandboxes')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($viewOnly)->postJson('/api/v1/sandboxes')->assertForbidden();
    $this->withToken($viewOnly)->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertForbidden();
    $this->withToken($viewOnly)->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertForbidden();

    // A developer uses sandboxes and kills their own, not someone else's; a viewer only looks.
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->withToken(sbx_token($developer, $this->organization))->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertForbidden();
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->withToken(sbx_token($viewer, $this->organization))->postJson('/api/v1/sandboxes')->assertForbidden();
    [$admin] = memberOf($this->organization, Role::Admin);
    $this->withToken(sbx_token($admin, $this->organization))->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertNoContent();
});

it('hides sandboxes of other organizations', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $other = sbx_token(...memberOf());

    $this->withToken($other)->getJson("/api/v1/sandboxes/{$sandbox->id}")->assertNotFound();
    $this->withToken($other)->getJson('/api/v1/sandboxes')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($other)->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertNotFound();
    $this->withToken($other)->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertNotFound();
});

// ---- exec ------------------------------------------------------------------------------------------------------

it('runs commands, decodes their output and records a redacted exec row', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $this->agents->answer('sandbox.exec', fn (array $payload) => [
        'exit_code' => 3, 'stdout' => base64_encode("héllo\n\xff"), 'stderr' => base64_encode('warn'), 'truncated' => false, 'duration_ms' => 71, 'timed_out' => false,
    ]);

    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", [
        'cmd' => 'curl -H "Authorization: Bearer abcdefghijkl1234" https://user:pa55@example.com --password=hunter2 && echo $TOKEN',
        'cwd' => '/workspace', 'env' => ['TOKEN' => 'super-secret-value'], 'stdin' => 'input', 'timeout' => 30,
    ])->assertOk()
        ->assertJsonPath('data.exit_code', 3)
        ->assertJsonPath('data.stdout', "héllo\n?")
        ->assertJsonPath('data.stderr', 'warn')
        ->assertJsonPath('data.truncated', false)
        ->assertJsonPath('data.timed_out', false)
        ->assertJsonPath('data.duration_ms', 71);

    $command = $this->agents->last('sandbox.exec');
    expect(sbx_schema_errors($command))->toBe([])
        ->and($command['payload']['cmd'][0])->toBe('sh')
        ->and($command['payload']['cmd'][1])->toBe('-lc')
        ->and($command['payload']['stdin'])->toBe(base64_encode('input'))
        ->and($command['payload']['timeout_s'])->toBe(30)
        ->and($command['payload']['max_output'])->toBe(262144);

    $row = SandboxExec::query()->sole();
    expect($row->exit_code)->toBe(3)
        ->and($row->stdout_bytes)->toBe(strlen("héllo\n\xff"))
        ->and($row->cwd)->toBe('/workspace')
        ->and($row->actor)->toBe('agent-bot')
        ->and($row->actor_type)->toBe('token')
        ->and($row->cmd)->not->toContain('abcdefghijkl1234')
        ->and($row->cmd)->not->toContain('pa55')
        ->and($row->cmd)->not->toContain('hunter2')
        ->and($row->cmd)->toContain('curl');

    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec?raw=1", ['cmd' => ['ls', '-la']])->assertOk()
        ->assertJsonPath('data.stdout', base64_encode("héllo\n\xff"));
    expect($this->agents->last('sandbox.exec')['payload']['cmd'])->toBe(['ls', '-la']);

    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/execs")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.cmd', 'ls -la')
        ->assertJsonPath('data.0.actor', 'agent-bot');
});

it('redacts env values, tokens and secrets from commands', function () {
    expect(ExecInSandbox::redact('echo s3cr3tvalue', ['X' => 's3cr3tvalue']))->toBe('echo ***')
        ->and(ExecInSandbox::redact('git clone https://x:ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@github.com/a/b'))->not->toContain('ghp_')
        ->and(ExecInSandbox::redact('export GITHUB_TOKEN=abc123 && run'))->toBe('export GITHUB_TOKEN=*** && run')
        ->and(ExecInSandbox::redact('aws --secret-key wJalr run'))->toBe('aws --secret-key *** run')
        ->and(ExecInSandbox::redact('use sk-abcdefghijklmnopqrstuvwxyz now'))->toBe('use *** now')
        ->and(ExecInSandbox::redact('ls -la /tmp'))->toBe('ls -la /tmp');
});

it('answers 409 when the sandbox is not running and 504 when the agent does not answer', function () {
    $creating = sbx_sandbox($this->organization, $this->server, ['status' => Sandbox::CREATING]);
    sbx_api()->postJson("/api/v1/sandboxes/{$creating->id}/exec", ['cmd' => 'ls'])->assertStatus(409)->assertJsonPath('message', 'The sandbox is still starting.');

    $killed = sbx_sandbox($this->organization, $this->server, ['name' => 'gone', 'status' => Sandbox::KILLED]);
    sbx_api()->getJson("/api/v1/sandboxes/{$killed->id}/files?path=/etc/hosts")->assertStatus(409);

    $running = sbx_sandbox($this->organization, $this->server, ['name' => 'busy']);
    sbx_api()->postJson("/api/v1/sandboxes/{$running->id}/exec", ['cmd' => 'sleep 500', 'timeout' => 5])->assertStatus(504)
        ->assertJsonPath('message', 'The sandbox did not answer within 15 seconds.');
    expect($this->agents->last('sandbox.exec')['handle'])->not->toBeNull();

    sbx_api()->postJson("/api/v1/sandboxes/{$running->id}/exec", ['cmd' => 'ls', 'timeout' => 500])->assertUnprocessable()->assertJsonValidationErrors('timeout');
    sbx_api()->postJson("/api/v1/sandboxes/{$running->id}/exec", ['cmd' => ''])->assertUnprocessable()->assertJsonValidationErrors('cmd');

    $this->agents->unavailable($this->server->id);
    sbx_api()->postJson("/api/v1/sandboxes/{$running->id}/exec", ['cmd' => 'ls'])->assertStatus(503);
});

// ---- files -----------------------------------------------------------------------------------------------------

it('reads, lists, writes and deletes files', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $this->agents->answer('sandbox.files.read', fn () => ['content' => base64_encode("\x00bin"), 'size' => 4, 'truncated' => false, 'mode' => '0644']);
    $this->agents->answer('sandbox.files.list', fn () => ['entries' => [['name' => 'a.txt', 'type' => 'file', 'size' => 3, 'mode' => '0644', 'mtime' => 1]], 'truncated' => false]);
    $this->agents->answer('sandbox.files.write', fn (array $payload) => ['size' => strlen(base64_decode($payload['content']))]);
    $this->agents->answer('sandbox.files.delete', fn () => []);

    $read = sbx_api()->get("/api/v1/sandboxes/{$sandbox->id}/files?path=/workspace/bin")->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Falak-File-Size', '4');
    expect($read->getContent())->toBe("\x00bin")
        ->and($this->agents->last('sandbox.files.read')['payload'])->toBe(['id' => $sandbox->id, 'path' => '/workspace/bin', 'max_bytes' => 5242880]);

    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/workspace&list=1")->assertOk()
        ->assertJsonPath('data.entries.0.name', 'a.txt')
        ->assertJsonPath('data.truncated', false);

    sbx_api()->call('PUT', "/api/v1/sandboxes/{$sandbox->id}/files?path=/workspace/run.sh&mode=0755", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], "#!/bin/sh\necho hi\n")
        ->assertOk()->assertJsonPath('data.size', 18);
    $write = $this->agents->last('sandbox.files.write');
    expect(sbx_schema_errors($write))->toBe([])
        ->and($write['payload'])->toMatchArray(['path' => '/workspace/run.sh', 'mode' => '0755', 'content' => base64_encode("#!/bin/sh\necho hi\n")]);

    sbx_api()->deleteJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/workspace/old&recursive=1")->assertNoContent();
    expect($this->agents->last('sandbox.files.delete')['payload'])->toBe(['id' => $sandbox->id, 'path' => '/workspace/old', 'recursive' => true])
        ->and(SandboxExec::query()->pluck('cmd')->all())->toEqualCanonicalizing(['files.write /workspace/run.sh (18 bytes)', 'files.delete -r /workspace/old']);

    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=relative")->assertUnprocessable()->assertJsonValidationErrors('path');
    config(['sandboxes.max_file_bytes' => 4]);
    sbx_api()->call('PUT', "/api/v1/sandboxes/{$sandbox->id}/files?path=/big", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'too big')->assertStatus(413);
});

it('maps agent errors: a directory is 422, a missing file 404, anything else 502', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $failing = new SbxFailingGateway($this->agents);
    app()->instance(AgentGateway::class, $failing);

    $failing->errors['sandbox.files.read'] = 'is_directory: /workspace';
    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/workspace")->assertUnprocessable()->assertJsonPath('message', 'The path is a directory.');

    $failing->errors['sandbox.files.read'] = 'not_found: /nope';
    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/nope")->assertNotFound();

    $failing->errors['sandbox.files.list'] = 'not_directory: /etc/hosts';
    sbx_api()->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/etc/hosts&list=1")->assertUnprocessable()->assertJsonPath('message', 'The path is not a directory.');

    $failing->errors['sandbox.exec'] = "sandbox {$sandbox->id} not found";
    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertStatus(409);

    $failing->errors['sandbox.exec'] = 'runsc: container not running';
    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertStatus(502)->assertJsonPath('message', 'runsc: container not running');

    $failing->errors['sandbox.files.write'] = 'no space left on device';
    sbx_api()->call('PUT', "/api/v1/sandboxes/{$sandbox->id}/files?path=/big", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'data')->assertStatus(502);

    // Failed calls are in the audit trail too: no exit code, the error.
    expect(SandboxExec::query()->orderBy('id')->get(['cmd', 'exit_code', 'error'])->toArray())->toBe([
        ['cmd' => 'ls', 'exit_code' => null, 'error' => 'The sandbox is not running.'],
        ['cmd' => 'ls', 'exit_code' => null, 'error' => 'runsc: container not running'],
        ['cmd' => 'files.write /big (4 bytes)', 'exit_code' => null, 'error' => 'no space left on device'],
    ]);
});

it('answers 504 and cancels the command when the gateway gives up waiting, and ?wait=1 then answers the sandbox still creating', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $failing = new SbxFailingGateway($this->agents);
    app()->instance(AgentGateway::class, $failing);
    $failing->timeouts = ['sandbox.exec', 'sandbox.create'];

    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'sleep 1'])->assertStatus(504)
        ->assertJsonPath('message', 'The server did not answer in time.');
    expect($this->agents->status($this->agents->last('sandbox.exec')['handle'])->status->value)->toBe('cancelled')
        ->and(SandboxExec::query()->sole()->error)->toBe('The server did not answer in time.');

    sbx_api()->postJson('/api/v1/sandboxes?wait=1', ['name' => 'slow'])->assertCreated()->assertJsonPath('data.status', 'creating');
    expect($this->agents->status($this->agents->last('sandbox.create')['handle'])->status->value)->toBe('queued');
});

/** Commands of the types in $errors fail at once (what await() then returns). */
final class SbxFailingGateway implements AgentGateway
{
    /** @var array<string, string> */
    public array $errors = [];

    /** @var list<string> types whose await() gives up (as the real gateway past its deadline) */
    public array $timeouts = [];

    public function __construct(private readonly FakeAgentGateway $fake) {}

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        $handle = $this->fake->dispatch($serverId, $type, $payload, $timeout, $idempotencyKey);

        if (isset($this->errors[$type])) {
            $this->fake->fail($handle, $this->errors[$type]);
        }

        return $handle;
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        $result = $this->fake->await($command, $waitSeconds);

        if (in_array($result->type, $this->timeouts, true)) {
            throw CommandTimedOut::waiting($result->id, $waitSeconds);
        }

        return $result;
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        return $this->fake->status($command);
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        return $this->fake->output($command, $afterSeq);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        return $this->fake->cancel($command);
    }

    public function supports(string $type): bool
    {
        return $this->fake->supports($type);
    }

    public function forgetSecrets(CommandHandle|string $command, array $paths): bool
    {
        return $this->fake->forgetSecrets($command, $paths);
    }
}

// ---- kill, extend --------------------------------------------------------------------------------------------

it('kills a sandbox once, and killing it again does nothing', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);

    sbx_api()->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertNoContent();
    $kill = $this->agents->last('sandbox.kill');
    expect(sbx_schema_errors($kill))->toBe([])
        ->and($kill['payload'])->toBe(['id' => $sandbox->id, 'reason' => 'killed'])
        ->and($sandbox->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($sandbox->kill_reason)->toBe('killed')
        ->and($sandbox->killed_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'sandbox.killed')->count())->toBe(1);

    sbx_api()->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertNoContent();
    expect($this->agents->dispatched('sandbox.kill'))->toHaveCount(1)
        ->and(AuditEntry::query()->where('action', 'sandbox.killed')->count())->toBe(1);
    sbx_api()->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertStatus(409);

    // Names are free again once killed.
    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'calm-otter'])->assertCreated();
    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'calm-otter'])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('extends the time to live within max_ttl and tells the agent the new expiry with sandbox.extend', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $expires = $sandbox->expires_at->getTimestamp();
    $this->agents->answer('sandbox.extend', fn (array $payload) => ['expires_at' => $payload['expires_at']]);

    sbx_api()->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => '30m', 'name' => 'renamed'])->assertOk()
        ->assertJsonPath('data.name', 'renamed')
        ->assertJsonPath('data.ttl_seconds', 5400);
    expect($sandbox->refresh()->expires_at->getTimestamp())->toBe($expires + 1800)
        ->and($sandbox->agent_expires_at->getTimestamp())->toBe($expires + 1800);

    $extend = $this->agents->last('sandbox.extend');
    expect(sbx_schema_errors($extend))->toBe([])
        ->and($extend['payload'])->toBe(['id' => $sandbox->id, 'expires_at' => $expires + 1800])
        ->and($this->agents->dispatched('sandbox.create'))->toBe([]);

    // The agent can't be told: nothing changes.
    $this->agents->unavailable($this->server->id);
    sbx_api()->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => '30m'])->assertStatus(503);
    expect($sandbox->refresh()->expires_at->getTimestamp())->toBe($expires + 1800);
    $this->agents->available($this->server->id);

    sbx_api()->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => 86400])->assertUnprocessable()->assertJsonValidationErrors('extend');
    $sandbox->forceFill(['status' => Sandbox::KILLED])->save();
    sbx_api()->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => 60])->assertStatus(409);
});

// ---- background ------------------------------------------------------------------------------------------------

it('sweeps expired sandboxes and those stuck creating', function () {
    $expired = sbx_sandbox($this->organization, $this->server, ['expires_at' => now()->subMinute()]);
    $stuck = sbx_sandbox($this->organization, $this->server, ['name' => 'stuck', 'status' => Sandbox::CREATING]);
    $stuck->forceFill(['created_at' => now()->subMinutes(6)])->save();
    $fresh = sbx_sandbox($this->organization, $this->server, ['name' => 'fresh', 'status' => Sandbox::CREATING]);

    (new SweepSandboxes)->handle(app(KillSandbox::class));

    expect($expired->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($expired->kill_reason)->toBe('expired')
        ->and($stuck->refresh()->status)->toBe(Sandbox::FAILED)
        ->and($stuck->error)->toContain('did not start')
        ->and($fresh->refresh()->status)->toBe(Sandbox::CREATING)
        ->and(collect($this->agents->dispatched('sandbox.kill'))->pluck('payload.id')->all())->toEqualCanonicalizing([$expired->id, $stuck->id]);
});

it('marks sandboxes the agent reaped', function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    $server = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'enrolled', 'type' => ServerType::App]);
    $mtls = fleet_mtls(fleet_enroll($this->organization->id, $server->id, ['features' => ['sandbox.v1']])['fingerprint']);
    $sandbox = sbx_sandbox($this->organization, $server);
    $elsewhere = sbx_sandbox($this->organization, $this->server, ['name' => 'elsewhere']);

    $this->postJson('/agent/v1/requests/sandbox.reaped', ['id' => $sandbox->id, 'reason' => 'oom'], $mtls)->assertOk()->assertExactJson(['accepted' => true]);
    expect($sandbox->refresh()->status)->toBe(Sandbox::KILLED)->and($sandbox->kill_reason)->toBe('oom');

    // The agent retries until it gets a 2xx: a repeat changes nothing.
    $this->postJson('/agent/v1/requests/sandbox.reaped', ['id' => $sandbox->id, 'reason' => 'oom'], $mtls)->assertOk()->assertExactJson(['accepted' => true]);
    expect(AuditEntry::query()->where('action', 'sandbox.killed')->count())->toBe(1);

    // Another server's sandbox is not this agent's to report.
    $this->postJson('/agent/v1/requests/sandbox.reaped', ['id' => $elsewhere->id, 'reason' => 'expired'], $mtls)->assertOk()->assertExactJson(['accepted' => false]);
    expect($elsewhere->refresh()->status)->toBe(Sandbox::RUNNING);
    $this->postJson('/agent/v1/requests/sandbox.reaped', ['id' => $sandbox->id, 'reason' => 'bored'], $mtls)->assertUnprocessable();
});

it('reconciles with sandbox.inventory', function () {
    $lost = sbx_sandbox($this->organization, $this->server, ['name' => 'lost']);
    $lost->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $young = sbx_sandbox($this->organization, $this->server, ['name' => 'young']);
    $stopped = sbx_sandbox($this->organization, $this->server, ['name' => 'stopped']);
    $stopped->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $killed = sbx_sandbox($this->organization, $this->server, ['name' => 'dead', 'status' => Sandbox::KILLED, 'kill_reason' => 'killed']);
    $orphan = strtolower((string) Str::ulid());

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $inventory = $this->agents->last('sandbox.inventory');
    expect(sbx_schema_errors($inventory))->toBe([]);

    $this->agents->succeed($inventory['handle'], ['sandboxes' => [
        ['id' => $stopped->id, 'status' => 'stopped', 'oom_killed' => true],
        ['id' => $killed->id, 'status' => 'running'],
        ['id' => $orphan, 'status' => 'running'],
    ]]);

    expect($lost->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($lost->kill_reason)->toBe('lost')
        ->and($young->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($stopped->refresh()->kill_reason)->toBe('oom')
        ->and(collect($this->agents->dispatched('sandbox.kill'))->pluck('payload.id')->all())->toEqualCanonicalizing([$stopped->id, $killed->id, $orphan]);
});

it('counts live sandboxes in the server capacity', function () {
    sbx_sandbox($this->organization, $this->server);
    sbx_sandbox($this->organization, $this->server, ['name' => 'dead', 'status' => Sandbox::KILLED]);
    $items = collect(app(CapacitySources::class)->all())->flatMap(fn ($source) => $source->onServer($this->organization->id, $this->server->id))
        ->filter(fn ($item) => $item->kind === 'sandbox');

    expect($items)->toHaveCount(1)->and($items->first()->memoryLimitMb)->toBe(1024);
});

// ---- console and dashboard ----------------------------------------------------------------------------------

it('opens a console in a running sandbox', function () {
    Http::fake(['relay:8080/*' => Http::response(null, 204)]);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'app.url' => 'https://panel.example.com', 'terminal.relay.enabled' => true]);
    [$developer] = memberOf($this->organization, Role::Developer);
    $sandbox = sbx_sandbox($this->organization, $this->server, ['created_by_id' => $developer->id]);

    // Someone else's sandbox: only with sandboxes.manage.
    [$other] = memberOf($this->organization, Role::Developer);
    $this->withToken(sbx_token($other, $this->organization))->postJson("/api/v1/sandboxes/{$sandbox->id}/console")->assertForbidden();
    expect($this->agents->dispatched('terminal.open'))->toBe([]);

    // sandboxes.use opens it (terminal.open is admin-only).
    $this->withToken(sbx_token($developer, $this->organization))->postJson("/api/v1/sandboxes/{$sandbox->name}/console")->assertCreated()
        ->assertJsonPath('data.target_type', 'sandbox')
        ->assertJsonPath('data.target_name', 'calm-otter')
        ->assertJsonPath('data.server_name', 'main-1')
        ->assertJsonPath('data.writable', true);

    $open = $this->agents->last('terminal.open');
    expect(sbx_schema_errors($open))->toBe([])
        ->and($open['payload']['sandbox'])->toBe(['id' => $sandbox->id])
        ->and($open['payload'])->not->toHaveKeys(['user', 'shell', 'container'])
        ->and(TerminalSession::query()->sole()->target_type)->toBe(TerminalSession::TARGET_SANDBOX);

    auth()->forgetGuards();
    $creating = sbx_sandbox($this->organization, $this->server, ['name' => 'starting', 'status' => Sandbox::CREATING]);
    sbx_api()->postJson("/api/v1/sandboxes/{$creating->id}/console")->assertStatus(409);
    $this->withToken(sbx_token(...memberOf()))->postJson("/api/v1/sandboxes/{$sandbox->id}/console")->assertNotFound();
});

it('opens the console from the dashboard', function () {
    $this->actingAs($this->user);
    $sandbox = sbx_sandbox($this->organization, $this->server);

    $this->post("/sandboxes/{$sandbox->id}/console")->assertRedirect();
    expect($this->agents->last('terminal.open')['payload']['sandbox'])->toBe(['id' => $sandbox->id]);
});

it('renders the dashboard pages', function () {
    $this->actingAs($this->user);
    $sandbox = sbx_sandbox($this->organization, $this->server);

    $this->get('/sandboxes')->assertOk()->assertInertia(fn ($page) => $page->component('Sandboxes/Index', false)
        ->has('sandboxes', 1)->where('servers.0.name', 'main-1')->where('can.use', true));
    $this->get("/sandboxes/{$sandbox->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Sandboxes/Show', false)
        ->where('sandbox.id', $sandbox->id)->where('can.kill', true));
    $this->postJson('/sandboxes', ['name' => 'from-ui'])->assertCreated()->assertJsonPath('data.name', 'from-ui');
});

it('lets servers opt out of sandboxes from their settings', function () {
    $this->actingAs($this->user);
    $this->patch("/servers/{$this->server->id}", ['sandboxes_enabled' => false])->assertSessionHasNoErrors();
    expect($this->server->refresh()->sandboxes_enabled)->toBeFalse();
});

// ---- security review fixes -----------------------------------------------------------------------------------

it('hands repo.secret to a sandbox only for who may reveal it, and never a sensitive secret', function () {
    app(CreateSecret::class)($this->organization->id, SecretScope::Organization, $this->organization->id, ['name' => 'GH_TOKEN', 'value' => 'ghp_clone', 'sensitive' => false], null);
    app(CreateSecret::class)($this->organization->id, SecretScope::Organization, $this->organization->id, ['name' => 'DB_PASS', 'value' => 'hunter2', 'sensitive' => true], null);
    $repo = ['url' => 'https://github.com/acme/shop', 'secret' => 'GH_TOKEN'];

    // A "*" token, or one for sandboxes only, is not enough: secrets.reveal must be granted by name.
    sbx_api()->postJson('/api/v1/sandboxes', ['repo' => $repo])->assertForbidden();
    $this->withToken(sbx_token($this->user, $this->organization, ['sandboxes.use']))->postJson('/api/v1/sandboxes', ['repo' => $repo])->assertForbidden();
    expect(Sandbox::query()->count())->toBe(0);

    $revealer = sbx_token($this->user, $this->organization, ['sandboxes.use', 'secrets.reveal']);
    $this->withToken($revealer)->postJson('/api/v1/sandboxes', ['repo' => $repo])->assertCreated();
    $create = $this->agents->last('sandbox.create');
    expect(sbx_schema_errors($create))->toBe([])
        ->and($create['payload']['repo'])->toBe(['url' => 'https://github.com/acme/shop', 'token' => 'ghp_clone']);

    $this->withToken($revealer)->postJson('/api/v1/sandboxes', ['repo' => [...$repo, 'secret' => 'DB_PASS']])->assertUnprocessable()
        ->assertJsonValidationErrors(['repo.secret' => 'sensitive']);
    expect(Sandbox::query()->count())->toBe(1);
});

it('lets a create that finishes after the sandbox already runs complete it, and undoes one killed meanwhile', function () {
    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'quick'])->assertCreated();
    $sandbox = Sandbox::query()->sole();
    HandleCommandOutcome::started($sandbox, []); // what ?wait=1 does when it sees the result first

    $this->agents->succeed($this->agents->last('sandbox.create')['handle'], ['status' => 'running', 'ip' => '10.88.0.7', 'image_digest' => 'sha256:d', 'started_at' => now()->toIso8601String()]);
    expect($sandbox->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($sandbox->ip)->toBe('10.88.0.7')
        ->and($sandbox->image_digest)->toBe('sha256:d')
        ->and($this->agents->dispatched('sandbox.kill'))->toBe([])
        ->and($this->agents->dispatched('sandbox.extend'))->toBe([]);

    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'late'])->assertCreated();
    $late = Sandbox::query()->where('name', 'late')->sole();
    sbx_api()->deleteJson('/api/v1/sandboxes/late')->assertNoContent();
    $this->agents->succeed($this->agents->last('sandbox.create')['handle'], ['status' => 'running', 'image_digest' => 'sha256:d', 'started_at' => now()->toIso8601String()]);
    expect($this->agents->last('sandbox.kill')['handle']->idempotencyKey)->toBe("sandbox.kill:{$late->id}:late");
});

it('leaves sandboxes still being created to their create when reconciling', function () {
    $creating = sbx_sandbox($this->organization, $this->server, ['name' => 'creating', 'status' => Sandbox::CREATING]);
    $starting = sbx_sandbox($this->organization, $this->server, ['name' => 'starting', 'status' => Sandbox::CREATING]);
    Sandbox::query()->whereKey([$creating->id, $starting->id])->update(['created_at' => now()->subMinutes(4)]);
    $young = sbx_sandbox($this->organization, $this->server, ['name' => 'young']);

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $this->agents->succeed($this->agents->last('sandbox.inventory')['handle'], ['sandboxes' => [
        ['id' => $creating->id, 'status' => 'creating'],
        ['id' => $starting->id, 'status' => 'stopped'],
        ['id' => $young->id, 'status' => 'stopped'],
    ]]);

    expect($creating->refresh()->status)->toBe(Sandbox::CREATING)
        ->and($starting->refresh()->status)->toBe(Sandbox::CREATING)
        ->and($young->refresh()->status)->toBe(Sandbox::RUNNING)
        ->and($this->agents->dispatched('sandbox.kill'))->toBe([]);
});

it('extends a sandbox still being created once its create succeeded', function () {
    sbx_api()->postJson('/api/v1/sandboxes', ['name' => 'slow'])->assertCreated();
    $sandbox = Sandbox::query()->sole();
    $expires = $sandbox->expires_at->getTimestamp();

    sbx_api()->patchJson('/api/v1/sandboxes/slow', ['extend' => '1h'])->assertOk()->assertJsonPath('data.ttl_seconds', 7200);
    expect($this->agents->dispatched('sandbox.extend'))->toBe([]);

    $this->agents->succeed($this->agents->last('sandbox.create')['handle'], ['status' => 'running', 'image_digest' => 'sha256:d', 'started_at' => now()->toIso8601String()]);
    $extend = $this->agents->last('sandbox.extend');
    expect(sbx_schema_errors($extend))->toBe([])
        ->and($extend['payload'])->toBe(['id' => $sandbox->id, 'expires_at' => $expires + 3600])
        ->and($sandbox->refresh()->agent_expires_at->getTimestamp())->toBe($expires);

    // agent_expires_at is what the agent answered it enforces.
    $this->agents->succeed($extend['handle'], ['expires_at' => $expires + 3600]);
    expect($sandbox->refresh()->agent_expires_at->getTimestamp())->toBe($expires + 3600);
});

it('sends the expiry when the create finished between reading the sandbox and saving its extension', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server, ['status' => Sandbox::CREATING]);
    $expires = $sandbox->expires_at->getTimestamp();
    $this->agents->answer('sandbox.extend', fn (array $payload) => ['expires_at' => $payload['expires_at']]);

    // The request reads it creating; its create succeeds right after (the row is running when the extend saves).
    $flipped = false;
    Sandbox::retrieved(function (Sandbox $model) use (&$flipped) {
        if (! $flipped && $model->status === Sandbox::CREATING) {
            $flipped = true;
            Sandbox::query()->whereKey($model->id)->update(['status' => Sandbox::RUNNING]);
        }
    });

    sbx_api()->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => '30m'])->assertOk()->assertJsonPath('data.status', 'running');
    $extend = $this->agents->last('sandbox.extend');
    expect($flipped)->toBeTrue()
        ->and($extend['payload'])->toBe(['id' => $sandbox->id, 'expires_at' => $expires + 1800])
        ->and($sandbox->refresh()->expires_at->getTimestamp())->toBe($expires + 1800);
});

it('sends sandbox.extend again from the sync while the agent enforces another expiry', function () {
    $behind = sbx_sandbox($this->organization, $this->server, ['name' => 'behind']);
    $behind->forceFill(['agent_expires_at' => $behind->expires_at->copy()->subMinutes(30)])->save();
    $synced = sbx_sandbox($this->organization, $this->server, ['name' => 'synced']);
    $synced->forceFill(['agent_expires_at' => $synced->expires_at])->save();
    sbx_sandbox($this->organization, $this->server, ['name' => 'creating', 'status' => Sandbox::CREATING]);

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $sent = $this->agents->dispatched('sandbox.extend');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['payload'])->toBe(['id' => $behind->id, 'expires_at' => $behind->expires_at->getTimestamp()])
        ->and(sbx_schema_errors($sent[0]))->toBe([]);

    // Unanswered: sent again by the next sync (a new slot); answered: not any more.
    $this->travel(5)->minutes();
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.extend'))->toHaveCount(2);
    $this->agents->succeed($this->agents->last('sandbox.extend')['handle'], ['expires_at' => $behind->expires_at->getTimestamp()]);
    $this->travel(5)->minutes();
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.extend'))->toHaveCount(2);
});

it('keeps names unique among live sandboxes and creates one sandbox at a time per organization', function () {
    sbx_sandbox($this->organization, $this->server, ['name' => 'dup']);
    sbx_sandbox($this->organization, $this->server, ['name' => 'dup', 'status' => Sandbox::KILLED]);
    sbx_sandbox($this->organization, $this->server, ['name' => 'dup', 'status' => Sandbox::FAILED]);
    expect(fn () => sbx_sandbox($this->organization, $this->server, ['name' => 'dup', 'status' => Sandbox::CREATING]))->toThrow(UniqueConstraintViolationException::class);

    // Another create holds the organization's lock: this one gives up rather than race its quota and name checks.
    config(['sandboxes.create_lock_wait' => 0]);
    $lock = Cache::lock("sandboxes:create:{$this->organization->id}", 60);
    $lock->get();
    sbx_api()->postJson('/api/v1/sandboxes')->assertStatus(429);
    $lock->release();
    sbx_api()->postJson('/api/v1/sandboxes')->assertCreated();
});

it('keeps exec, files, consoles, renames and extensions of a sandbox to its creator and sandboxes.manage', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    [$developer] = memberOf($this->organization, Role::Developer);
    $dev = sbx_token($developer, $this->organization);

    $this->withToken($dev)->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertForbidden();
    $this->withToken($dev)->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/etc/hosts")->assertForbidden();
    $this->withToken($dev)->getJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/&list=1")->assertForbidden();
    $this->withToken($dev)->call('PUT', "/api/v1/sandboxes/{$sandbox->id}/files?path=/x", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'x')->assertForbidden();
    $this->withToken($dev)->deleteJson("/api/v1/sandboxes/{$sandbox->id}/files?path=/x")->assertForbidden();
    $this->withToken($dev)->getJson("/api/v1/sandboxes/{$sandbox->id}")->assertOk();
    expect($this->agents->dispatched())->toBe([]);

    // Renaming and extending too.
    $this->withToken($dev)->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['name' => 'mine-now'])->assertForbidden();
    $this->withToken($dev)->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['extend' => '30m'])->assertForbidden();
    expect($sandbox->refresh()->name)->toBe('calm-otter');

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->withToken(sbx_token($admin, $this->organization))->patchJson("/api/v1/sandboxes/{$sandbox->id}", ['name' => 'by-admin'])->assertOk();
    $this->agents->answer('sandbox.exec', fn () => ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'truncated' => false, 'duration_ms' => 1, 'timed_out' => false]);
    $this->withToken(sbx_token($admin, $this->organization))->postJson("/api/v1/sandboxes/{$sandbox->id}/exec", ['cmd' => 'ls'])->assertOk();
});

it('refuses images with "..", a trailing dot or a local registry', function () {
    $refused = ['../etc', 'a..b/c', 'ubuntu.', 'localhost:5000/x', 'localhost/x', 'LOCALHOST/x', 'reg.localhost/x', '127.0.0.1:5000/x', '127.1/x',
        '10.0.0.5/x', '192.168.1.2:443/x', '169.254.169.254/x', '0x7f.1/x', '2130706433:80/x', str_repeat('a', 256)];

    foreach ($refused as $image) {
        sbx_api()->postJson('/api/v1/sandboxes', ['image' => $image])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    foreach (['ubuntu:24.04', 'ghcr.io/acme/app:1.2', 'node@sha256:abc', '8.8.8.8/x', 'registry.example.com:5000/team/app'] as $image) {
        sbx_api()->postJson('/api/v1/sandboxes', ['image' => $image])->assertCreated();
        expect(sbx_schema_errors($this->agents->last('sandbox.create')))->toBe([]);
    }
});

it('sends a kill again until the agent confirms it', function () {
    $sandbox = sbx_sandbox($this->organization, $this->server);
    $this->agents->unavailable($this->server->id);
    sbx_api()->deleteJson("/api/v1/sandboxes/{$sandbox->id}")->assertNoContent();
    expect($sandbox->refresh()->status)->toBe(Sandbox::KILLED)
        ->and($sandbox->kill_confirmed_at)->toBeNull()
        ->and($this->agents->dispatched('sandbox.kill'))->toBe([]);

    $this->agents->available($this->server->id);
    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    $kill = $this->agents->last('sandbox.kill');
    expect($kill['payload']['id'])->toBe($sandbox->id)
        ->and($this->agents->last('sandbox.inventory')['handle']->serverId)->toBe($this->server->id);

    $this->agents->succeed($kill['handle'], []);
    expect($sandbox->refresh()->kill_confirmed_at)->not->toBeNull();

    (new SyncSandboxes)->handle($this->agents, app(KillSandbox::class));
    expect($this->agents->dispatched('sandbox.kill'))->toHaveCount(1);
});
