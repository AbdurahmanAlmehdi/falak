<?php

use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Terminal\Application\Actions\RunNetTool;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Domain\Data\NetToolSpec;
use Falak\Terminal\Domain\Models\NetToolRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization, ['name' => 'main-1']);
    nettool_agent($this->server);
});

function nettool_agent(Server $server, bool $supported = true): void
{
    Agent::query()->where('server_id', $server->id)->delete();
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $server->organization_id, 'facts' => ['features' => $supported ? ['terminal.v2', 'net.tools'] : ['terminal.v2']]]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function nettool_site(Organization $organization, Server $server, array $attributes = []): Site
{
    $site = Site::query()->create([
        'organization_id' => $organization->id, 'name' => 'Shop', 'slug' => 'shop', 'runtime' => SiteRuntime::FrankenPhp, 'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel, 'php_version' => '8.4', 'web_directory' => 'public', 'unix_user' => 'shop', 'isolated' => true,
        'deploy_script' => '$FALAK_FETCH', 'laravel' => new LaravelSettings, ...$attributes,
    ]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

    return $site->load('targets');
}

/**
 * @return array<string, mixed>
 */
function nettool_payload(FakeAgentGateway $agents): array
{
    return $agents->last('net.tool')['payload'];
}

// ---- running ------------------------------------------------------------------------------------------------

it('runs a tool on a server: payload, run row, audit entry', function () {
    $response = $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'tcp', 'target' => 'db.internal', 'port' => 5432])->assertCreated();

    expect(nettool_payload($this->agents))->toBe(['tool' => 'tcp', 'target' => 'db.internal', 'port' => 5432, 'count' => 3, 'timeout_s' => 30])
        ->and($this->agents->last('net.tool')['timeout'])->toBe(45);

    $run = NetToolRun::query()->sole();
    expect($response->json('data'))->toMatchArray(['id' => $run->id, 'tool' => 'tcp', 'target_type' => 'server', 'server_name' => 'main-1', 'status' => 'running', 'command_id' => $run->command_id])
        ->and($run->input)->toBe(['target' => 'db.internal', 'port' => 5432, 'count' => 3, 'timeout_s' => 30]);

    $audit = AuditEntry::query()->where('action', 'network.tool_run')->sole();
    expect($audit->context)->toMatchArray(['tool' => 'tcp', 'target_type' => 'server', 'server_name' => 'main-1', 'target' => 'db.internal', 'port' => 5432]);
});

it('runs on the host for a native site and in the container for docker, compose and database targets', function () {
    $native = nettool_site($this->organization, $this->server);
    $this->postJson("/sites/{$native->id}/net-tools", ['tool' => 'dns', 'target' => 'example.com'])->assertCreated();
    expect(nettool_payload($this->agents))->not->toHaveKey('container')
        ->and(NetToolRun::query()->latest('id')->first()->only(['target_type', 'target_id', 'target_name']))->toBe(['target_type' => 'site', 'target_id' => $native->id, 'target_name' => 'Shop']);

    $docker = nettool_site($this->organization, $this->server, ['slug' => 'api', 'name' => 'API', 'runtime' => SiteRuntime::Docker, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null]);
    $this->postJson("/sites/{$docker->id}/net-tools", ['tool' => 'http', 'url' => 'https://example.com/health'])->assertCreated();
    NetToolRun::query()->update(['status' => NetToolRun::SUCCEEDED]); // at most two running per user
    expect(nettool_payload($this->agents)['container'])->toBe(['labels' => ['falak.site' => 'api']]);

    $compose = nettool_site($this->organization, $this->server, [
        'slug' => 'stack', 'name' => 'Stack', 'runtime' => SiteRuntime::Compose, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null,
        'compose_source' => ComposeSource::Repo, 'compose_snapshot' => "services:\n  web:\n    image: nginx\n  worker:\n    image: nginx\n",
    ]);
    $this->postJson("/sites/{$compose->id}/net-tools", ['tool' => 'ping', 'target' => '10.0.0.1'])->assertUnprocessable()->assertJsonValidationErrors('service');
    $this->postJson("/sites/{$compose->id}/net-tools", ['tool' => 'ping', 'target' => '10.0.0.1', 'service' => 'worker'])->assertCreated();
    expect(nettool_payload($this->agents))->toBe(['tool' => 'ping', 'target' => '10.0.0.1', 'count' => 4, 'timeout_s' => 30, 'container' => ['labels' => [
        'com.docker.compose.project' => 'stack', 'com.docker.compose.service' => 'worker',
    ]]]);

    $instance = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'main-db']);
    $this->postJson("/databases/instances/{$instance->id}/net-tools", ['tool' => 'traceroute', 'target' => '1.1.1.1'])->assertCreated();
    expect(nettool_payload($this->agents)['container'])->toBe(['name' => "falak-db-{$instance->id}"])
        ->and(NetToolRun::query()->latest('id')->first()->container)->toBe("docker:falak-db-{$instance->id}");
});

