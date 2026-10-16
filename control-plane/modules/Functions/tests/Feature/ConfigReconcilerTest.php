<?php

use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Application\RepositoryCode;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Functions\Infrastructure\Config\FunctionSchedulesReconciler;
use Falak\Functions\Infrastructure\Config\FunctionsReconciler;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../Sites/tests/Support/config.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->git->files = ['fn/main.py' => "app = None\n", 'fn/util.py' => "X = 1\n"];
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    Agent::factory()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'facts' => ['features' => ['fn.v1', 'fn.v2', 'fn.v3']]]);
    $this->env = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'api', [], $this->env, [$this->server], [
        'runtime' => 'function', 'framework' => 'docker', 'build_mode' => 'docker', 'php_version' => null, 'repository' => null, 'source_connection_id' => null, 'branch' => null,
    ]);
    $this->function = app(FunctionStore::class)->ensure(app(SiteDirectory::class)->find($this->site->id), runtime: 'python');
    $this->source = config_source($this->organization, $this->env, connectionId: $this->git->addConnection($this->organization->id)->id);
    $this->scope = config_scope($this->env, $this->source, config_doc('production', [], ['branch' => 'main']));
    $this->functions = app(FunctionsReconciler::class);
    $this->schedules = app(FunctionSchedulesReconciler::class);
});

/**
 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
 */
function fn_changes(ChangeSet $set): array
{
    $out = [];

    foreach ($set->changes as $change) {
        $out[$change->path] = [$change->op->value, $change->before, $change->after];
    }

    return $out;
}

it('reads a function in the file\'s shape and plans its settings and code', function () {
    $current = $this->functions->current($this->scope);

    expect($current['units']['api'])->toBe(['type' => 'function', 'runtime' => 'python', 'entrypoint' => 'main.py',
        'scaling' => ['min_instances' => 0, 'max_instances' => 5, 'concurrency' => 50, 'idle_timeout' => '5m'], 'memory' => '256m', 'cpus' => 0.5, 'timeout' => '30s']);

    $desired = ['units' => ['api' => ['type' => 'function', 'runtime' => 'python', 'root' => 'fn', 'scaling' => ['idle_timeout' => '300s', 'max_instances' => 2], 'memory' => '1024MiB']]];
    $changes = fn_changes($this->functions->diff($this->scope, $desired, $current));
    $head = substr($this->function->head()->hash, 0, 12);

    expect($changes['/units/api/runtime'][0])->toBe('noop')
        ->and($changes['/units/api/root'])->toBe(['create', null, 'fn'])
        ->and($changes['/units/api/scaling/idle_timeout'])->toBe(['noop', '5m', '5m'])
        ->and($changes['/units/api/scaling/max_instances'])->toBe(['update', 5, 2])
        ->and($changes['/units/api/memory'])->toBe(['update', '256m', '1g'])
        ->and($changes['/units/api/code'][0])->toBe('update')
        ->and($changes['/units/api/code'][1])->toBe($head);

    $this->functions->apply($this->scope, $this->functions->diff($this->scope, $desired, $current), config_ctx($this->env));
    $function = $this->function->refresh();

    expect($function->only(['root', 'max_instances', 'memory_mb']))->toBe(['root' => 'fn', 'max_instances' => 2, 'memory_mb' => 1024])
        ->and(array_keys($function->head()->files))->toBe(['main.py', 'util.py'])
        ->and(ConfigOwnership::query()->where('resource_id', $this->site->id)->orderBy('path')->pluck('path')->all())
        ->toBe(['/code', '/memory', '/root', '/runtime', '/scaling/idle_timeout', '/scaling/max_instances'])
        ->and(collect($this->functions->diff($this->scope, $desired, $this->functions->current($this->scope))->actionable())->all())->toBe([]);
});

it('validates runtimes, bounds and the code', function () {
    $issues = $this->functions->validate($this->scope, config_doc('production', [
        'api' => ['type' => 'function', 'runtime' => 'python', 'root' => 'fn', 'memory' => '32m', 'timeout' => '1h', 'scaling' => ['min_instances' => 4, 'max_instances' => 2]],
        'other' => ['type' => 'function', 'runtime' => 'bun', 'root' => 'fn'],
        'ruby' => ['type' => 'function', 'runtime' => 'ruby'],
    ]));

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.range /units/api/memory',
        'value.range /units/api/timeout',
        'value.conflict /units/api/scaling/min_instances',
        'value.range /units/other/root', // no index.ts under fn
        'value.unsupported /units/ruby/runtime',
    ]);
});

