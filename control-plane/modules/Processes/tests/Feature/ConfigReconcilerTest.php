<?php

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Contracts\Role;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Processes\Infrastructure\Config\ProcessesReconciler;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

// falak.yaml's workers, daemons and schedules (Processes' Reconcilable): current → desired → ChangeSet tables, apply,
// ownership, and the dashboard's read-only signal.

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->server = processes_server($this->organization->id);
    $this->site = processes_site($this->organization->id, [$this->server]);
    $project = Project::query()->where('organization_id', $this->organization->id)->where('is_default', true)->firstOrFail();
    $environment = $project->production();
    ConfigSource::query()->create([
        'organization_id' => $this->organization->id, 'project_id' => $project->id, 'source_connection_id' => strtolower((string) Str::ulid()),
        'repository' => 'acme/shop', 'path' => 'falak.yaml',
    ]);
    $this->scope = new ConfigScope($this->organization->id, $project->id, $environment->id, $environment->slug, true, str_repeat('a', 40),
        ['web' => ['kind' => 'site', 'ref_id' => $this->site->id]], [$this->server->name => $this->server->id], [$this->server->id]);
    $this->reconciler = app(ProcessesReconciler::class);
    $run = ConfigRun::query()->create([
        'organization_id' => $this->organization->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'environment' => $environment->slug,
        'commit' => str_repeat('a', 40), 'trigger' => 'push', 'status' => 'applying', 'file' => '',
    ]);
    $this->ctx = new ApplyContext($run->id, ApplyPhase::WithRelease, str_repeat('a', 40), unit: 'web');
});

/**
 * Plan (diff against today) then apply, as Config's phase B does.
 *
 * @param  array<string, mixed>  $web  the unit's sections
 * @return list<array{0: string, 1: string}> [op, path] of the actionable changes
 */
function processes_config_apply(array $web): array
{
    $r = test()->reconciler;
    $changes = $r->diff(test()->scope, ['units' => ['web' => ['type' => 'app', ...$web]]], $r->current(test()->scope));
    $r->apply(test()->scope, $changes, test()->ctx);

    return array_map(fn (Change $c) => [$c->op->value, $c->path], $changes->actionable());
}

it('adds a nullable name column to workers', function () {
    expect(Schema::hasColumn('processes_workers', 'name'))->toBeTrue();
});

it('reads today\'s processes in the file\'s shape (unnamed workers as worker-<n>)', function () {
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'queue' => 'high,default', 'processes' => 2, 'timeout' => 90, 'memory' => 1024, 'env' => ['B' => '2', 'A' => '1']]);
    Daemon::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'ws', 'command' => 'php artisan reverb:start', 'server_ids' => [$this->server->id]]);
    Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'report', 'command' => 'php artisan report', 'expression' => '0 3 * * *']);

    $current = $this->reconciler->current($this->scope)['units']['web'];

    expect($current['workers']['worker-1'])->toMatchArray(['queue' => ['high', 'default'], 'processes' => 2, 'timeout' => '1m30s', 'sleep' => '3s', 'memory' => '1g', 'env' => ['B' => '2', 'A' => '1']])
        ->and($current['daemons']['ws'])->toMatchArray(['command' => 'php artisan reverb:start', 'stop_timeout' => '30s', 'servers' => [$this->server->name]])
        ->and($current['schedules']['report'])->toMatchArray(['cron' => '0 3 * * *', 'timezone' => 'UTC', 'timeout' => '1h', 'enabled' => true]);
});

it('plans creates, field updates and noops; env values never appear', function () {
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'queue', 'queue' => 'default', 'timeout' => 90, 'env' => ['TOKEN' => 'old']]);
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'dashboard-only']);

    $changes = $this->reconciler->diff($this->scope, ['units' => ['web' => ['type' => 'app',
        'workers' => ['queue' => ['queue' => 'default', 'timeout' => '1m30s', 'processes' => 3, 'env' => ['TOKEN' => 'new']]],
        'daemons' => ['ws' => ['command' => 'run ws', 'env' => ['KEY' => 'secret']]],
    ]]], $this->reconciler->current($this->scope));
    $table = array_map(fn (Change $c) => [$c->op->value, $c->path], $changes->changes);

    expect($table)->toEqualCanonicalizing([
        ['noop', '/units/web/workers/queue/queue'],
        ['noop', '/units/web/workers/queue/timeout'],
        ['update', '/units/web/workers/queue/processes'],
        ['update', '/units/web/workers/queue/env'],
        ['create', '/units/web/daemons/ws'],
    ])
        ->and(json_encode($changes->toArray()))->not->toContain('secret')->not->toContain('new')->not->toContain('old')
        ->and(collect($changes->changes)->every(fn (Change $c) => in_array($c->section, $this->reconciler->sections(), true)))->toBeTrue();
});

