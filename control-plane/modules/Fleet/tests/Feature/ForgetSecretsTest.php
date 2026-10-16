<?php

use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($organization->id, $this->serverId);
    $this->headers = fleet_mtls($this->enrolled['fingerprint']);
    $this->gateway = app(AgentGateway::class);
});

it('forgets payload secrets only once the command is terminal', function () {
    $key = base64_encode(random_bytes(32));
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'true', 'env' => ['BACKUP_KEY' => $key], 'mask' => ['BACKUP_KEY']]);

    // Still deliverable: kept.
    expect($this->gateway->forgetSecrets($handle, ['env.BACKUP_KEY']))->toBeFalse()
        ->and(Command::query()->findOrFail($handle->id)->payload)->toContain($key);

    $this->getJson('/agent/v1/commands?wait=0', $this->headers);
    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        ['command_id' => $handle->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
    ]))->assertNoContent();

    expect($this->gateway->forgetSecrets($handle, ['env.BACKUP_KEY', 'not.there']))->toBeTrue();
    $payload = json_decode(Command::query()->findOrFail($handle->id)->payload, true);
    expect($payload['env'])->toBe(['BACKUP_KEY' => '[forgotten]'])
        ->and($payload['script'])->toBe('true')
        ->and(json_encode(DB::table('fleet_commands')->get()))->not->toContain($key);
});

it('sweeps the secrets its module did not forget: settled commands, and commands stuck past their timeout', function () {
    $key = base64_encode(random_bytes(32));
    $payload = fn (string $id) => ['instance' => '01hzyinst00000000000000001', 'engine' => 'postgres', 'database' => 'app',
        'encryption' => ['mode' => 'cp', 'key_id' => $id, 'key' => $key], 'destination' => ['kind' => 'presigned_url', 'url' => 'https://s3.example.com/k']];
    $settled = $this->gateway->dispatch($this->serverId, 'db.backup', $payload('01hzybackup000000000000001'), 600);
    $stuck = $this->gateway->dispatch($this->serverId, 'db.backup', $payload('01hzybackup000000000000002'), 60);
    $recent = $this->gateway->dispatch($this->serverId, 'db.backup', $payload('01hzybackup000000000000003'), 600);
    Command::query()->whereKey($settled->id)->update(['status' => CommandStatus::Succeeded->value, 'finished_at' => now()]);
    Command::query()->whereKey($recent->id)->update(['status' => CommandStatus::Succeeded->value, 'finished_at' => now()]);

    // Running commands keep them; so does a command settled a moment ago (its listener may still forget them).
    $this->travel(5)->minutes();
    Command::query()->whereKey($recent->id)->update(['updated_at' => now()]);
    $this->travel(8)->minutes();
    Command::query()->whereKey($stuck->id)->update(['status' => CommandStatus::Running->value]);
    SweepFleet::dispatchSync();

    $key_of = fn ($handle) => json_decode(Command::query()->findOrFail($handle->id)->payload, true)['encryption']['key'];
    expect($key_of($settled))->toBe('[forgotten]')
        ->and($key_of($recent))->toBe($key)
        ->and(Command::query()->findOrFail($settled->id)->secrets_forgotten_at)->not->toBeNull();

    // The stuck one: forgotten once its timeout plus the delay has passed, even though it never settled.
    $this->travel(20)->minutes();
    Command::query()->whereKey($stuck->id)->update(['status' => CommandStatus::Running->value]);
    SweepFleet::dispatchSync();
    expect($key_of($stuck))->toBe('[forgotten]')
        ->and(json_encode(DB::table('fleet_commands')->whereIn('id', [$settled->id, $stuck->id])->get()))->not->toContain($key);
});

it('hands an x-falak-forget-result command its result once, then drops it; the sweep drops what nobody awaited', function () {
    $output = base64_encode('s3cret-output');
    $payload = ['id' => '01hzysbx000000000000000001', 'cmd' => ['cat', '/run/secret'], 'timeout_s' => 5];
    $awaited = $this->gateway->dispatch($this->serverId, 'sandbox.exec', $payload);
    $orphan = $this->gateway->dispatch($this->serverId, 'sandbox.exec', $payload);
    $this->getJson('/agent/v1/commands?wait=0', $this->headers);

    foreach ([$awaited, $orphan] as $handle) {
        $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
            ['command_id' => $handle->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString(),
                'result' => ['exit_code' => 0, 'stdout' => $output, 'stderr' => '', 'truncated' => false, 'timed_out' => false, 'duration_ms' => 3]],
        ]))->assertNoContent();
    }

    expect($this->gateway->await($awaited, 1)->result['stdout'])->toBe($output)
        ->and(Command::query()->findOrFail($awaited->id)->result)->toBeNull()
        ->and(DB::table('fleet_command_events')->where('command_id', $awaited->id)->whereNotNull('result')->exists())->toBeFalse()
        ->and(Command::query()->findOrFail($orphan->id)->result['stdout'])->toBe($output);

    $this->travel(11)->minutes();
    SweepFleet::dispatchSync();
    expect(json_encode(DB::table('fleet_commands')->get()).json_encode(DB::table('fleet_command_events')->get()))->not->toContain($output);
});