it('creates, updates and removes the schedules the file sets; adopted ones are only released', function () {
    FunctionSchedule::query()->create(['function_id' => $this->function->id, 'name' => 'manual', 'expression' => '@hourly', 'timezone' => 'UTC', 'overlap' => 'skip', 'timeout_s' => 300, 'enabled' => true]);
    $ctx = config_ctx($this->env);
    app(ManagedResources::class)->record($this->scope, $ctx, 'api', 'site', $this->site->id, ['/type'], merge: true); // the unit is the file's
    $apply = function (array $schedules) use ($ctx) {
        $desired = ['units' => ['api' => ['type' => 'function', 'schedules' => $schedules]]];
        $plan = $this->schedules->diff($this->scope, $desired, $this->schedules->current($this->scope));
        $this->schedules->apply($this->scope, $plan, $ctx);

        return fn_changes($plan);
    };

    $changes = $apply(['nightly' => ['cron' => '0  3 * * *', 'timeout' => '600s'], 'manual' => ['cron' => '@daily']]);

    expect($changes['/units/api/schedules/nightly'])->toBe(['create', null, ['cron' => '0 3 * * *', 'timeout' => '10m']])
        ->and($changes['/units/api/schedules/manual/cron'])->toBe(['update', '@hourly', '@daily'])
        ->and(FunctionSchedule::query()->orderBy('name')->get()->map->only(['name', 'expression', 'timeout_s'])->all())->toBe([
            ['name' => 'manual', 'expression' => '@daily', 'timeout_s' => 300],
            ['name' => 'nightly', 'expression' => '0 3 * * *', 'timeout_s' => 600],
        ]);

    $changes = $apply([]);

    expect($changes['/units/api/schedules/nightly'][0])->toBe('delete')
        ->and($changes['/units/api/schedules/manual'][0])->toBe('detach')
        ->and(FunctionSchedule::query()->pluck('name')->all())->toBe(['manual'])
        ->and(ConfigOwnership::query()->where('resource_type', 'function_schedule')->exists())->toBeFalse()
        ->and(ConfigDetached::query()->sole()->only(['resource_type', 'label']))->toBe(['resource_type' => 'function_schedule', 'label' => 'Function schedule manual (api)']);

    $issues = $this->schedules->validate($this->scope, config_doc('production', ['api' => ['type' => 'function', 'schedules' => [
        'bad' => ['cron' => 'every day', 'timezone' => 'Mars/Olympus', 'timeout' => '2d'],
    ]]]));
    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.unsupported /units/api/schedules/bad/cron',
        'value.unsupported /units/api/schedules/bad/timezone',
        'value.range /units/api/schedules/bad/timeout',
    ]);
});

it('refuses dashboard edits of what the file manages: settings, allowlist, schedules', function () {
    $desired = ['units' => ['api' => ['type' => 'function', 'runtime' => 'python', 'root' => 'fn', 'scaling' => ['max_instances' => 2], 'access' => ['allow' => ['10.0.0.0/8']]]]];
    $ctx = config_ctx($this->env);
    $this->functions->apply($this->scope, $this->functions->diff($this->scope, $desired, $this->functions->current($this->scope)), $ctx);
    $schedules = ['units' => ['api' => ['type' => 'function', 'schedules' => ['nightly' => ['cron' => '@daily']]]]];
    $this->schedules->apply($this->scope, $this->schedules->diff($this->scope, $schedules, $this->schedules->current($this->scope)), $ctx);
    $nightly = FunctionSchedule::query()->where('name', 'nightly')->sole();
    $base = "/sites/{$this->site->id}/function";

    // The panel shows them read-only.
    expect($this->getJson($base)->assertOk()->json('data.managed.paths'))->toContain('/scaling/max_instances', '/code')
        ->and($this->getJson("{$base}/access")->json('data.managed.paths'))->toContain('/access/allow')
        ->and($this->getJson("{$base}/schedules")->json('data.schedules.0.managed.paths'))->toBe(['/schedules/nightly']);

    $this->putJson("{$base}/settings", ['max_instances' => 4])->assertStatus(422)->assertJsonValidationErrors('max_instances');
    $this->putJson("{$base}/settings", ['max_instances' => 2, 'concurrency' => 10])->assertOk();
    $this->putJson("{$base}/access/allowlist", ['allow_cidrs' => ['0.0.0.0/0']])->assertStatus(422)->assertJsonValidationErrors('allow_cidrs');
    $this->putJson("{$base}/schedules/{$nightly->id}", ['name' => 'nightly', 'expression' => '@hourly'])->assertStatus(422);
    $this->deleteJson("{$base}/schedules/{$nightly->id}")->assertStatus(422);
    $this->postJson("{$base}/schedules", ['name' => 'nightly', 'expression' => '@hourly'])->assertStatus(422);
});

