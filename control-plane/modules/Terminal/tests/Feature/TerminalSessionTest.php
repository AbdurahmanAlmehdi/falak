<?php

use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Events\ServerDeleted;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalFrame;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Events\TerminalOutput;
use Falak\Terminal\Events\TerminalSessionClosed;
use Falak\Terminal\Events\TerminalSessionOpened;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization);
});

it('opens a session with a schema-valid terminal.open command', function () {
    Event::fake([TerminalSessionOpened::class]);

    $session = terminal_open($this->server, ['user' => 'falak', 'cols' => 120, 'rows' => 40]);

    $command = terminal_open_command($this->agents);
    expect($command['payload'])->toBe([
        'session_id' => $session->id,
        'user' => 'falak',
        'shell' => '/bin/bash',
        'cols' => 120,
        'rows' => 40,
        'idle_timeout_s' => 900,
        'env' => ['TERM' => 'xterm-256color'],
    ])
        ->and($command['timeout'])->toBe(3600)
        ->and($command['handle']->idempotencyKey)->toBe("terminal.open:{$session->id}")
        ->and($session->status)->toBe(SessionStatus::Opening)
        ->and($session->command_id)->toBe($command['handle']->id)
        ->and($session->user_id)->toBe($this->user->id)
        ->and(AuditEntry::query()->where('action', 'terminal.session_opened')->where('subject_id', $session->id)->exists())->toBeTrue();

    Event::assertDispatched(TerminalSessionOpened::class, fn ($e) => $e->sessionId === $session->id && $e->unixUser === 'falak');
});

it('defaults to the configured user and redirects to the session page', function () {
    $this->post("/terminal/servers/{$this->server->id}/sessions")
        ->assertRedirect(route('terminal.sessions.show', TerminalSession::query()->firstOrFail()));

    expect(terminal_open_command($this->agents)['payload']['user'])->toBe('root');
});

it('rejects inactive servers, disconnected agents and invalid users', function () {
    $pending = terminal_server($this->organization, ['name' => 'new', 'status' => ServerStatus::Provisioning]);
    $this->post("/terminal/servers/{$pending->id}/sessions")->assertSessionHasErrors('server');

    $this->agents->unavailable($this->server->id);
    $this->post("/terminal/servers/{$this->server->id}/sessions")->assertSessionHasErrors('server');

    $this->agents->available($this->server->id);
    $this->post("/terminal/servers/{$this->server->id}/sessions", ['user' => 'Root; rm'])->assertSessionHasErrors('user');

    expect(TerminalSession::query()->count())->toBe(0);
});

it('hides servers of other organizations', function () {
    [, $other] = memberOf();
    $foreign = terminal_server($other, ['name' => 'foreign']);

    $this->post("/terminal/servers/{$foreign->id}/sessions")->assertNotFound();
});

it('only lets admins and owners open terminals', function (Role $role, int $status) {
    [, $organization] = actingAsMember($role);
    $server = terminal_server($organization, ['name' => 'x']);

    $response = $this->post("/terminal/servers/{$server->id}/sessions");

    $status === 302 ? $response->assertRedirect() : $response->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 302],
    'developer' => [Role::Developer, 403],
    'viewer' => [Role::Viewer, 403],
]);

it('goes live on output, records frames once and relays them', function () {
    Event::fake([TerminalOutput::class]);
    $session = terminal_open($this->server);
    $handle = terminal_open_command($this->agents)['handle'];

    $this->agents->emit($handle, [terminal_b64("hello\r\n"), terminal_b64('$ ')]);

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Open)
        ->and($session->started_at)->not->toBeNull()
        ->and($session->recording_bytes)->toBe(9)
        ->and(TerminalFrame::query()->where('session_id', $session->id)->pluck('fleet_seq')->all())->toBe([1, 2]);

    Event::assertDispatchedTimes(TerminalOutput::class, 2);
    Event::assertDispatched(TerminalOutput::class, fn (TerminalOutput $e) => $e->seq === 1 && base64_decode($e->data) === "hello\r\n" && $e->broadcastOn()->name === "presence-terminal.sessions.{$session->id}.0");
});

it('ignores redelivered output events', function () {
    Event::fake([TerminalOutput::class]);
    $session = terminal_open($this->server);
    $handle = terminal_open_command($this->agents)['handle'];

    $event = new CommandOutputReceived($handle->id, $this->server->id, 'running', [
        ['seq' => 5, 'kind' => 'output', 'stream' => 'stdout', 'data' => terminal_b64('abc'), 'progress' => null, 'at' => now()->toIso8601ZuluString()],
    ]);
    event($event);
    event($event);

    expect(TerminalFrame::query()->where('session_id', $session->id)->count())->toBe(1)
        ->and($session->refresh()->recording_bytes)->toBe(3);
    Event::assertDispatchedTimes(TerminalOutput::class, 1);
});

it('splits large output into independently decodable broadcast parts', function () {
    config(['terminal.broadcast_chunk_bytes' => 400]);
    Event::fake([TerminalOutput::class]);
    terminal_open($this->server);
    $handle = terminal_open_command($this->agents)['handle'];
    $payload = random_bytes(1000);

    $this->agents->emit($handle, terminal_b64($payload));

    $parts = [];
    Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $e) use (&$parts) {
        expect(strlen($e->data))->toBeLessThanOrEqual(400)->and($e->parts)->toBe(4);
        $parts[$e->part] = base64_decode($e->data, true);

        return true;
    });
    ksort($parts);

    expect(implode('', $parts))->toBe($payload);
});

