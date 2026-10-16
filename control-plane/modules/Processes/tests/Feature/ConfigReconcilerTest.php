<?php

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigDetached;
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
    // The unit is the file's (Sites records its site in phase A).
    app(ManagedResources::class)->record($this->scope, $this->ctx, 'web', 'site', $this->site->id, ['/type'], merge: true);
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
        ->and(Daemon::query()->find($untouched->id))->not->toBeNull()
        // The adopted worker is listed as unmanaged, with the Processes delete route.
        ->and(ConfigDetached::query()->sole()->only(['resource_type', 'resource_id', 'unit', 'label', 'reason', 'delete_url']))->toBe([
            'resource_type' => 'worker', 'resource_id' => $adopted->id, 'unit' => 'web', 'label' => 'Worker worker-1 (web)', 'reason' => 'removed_from_file',
            'delete_url' => "/sites/{$this->site->id}/queues/{$adopted->id}",
        ]);

    // Managed again (the file lists it once more): off the list.
    processes_config_apply(['workers' => ['worker-1' => ['processes' => 4]]]);
    expect(ConfigDetached::query()->count())->toBe(0);
});

it('items of a unit that left the file follow its detached site: detached, never deleted', function () {
    processes_config_apply(['daemons' => ['ws' => ['command' => 'run ws']]]);
    $daemon = Daemon::query()->sole();
    app(ManagedResources::class)->forget('site', $this->site->id, $this->scope->environmentId); // Sites detached the unit's site

    expect(processes_config_apply([]))->toBe([['detach', '/units/web/daemons/ws']])
        ->and(Daemon::query()->find($daemon->id))->not->toBeNull()
        ->and(ConfigDetached::query()->where('resource_type', 'daemon')->exists())->toBeTrue();
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

it('refuses workers without a command on units that aren\'t Laravel (queue:work), at validation', function () {
    $new = new ConfigScope($this->scope->organizationId, $this->scope->projectId, $this->scope->environmentId, $this->scope->environment);
    $issues = $this->reconciler->validate($new, ['units' => [
        'api' => ['type' => 'app', 'runtime' => 'php-fpm', 'workers' => ['q' => ['queue' => ['mail']], 'ok' => ['command' => 'php jobs.php']]],
        'shop' => ['type' => 'app', 'runtime' => 'php-fpm', 'framework' => 'laravel', 'workers' => ['q' => ['queue' => ['mail']]]],
        'node' => ['type' => 'app', 'runtime' => 'node', 'framework' => 'laravel', 'workers' => ['q' => ['processes' => 2]]],
    ]]);

    expect(collect($issues)->map(fn ($i) => [$i->code, $i->pointer])->all())->toEqualCanonicalizing([
        ['value.unsupported', '/units/api/workers/q'],
        ['value.unsupported', '/units/node/workers/q'],
    ]);

    // An existing Laravel site (the unit's site) runs queue:work without a command.
    expect($this->reconciler->validate($this->scope, ['units' => ['web' => ['type' => 'app', 'workers' => ['q' => ['processes' => 1]]]]]))->toBe([]);
});

it('runs file processes only as the site\'s own unix user', function () {
    $issues = $this->reconciler->validate($this->scope, ['units' => ['web' => ['type' => 'app',
        'daemons' => ['ws' => ['command' => 'run', 'user' => 'ubuntu'], 'ok' => ['command' => 'run', 'user' => 'shop'], 'default' => ['command' => 'run']],
        'schedules' => ['report' => ['command' => 'php artisan report', 'cron' => '@daily', 'user' => 'deploy']],
    ]], 'environments' => [$this->scope->environment => []]]);

    expect(collect($issues)->map(fn ($i) => [$i->code, $i->pointer])->all())->toEqualCanonicalizing([
        ['value.forbidden', '/units/web/daemons/ws/user'],
        ['value.forbidden', '/units/web/schedules/report/user'],
    ])->and($issues[0]->message)->toContain('"shop"');

    // Defense in depth: apply always writes the site's user, even for a plan that names another one.
    processes_config_apply(['daemons' => ['ws' => ['command' => 'run', 'user' => 'ubuntu']], 'schedules' => ['report' => ['command' => 'php artisan report', 'cron' => '@daily', 'user' => 'ubuntu']]]);

    expect(Daemon::query()->where('name', 'ws')->value('user'))->toBe('shop')
        ->and(Schedule::query()->where('name', 'report')->value('user'))->toBe('shop');

    // A unit without a site yet may not name a user at all.
    $new = new ConfigScope($this->scope->organizationId, $this->scope->projectId, $this->scope->environmentId, $this->scope->environment);
    expect(collect($this->reconciler->validate($new, ['units' => ['api' => ['type' => 'app', 'daemons' => ['ws' => ['command' => 'run', 'user' => 'shop']]]]]))->pluck('code')->all())
        ->toBe(['value.forbidden']);
});

it('never reuses env values of an earlier plan', function () {
    $other = processes_site($this->organization->id, [$this->server], ['slug' => 'other']);
    $scope = new ConfigScope($this->scope->organizationId, $this->scope->projectId, $this->scope->environmentId, $this->scope->environment, true, null,
        ['web' => ['kind' => 'site', 'ref_id' => $this->site->id], 'api' => ['kind' => 'site', 'ref_id' => $other->id]], $this->scope->servers);
    $r = $this->reconciler;

    // A plan for web with env, then a plan for api's worker of the same key without env.
    $r->diff($scope, ['units' => ['web' => ['type' => 'app', 'workers' => ['queue' => ['command' => 'work', 'env' => ['TOKEN' => 'web-secret']]]]]], ['units' => []]);
    $changes = $r->diff($scope, ['units' => ['api' => ['type' => 'app', 'workers' => ['queue' => ['command' => 'work']]]]], ['units' => []]);
    $r->apply($scope, $changes, new ApplyContext($this->ctx->runId, ApplyPhase::WithRelease, null, unit: 'api'));

    expect(Worker::query()->where('site_id', $other->id)->sole()->env)->toBeEmpty();

    // A later plan doesn't see web's env either: applying web's stale changes after another diff refuses.
    $stale = $r->diff($scope, ['units' => ['web' => ['type' => 'app', 'workers' => ['queue' => ['command' => 'work', 'env' => ['TOKEN' => 'web-secret']]]]]], ['units' => []]);
    $r->diff($scope, ['units' => ['api' => ['type' => 'app']]], ['units' => []]);

    expect(fn () => $r->apply($scope, $stale, $this->ctx))->toThrow(RuntimeException::class, 'plan the unit again');
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

it('an adopted schedule keeps its key: a dashboard row named like it later is refused, or takes the next key', function () {
    $nightly = Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'Nightly report', 'command' => 'php artisan report', 'expression' => '0 3 * * *']);
    $file = ['schedules' => ['Nightly-report' => ['command' => 'php artisan report', 'cron' => '0 3 * * *']]];
    processes_config_apply($file);

    $this->postJson("/sites/{$this->site->id}/scheduler", ['name' => 'Nightly-report', 'command' => 'other', 'expression' => '@hourly',
        'overlap' => 'skip', 'timeout' => 60, 'heartbeat' => true, 'enabled' => true, 'all_servers' => false])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    // One made around the guard reads as Nightly-report-2: planning the file again changes nothing.
    Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'Nightly-report', 'command' => 'other', 'expression' => '@hourly']);
    $current = $this->reconciler->current($this->scope)['units']['web']['schedules'];

    expect(array_keys($current))->toEqualCanonicalizing(['Nightly-report', 'Nightly-report-2'])
        ->and($current['Nightly-report']['command'])->toBe('php artisan report')
        ->and(processes_config_apply($file))->toBe([])
        ->and($nightly->refresh()->only(['name', 'command']))->toBe(['name' => 'Nightly report', 'command' => 'php artisan report']);
});