it('refuses functions, old agents and other organizations', function () {
    $fn = nettool_site($this->organization, $this->server, ['slug' => 'hello', 'runtime' => SiteRuntime::Function, 'build_mode' => BuildMode::Docker, 'framework' => Framework::Docker, 'php_version' => null]);
    $this->postJson("/sites/{$fn->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertUnprocessable()->assertJsonValidationErrors(['site' => 'Functions run on demand']);

    nettool_agent($this->server, supported: false);
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertJsonValidationErrors(['server' => 'too old for network tools']);

    [, $other] = memberOf(null, Role::Owner);
    $foreign = terminal_server($other, ['name' => 'theirs']);
    $this->postJson("/servers/{$foreign->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertNotFound();

    $this->agents->assertNothingDispatched('net.tool');
    expect(NetToolRun::query()->count())->toBe(0);
});

it('needs network.tools: admins yes, developers and viewers no (like terminal.open)', function (Role $role, int $status) {
    [, $organization] = actingAsMember($role);
    $server = terminal_server($organization, ['name' => 'x-1']);
    nettool_agent($server);

    $this->postJson("/servers/{$server->id}/net-tools", ['tool' => 'ping', 'target' => 'example.com'])->assertStatus($status);
    $this->postJson("/api/v1/servers/{$server->id}/net-tools", ['tool' => 'ping', 'target' => 'example.com'])->assertStatus($status === 201 ? 201 : 403);
})->with([
    'admin' => [Role::Admin, 201],
    'developer' => [Role::Developer, 403],
    'viewer' => [Role::Viewer, 403],
]);

it('validates each tool’s arguments like the agent', function (array $input, string $field) {
    $this->postJson("/servers/{$this->server->id}/net-tools", $input)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->agents->assertNothingDispatched('net.tool');
})->with([
    'unknown tool' => [['tool' => 'nmap', 'target' => 'a'], 'tool'],
    'option injection' => [['tool' => 'ping', 'target' => '-oProxyCommand=x'], 'target'],
    'shell' => [['tool' => 'traceroute', 'target' => 'a;reboot'], 'target'],
    'spaces' => [['tool' => 'ping', 'target' => 'a b'], 'target'],
    'count' => [['tool' => 'ping', 'target' => 'a', 'count' => 21], 'count'],
    'port' => [['tool' => 'tcp', 'target' => 'a', 'port' => 0], 'port'],
    'port missing' => [['tool' => 'tcp', 'target' => 'a'], 'port'],
    'hops' => [['tool' => 'traceroute', 'target' => 'a', 'max_hops' => 31], 'max_hops'],
    'timeout' => [['tool' => 'ping', 'target' => 'a', 'timeout_s' => 61], 'timeout_s'],
    'record type' => [['tool' => 'dns', 'target' => 'a', 'record_type' => 'ANY'], 'record_type'],
    'ptr' => [['tool' => 'dns', 'target' => 'example.com', 'record_type' => 'PTR'], 'target'],
    'nameserver' => [['tool' => 'dns', 'target' => 'a', 'nameserver' => 'dns.google'], 'nameserver'],
    'scheme' => [['tool' => 'http', 'url' => 'file:///etc/passwd'], 'url'],
    'credentials' => [['tool' => 'http', 'url' => 'https://u:p@a/'], 'url'],
    'header injection' => [['tool' => 'http', 'url' => "https://a/\r\nX: y"], 'url'],
    'method' => [['tool' => 'http', 'url' => 'https://a/', 'method' => 'POST'], 'method'],
    'connect_to' => [['tool' => 'http', 'url' => 'https://a/', 'connect_to' => 'b'], 'connect_to'],
    'foreign field' => [['tool' => 'ping', 'target' => 'a', 'port' => 80], 'port'],
    'insecure on dns' => [['tool' => 'dns', 'target' => 'a', 'insecure' => true], 'insecure'],
]);

it('builds payloads with defaults and only the tool’s fields', function () {
    expect(NetToolSpec::payload(['tool' => 'http', 'url' => 'https://example.com/', 'insecure' => '1', 'connect_to' => '203.0.113.10']))
        ->toBe(['tool' => 'http', 'url' => 'https://example.com/', 'method' => 'GET', 'connect_to' => '203.0.113.10', 'insecure' => true, 'timeout_s' => 30])
        ->and(NetToolSpec::payload(['tool' => 'dns', 'target' => '1.1.1.1', 'record_type' => 'ptr', 'insecure' => false]))
        ->toBe(['tool' => 'dns', 'target' => '1.1.1.1', 'record_type' => 'PTR', 'timeout_s' => 30])
        ->and(NetToolSpec::validHost(str_repeat('a', 64).'.com'))->toBeFalse()
        ->and(NetToolSpec::validHost('my_service.internal.'))->toBeTrue()
        ->and(fn () => NetToolSpec::payload(['tool' => 'ping', 'target' => 'a..b']))->toThrow(ValidationException::class);
});

it('caps concurrent runs per user and rate limits', function () {
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertStatus(429)->assertJson(['message' => 'Wait for your other network tools to finish.']);

    NetToolRun::query()->update(['status' => NetToolRun::SUCCEEDED]);
    for ($i = 0; $i < 7; $i++) {
        $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
        NetToolRun::query()->update(['status' => NetToolRun::SUCCEEDED]);
    }
    // 11th request in the minute (the refused one above counted too).
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertStatus(429);
});

it('refuses a run while another request of the user holds the cap lock', function () {
    $lock = Cache::lock(RunNetTool::lockKey($this->user->id), 10);
    expect($lock->get())->toBeTrue();

    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertStatus(429);
    $lock->release();
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    expect(NetToolRun::query()->count())->toBe(1);
});

it('settles a cancelled command as cancelled, not as a timeout', function () {
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'http', 'url' => 'https://a/'])->assertCreated();
    [$cancelled, $capped] = NetToolRun::query()->orderBy('created_at')->orderBy('id')->get()->all();

    $this->agents->fail($cancelled->command_id, 'cancelled', null, 'cancelled');
    $this->agents->fail($capped->command_id, 'http stopped: output limit reached (256 KiB)', 1);

    expect($cancelled->fresh()->status)->toBe('cancelled')
        ->and($capped->fresh()->only(['status', 'exit_code', 'error']))->toBe(['status' => 'failed', 'exit_code' => 1, 'error' => 'http stopped: output limit reached (256 KiB)']);
});

