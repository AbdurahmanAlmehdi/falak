<?php

use Falak\Fleet\Application\CommandLifecycle;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $this->organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    fleet_enroll($this->organization->id, $this->serverId);
    $this->gateway = app(AgentGateway::class);
});

it('leaves results that are forgotten once read (x-falak-forget-result) out of CommandFinished and CommandFailed', function () {
    Event::fake([CommandFinished::class, CommandFailed::class]);
    $id = str_repeat('a', 26);
    $read = $this->gateway->dispatch($this->serverId, 'sandbox.files.read', ['id' => $id, 'path' => '/big', 'max_bytes' => 5242880]);
    $exec = $this->gateway->dispatch($this->serverId, 'sandbox.exec', ['id' => $id, 'cmd' => ['false'], 'timeout_s' => 5]);
    $other = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);
    $content = ['content' => base64_encode(str_repeat('x', 100_000)), 'size' => 100_000, 'truncated' => false, 'mode' => '0644'];

    $lifecycle = app(CommandLifecycle::class);
    $lifecycle->finish(Command::query()->findOrFail($read->id), 0, $content, null);
    $lifecycle->finish(Command::query()->findOrFail($exec->id), 1, ['stdout' => 'c2VjcmV0'], 'exit status 1');
    $lifecycle->finish(Command::query()->findOrFail($other->id), 0, ['stdout' => 'ok'], null);

    Event::assertDispatched(CommandFinished::class, fn (CommandFinished $e) => $e->type === 'sandbox.files.read' && $e->result === null);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->type === 'sandbox.exec' && $e->result === null);
    Event::assertDispatched(CommandFinished::class, fn (CommandFinished $e) => $e->type === 'system.exec' && $e->result === ['stdout' => 'ok']);
    // The request waiting for it still reads it (once).
    expect($this->gateway->await($read, 1)->result)->toBe($content);
});

it('queues no listener for sandbox commands, and every queued command listener skips them', function () {
    Queue::fake();
    $finished = fn (string $type) => CommandFinished::dispatch((string) Str::ulid(), $this->organization->id, $this->serverId, $type, (string) Str::ulid(), 0, []);
    $failed = fn (string $type) => CommandFailed::dispatch((string) Str::ulid(), $this->organization->id, $this->serverId, $type, (string) Str::ulid(), 'failed', 'boom', 1);

    foreach (['sandbox.exec', 'sandbox.files.read', 'sandbox.create', 'sandbox.inventory'] as $type) {
        $finished($type);
        $failed($type);
    }
    Queue::assertNotPushed(CallQueuedListener::class);

    $finished('system.exec');
    Queue::assertPushed(CallQueuedListener::class);

    // A queued listener added later without the trait would queue a job per sandbox command again.
    $queued = [];
    foreach ([CommandFinished::class, CommandFailed::class] as $event) {
        foreach (app('events')->getRawListeners()[$event] ?? [] as $listener) {
            $class = is_array($listener) ? $listener[0] : (is_string($listener) ? explode('@', $listener)[0] : null);

            if (is_string($class) && is_subclass_of($class, ShouldQueue::class)) {
                $queued[$class] = method_exists($class, 'shouldQueue');
            }
        }
    }
    expect($queued)->not->toBeEmpty()->and(array_keys(array_filter($queued, fn (bool $skips) => ! $skips)))->toBe([]);
});