it('applies the file through the actions and records ownership', function () {
    $ops = processes_config_apply([
        'workers' => ['queue' => ['queue' => ['high', 'default'], 'processes' => 2, 'memory' => '512m', 'timeout' => '2m', 'env' => ['TOKEN' => 'abc', 'DEBUG' => true]]],
        'daemons' => ['ws' => ['command' => 'php artisan reverb:start', 'stop_timeout' => '1m']],
        'schedules' => ['report' => ['command' => 'php artisan report', 'cron' => '@daily', 'timeout' => '10m']],
    ]);

    $worker = Worker::query()->sole();
    $managed = app(ManagedResources::class);

    expect($ops)->toHaveCount(3)
        ->and($worker->only(['name', 'queue', 'processes', 'memory', 'timeout']))->toBe(['name' => 'queue', 'queue' => 'high,default', 'processes' => 2, 'memory' => 512, 'timeout' => 120])
        ->and($worker->env)->toBe(['DEBUG' => 'true', 'TOKEN' => 'abc'])
        ->and(Daemon::query()->sole()->only(['name', 'stop_timeout']))->toBe(['name' => 'ws', 'stop_timeout' => 60])
        ->and(Schedule::query()->sole()->only(['name', 'expression', 'timeout']))->toBe(['name' => 'report', 'expression' => '@daily', 'timeout' => 600])
        ->and($managed->managed('worker', $worker->id)->paths)->toBe(['/workers/queue'])
        ->and($managed->createdByFile('worker', $worker->id))->toBeTrue()
        ->and($managed->managed('schedule', Schedule::query()->sole()->id)->paths)->toBe(['/schedules/report']);

    // Applying the same file again changes nothing.
    expect(processes_config_apply([
        'workers' => ['queue' => ['queue' => ['high', 'default'], 'processes' => 2, 'memory' => '512m', 'timeout' => '2m', 'env' => ['TOKEN' => 'abc', 'DEBUG' => true]]],
        'daemons' => ['ws' => ['command' => 'php artisan reverb:start', 'stop_timeout' => '1m']],
        'schedules' => ['report' => ['command' => 'php artisan report', 'cron' => '@daily', 'timeout' => '10m']],
    ]))->toBe([]);
});

it('deletes what the file created and only unmanages what it adopted', function () {
    $adopted = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id]);
    $untouched = Daemon::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'manual', 'command' => 'run']);

    processes_config_apply([
        'workers' => ['worker-1' => ['processes' => 4]],
        'daemons' => ['ws' => ['command' => 'run ws']],
    ]);
    $created = Daemon::query()->where('name', 'ws')->sole();

    expect($adopted->refresh()->name)->toBe('worker-1')->and($adopted->processes)->toBe(4)
        ->and(app(ManagedResources::class)->createdByFile('worker', $adopted->id))->toBeFalse();

    $ops = processes_config_apply([]);

    expect($ops)->toEqualCanonicalizing([['detach', '/units/web/workers/worker-1'], ['delete', '/units/web/daemons/ws']])
        ->and(Worker::query()->find($adopted->id))->not->toBeNull()
        ->and(app(ManagedResources::class)->managed('worker', $adopted->id)->any())->toBeFalse()
        ->and(Daemon::query()->find($created->id))->toBeNull()
        ->and(Daemon::query()->find($untouched->id))->not->toBeNull();
});

it('validates cron, time zones, bounds, env and servers', function () {
    $issues = $this->reconciler->validate($this->scope, ['units' => ['web' => ['type' => 'app', 'servers' => [$this->server->name],
        'workers' => ['q' => ['memory' => '16m', 'timeout' => '2d', 'servers' => ['other'], 'env' => ['X' => str_repeat('x', 4097)]]],
        'daemons' => ['d' => ['command' => 'x', 'stop_timeout' => '0s']],
        'schedules' => ['s' => ['command' => 'x', 'cron' => '* * *', 'timezone' => 'Mars/Olympus']],
    ]], 'environments' => [$this->scope->environment => []]]);

    expect(collect($issues)->map(fn ($i) => [$i->code, $i->pointer])->all())->toEqualCanonicalizing([
        ['value.range', '/units/web/workers/q/timeout'],
        ['value.range', '/units/web/workers/q/memory'],
        ['value.range', '/units/web/workers/q/env/X'],
        ['name.server', '/units/web/workers/q/servers/0'],
        ['value.range', '/units/web/daemons/d/stop_timeout'],
        ['value.unsupported', '/units/web/schedules/s/cron'],
        ['value.unsupported', '/units/web/schedules/s/timezone'],
    ]);
});

it('shows managed processes read-only and refuses edits of them', function () {
    processes_config_apply(['workers' => ['queue' => ['processes' => 2]], 'daemons' => ['ws' => ['command' => 'run ws']]]);
    $free = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => null]);
    $managed = Worker::query()->where('name', 'queue')->sole();
    $daemon = Daemon::query()->sole();

    $items = collect($this->getJson("/sites/{$this->site->id}/processes")->assertOk()->json('data.items'));

    expect($items->firstWhere('id', $managed->id)['managed'])->toMatchArray(['badge' => 'Managed by falak.yaml @ aaaaaaa', 'paths' => ['/workers/queue']])
        ->and($items->firstWhere('id', $free->id)['managed'])->toBeNull();

    $input = ['processes' => 3, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128];
    $this->putJson("/sites/{$this->site->id}/queues/{$managed->id}", $input)->assertStatus(422)->assertJsonValidationErrors('worker');
    $this->deleteJson("/sites/{$this->site->id}/daemons/{$daemon->id}")->assertStatus(422);
    $this->postJson("/sites/{$this->site->id}/daemons", ['name' => 'ws', 'command' => 'other', 'instances' => 1, 'restart' => 'always', 'stop_signal' => 'TERM', 'stop_timeout' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    $this->put("/sites/{$this->site->id}/queues/{$free->id}", $input)->assertSessionHasNoErrors();

    expect($managed->refresh()->processes)->toBe(2)->and($free->refresh()->processes)->toBe(3);

    // Disabling the config source makes every field editable again.
    ConfigSource::query()->update(['enabled' => false]);
    $this->put("/sites/{$this->site->id}/queues/{$managed->id}", $input)->assertSessionHasNoErrors();
    expect($managed->refresh()->processes)->toBe(3);
});