it('settles runs from the command outcome and shows their output', function () {
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'tcp', 'target' => 'a', 'port' => 1])->assertCreated();
    [$ping, $tcp] = NetToolRun::query()->orderBy('created_at')->orderBy('id')->get()->all();

    $this->agents->emit($ping->command_id, ["PING a\n", "1 packets transmitted\n"]);
    $this->agents->succeed($ping->command_id);
    $this->agents->fail($tcp->command_id, 'tcp stopped after 30s (timeout)', 124);

    expect($ping->fresh()->only(['status', 'exit_code']))->toBe(['status' => 'succeeded', 'exit_code' => 0])
        ->and($tcp->fresh()->only(['status', 'exit_code', 'error']))->toBe(['status' => 'timed_out', 'exit_code' => 124, 'error' => 'tcp stopped after 30s (timeout)']);

    $data = $this->getJson("/net-tools/runs/{$ping->id}")->assertOk()->json('data');
    expect(implode('', array_column($data['lines'], 'data')))->toBe("PING a\n1 packets transmitted\n")
        ->and($data['status'])->toBe('succeeded');

    $runs = $this->getJson("/net-tools/runs?target_type=server&target_id={$this->server->id}")->assertOk()->json('data');
    expect(array_column($runs, 'id'))->toEqualCanonicalizing([$ping->id, $tcp->id]);

    // Another organization's member never sees it.
    actingAsMember(Role::Owner);
    $this->getJson("/net-tools/runs/{$ping->id}")->assertNotFound();
});

it('prunes runs older than 30 days', function () {
    $this->postJson("/servers/{$this->server->id}/net-tools", ['tool' => 'ping', 'target' => 'a'])->assertCreated();
    NetToolRun::query()->update(['created_at' => now()->subDays(31)]);

    app()->call([new SweepTerminalSessions, 'handle']);

    expect(NetToolRun::query()->count())->toBe(0);
});