it('ignores output of unrelated commands', function () {
    Event::fake([TerminalOutput::class]);
    $handle = $this->agents->dispatch($this->server->id, 'system.exec', ['script' => 'uptime']);

    $this->agents->emit($handle, "up 3 days\n");

    expect(TerminalFrame::query()->count())->toBe(0);
    Event::assertNotDispatched(TerminalOutput::class);
});

it('closes with the reason the agent reports', function (string $reason, ?int $exitCode) {
    Event::fake([TerminalSessionClosed::class]);
    $session = terminal_open($this->server);
    $handle = terminal_open_command($this->agents)['handle'];
    $this->agents->started($handle);

    $this->agents->succeed($handle, array_filter(['reason' => $reason, 'exit_code' => $exitCode], fn ($v) => $v !== null));

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Closed)
        ->and($session->close_reason)->toBe($reason)
        ->and($session->exit_code)->toBe($exitCode)
        ->and($session->closed_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'terminal.session_closed')->where('subject_id', $session->id)->value('context'))->toMatchArray(['reason' => $reason]);

    Event::assertDispatched(TerminalSessionClosed::class, fn ($e) => $e->sessionId === $session->id && $e->reason === $reason);
})->with([
    'shell exited' => ['exited', 0],
    'idle' => ['idle', null],
    'timeout' => ['timeout', null],
]);

it('treats a non-zero shell exit as a normal close and start failures as failed', function () {
    $session = terminal_open($this->server);
    $this->agents->fail(terminal_open_command($this->agents)['handle'], null, 130, 'failed', ['reason' => 'exited', 'exit_code' => 130]);

    expect($session->refresh()->status)->toBe(SessionStatus::Closed)->and($session->exit_code)->toBe(130);

    $broken = terminal_open($this->server);
    $this->agents->fail(terminal_open_command($this->agents)['handle'], 'user "nobody2" does not exist');

    expect($broken->refresh()->status)->toBe(SessionStatus::Failed)
        ->and($broken->close_reason)->toBe('failed')
        ->and($broken->error)->toContain('does not exist');
});

it('closes sessions on request and keeps the reason when the agent confirms', function () {
    $session = terminal_open($this->server);
    $handle = terminal_open_command($this->agents)['handle'];
    $this->agents->started($handle);

    $this->delete("/terminal/sessions/{$session->id}")->assertRedirect();

    $close = $this->agents->last('terminal.close');
    expect($close['payload'])->toBe(['session_id' => $session->id])
        ->and($close['handle']->idempotencyKey)->toBe("terminal.close:{$session->id}")
        ->and($session->refresh()->status)->toBe(SessionStatus::Closed)
        ->and($session->close_reason)->toBe('closed');

    $this->agents->succeed($handle, ['reason' => 'closed']);
    expect($session->refresh()->close_reason)->toBe('closed')
        ->and(AuditEntry::query()->where('action', 'terminal.session_closed')->count())->toBe(1);
});

it('sweeps idle, overlong and never-opened sessions', function () {
    $idle = terminal_open($this->server);
    $this->agents->started(terminal_open_command($this->agents)['handle']);
    $busy = terminal_open($this->server);
    $this->agents->started(terminal_open_command($this->agents)['handle']);
    $stuck = terminal_open($this->server);
    $old = terminal_open($this->server);
    $this->agents->started(terminal_open_command($this->agents)['handle']);

    $idle->forceFill(['last_activity_at' => now()->subSeconds(900 + 61)])->save();
    $busy->forceFill(['last_activity_at' => now()->subSeconds(30)])->save();
    $stuck->forceFill(['created_at' => now()->subMinutes(3), 'last_activity_at' => now()])->save();
    $old->forceFill(['created_at' => now()->subSeconds(3600 + 61), 'last_activity_at' => now()])->save();

    app()->call([new SweepTerminalSessions, 'handle']);

    expect($idle->refresh()->close_reason)->toBe('idle')
        ->and($busy->refresh()->status)->toBe(SessionStatus::Open)
        ->and($stuck->refresh()->status)->toBe(SessionStatus::Failed)
        ->and($old->refresh()->close_reason)->toBe('timeout')
        ->and(collect($this->agents->dispatched('terminal.close'))->pluck('payload.session_id')->sort()->values()->all())
        ->toBe(collect([$idle->id, $stuck->id, $old->id])->sort()->values()->all());
});

it('closes live sessions when their server is deleted and keeps recordings', function () {
    $session = terminal_open($this->server);
    $this->agents->emit(terminal_open_command($this->agents)['handle'], terminal_b64('x'));

    ServerDeleted::dispatch($this->server->id, $this->organization->id, 'web', 'web-1');

    expect($session->refresh()->close_reason)->toBe('server_deleted')
        ->and(TerminalFrame::query()->where('session_id', $session->id)->count())->toBe(1);
});

it('lists live sessions and recordings on the index page', function () {
    $session = terminal_open($this->server);
    $closed = terminal_open($this->server);
    $this->agents->succeed(terminal_open_command($this->agents)['handle'], ['reason' => 'exited']);

    $this->get('/terminal')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Terminal/Index', false)
        ->where('sessions.0.id', $session->id)
        ->where('recordings.0.id', $closed->id)
        ->where('servers.0.id', $this->server->id)
        ->where('can.open', true));
});

it('forbids the index to members without terminal permissions', function () {
    actingAsMember(Role::Developer);

    $this->get('/terminal')->assertForbidden();
});