it('keys schedules by their owned key first, then deterministically, and guards them by that key', function () {
    $schedule = fn (string $name, string $cron, ?string $id = null) => FunctionSchedule::query()->forceCreate(['id' => $id ?? strtolower((string) Str::ulid()),
        'function_id' => $this->function->id, 'name' => $name, 'expression' => $cron, 'timezone' => 'UTC', 'overlap' => 'skip', 'timeout_s' => 300, 'enabled' => true]);
    $nightly = $schedule('Nightly report', '@daily');
    $ctx = config_ctx($this->env);
    $desired = ['units' => ['api' => ['type' => 'function', 'schedules' => ['Nightly-report' => ['cron' => '@daily']]]]];
    $this->schedules->apply($this->scope, $this->schedules->diff($this->scope, $desired, $this->schedules->current($this->scope)), $ctx);
    $base = "/sites/{$this->site->id}/function";

    // Managed under /schedules/Nightly-report: its name isn't its key, the dashboard still can't edit it or take the key.
    $this->putJson("{$base}/schedules/{$nightly->id}", ['name' => 'Nightly report', 'expression' => '@hourly'])->assertStatus(422);
    $this->deleteJson("{$base}/schedules/{$nightly->id}")->assertStatus(422);
    $this->postJson("{$base}/schedules", ['name' => 'Nightly-report', 'expression' => '@hourly'])->assertStatus(422)->assertJsonValidationErrors('name');

    // Rows made around the guards: the owned row keeps its key; same-name rows take -2 by id, not by insertion order.
    $schedule('Nightly-report', '@hourly');
    $schedule('Weekly job', '@monthly', '01k0000000000000000000000z');
    $schedule('Weekly job', '@weekly', '01k0000000000000000000000a');
    $current = $this->schedules->current($this->scope)['units']['api']['schedules'];

    expect($current['Nightly-report']['cron'])->toBe('@daily')
        ->and($current['Nightly-report-2']['cron'])->toBe('@hourly')
        ->and($current['Weekly-job']['cron'])->toBe('@weekly')
        ->and($current['Weekly-job-2']['cron'])->toBe('@monthly')
        ->and(collect($this->schedules->diff($this->scope, $desired, $this->schedules->current($this->scope))->actionable())->all())->toBe([]);
});

it('refuses a function whose code listing the provider cut short', function () {
    $this->git->treeTruncated = true;

    $issues = $this->functions->validate($this->scope, config_doc('production', ['api' => ['type' => 'function', 'runtime' => 'python', 'root' => 'fn']]));

    expect($issues[0]->code.' '.$issues[0]->pointer)->toBe('value.range /units/api/root')
        ->and($issues[0]->message)->toContain('too long to read whole');
});

it('drift compares a function\'s settings, never its code: no repository reads', function () {
    $drift = new ConfigScope(...[...get_object_vars($this->scope), 'drift' => true]);
    $desired = ['units' => ['api' => ['type' => 'function', 'runtime' => 'python', 'root' => 'fn', 'memory' => '1g']]];
    $this->git->fileReads = [];

    $changes = fn_changes($this->functions->diff($drift, $desired, $this->functions->current($drift)));

    expect($changes)->not->toHaveKey('/units/api/code')
        ->and($changes['/units/api/memory'][0])->toBe('update')
        ->and($this->git->fileReads)->toBe([]);
});

it('the repository code reader keeps successes only: a failed read is read again', function () {
    $code = app(RepositoryCode::class);
    $sha = str_repeat('a', 40);
    $this->git->treeTruncated = true;

    expect(fn () => $code->read($this->source->source_connection_id, 'acme/shop', $sha, 'fn', 'main.py'))->toThrow(ValidationException::class);

    $this->git->treeTruncated = false;
    expect($code->read($this->source->source_connection_id, 'acme/shop', $sha, 'fn', 'main.py')['files'])->toHaveKeys(['main.py', 'util.py']);
});
