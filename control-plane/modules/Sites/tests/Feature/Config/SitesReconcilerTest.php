<?php

use Falak\Config\Application\Planning\RiskRules;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\DeployScriptSections;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\Config\SitesLaravelReconciler;
use Falak\Sites\Infrastructure\Config\SitesReconciler;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Support/config.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1'], ['8.3', '8.4']);
    $this->env = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', ['APP_ENV' => 'production', 'APP_KEY' => 'base64:secret'], $this->env, [$this->server], [
        'deploy_script' => "# warm up\n\$FALAK_FETCH\n\$FALAK_PHP artisan optimize\n\$FALAK_ACTIVATE\n",
    ]);
    $this->source = config_source($this->organization, $this->env, connectionId: $this->git->addConnection($this->organization->id)->id);
    $this->reconciler = app(SitesReconciler::class);
    $this->scope = fn (array $resolved = []) => config_scope($this->env, $this->source, $resolved);
});

/**
 * @return array<string, array{0: string, 1: mixed, 2: mixed}> path => [op, before, after]
 */
function sites_changes(ChangeSet $set): array
{
    $out = [];

    foreach ($set->changes as $change) {
        $out[$change->path] = [$change->op->value, $change->before, $change->after];
    }

    return $out;
}

it('reads a site in the file\'s shape, never with secret values', function () {
    $unit = $this->reconciler->current(($this->scope)())['units']['web'];

    expect($unit)->toMatchArray([
        'type' => 'app',
        'runtime' => 'frankenphp',
        'framework' => 'laravel',
        'php' => '8.4',
        'web_directory' => 'public',
        'servers' => ['web-1'],
        'build' => ['mode' => 'native'],
        'laravel' => ['scheduler' => true, 'horizon' => false, 'octane' => false],
        'env' => ['APP_ENV' => 'production'],
        'deploy' => ['hooks' => ['before_activate' => '$FALAK_PHP artisan optimize']],
    ])->and($unit)->not->toHaveKey('root');
});

it('plans field by field: updates, noops, creates; never a secret in before', function () {
    $desired = ['units' => ['web' => [
        'type' => 'app',
        'runtime' => 'frankenphp',
        'php' => '8.3',
        'root' => '.',
        'env' => ['APP_ENV' => 'staging', 'APP_KEY' => '${{ secrets.APP_KEY }}', 'WORKERS' => 2, 'DEBUG' => false],
        'deploy' => ['hooks' => ['before_activate' => '$FALAK_PHP artisan optimize', 'after_activate' => 'php artisan queue:restart']],
    ]]];
    $scope = ($this->scope)();
    $changes = sites_changes($this->reconciler->diff($scope, $desired, $this->reconciler->current($scope)));

    expect($changes)->toMatchArray([
        '/units/web/type' => ['noop', 'app', 'app'],
        '/units/web/runtime' => ['noop', 'frankenphp', 'frankenphp'],
        '/units/web/root' => ['noop', '.', '.'],
        '/units/web/php' => ['update', '8.4', '8.3'],
        '/units/web/env/APP_ENV' => ['update', 'production', 'staging'],
        '/units/web/env/APP_KEY' => ['update', SitesReconciler::REDACTED, '${{ secrets.APP_KEY }}'],
        '/units/web/env/WORKERS' => ['create', null, '2'],
        '/units/web/env/DEBUG' => ['create', null, 'false'],
        '/units/web/deploy/hooks/before_activate' => ['noop', '$FALAK_PHP artisan optimize', '$FALAK_PHP artisan optimize'],
        '/units/web/deploy/hooks/after_activate' => ['create', null, 'php artisan queue:restart'],
    ]);

    foreach ($this->reconciler->diff($scope, $desired, [])->changes as $change) {
        expect($change->section)->toBeIn($this->reconciler->sections());
    }
});

it('applies fields through Sites\' actions and records what the file manages', function () {
    $desired = ['units' => ['web' => [
        'type' => 'app',
        'runtime' => 'frankenphp',
        'php' => '8.3',
        'env' => ['APP_ENV' => 'staging', 'WORKERS' => 2],
        'expose' => ['APP_ENV'],
        'laravel' => ['horizon' => true],
        'deploy' => ['hooks' => ['after_activate' => 'php artisan queue:restart']],
    ]]];
    $scope = ($this->scope)();
    $ctx = config_ctx($this->env);
    $this->reconciler->apply($scope, $this->reconciler->diff($scope, $desired, $this->reconciler->current($scope)), $ctx);

    $site = $this->site->refresh();
    $environment = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
    $sections = DeployScriptSections::split($site->deploy_script);

    expect($site->php_version)->toBe('8.3')
        ->and($site->laravel->horizon)->toBeFalse() // laravel is phase B (SitesLaravelReconciler), not this reconciler's
        ->and($environment->variables)->toMatchArray(['APP_ENV' => 'staging', 'WORKERS' => '2', 'APP_KEY' => 'base64:secret'])
        ->and($environment->exposed)->toBe(['APP_ENV'])
        ->and($sections['before_fetch'])->toBe('# warm up') // untouched sections keep their text
        ->and($sections['before_activate'])->toBe('$FALAK_PHP artisan optimize')
        ->and($sections['after_activate'])->toBe('php artisan queue:restart')
        ->and(ConfigOwnership::query()->where('resource_id', $site->id)->orderBy('path')->pluck('path')->all())->toBe([
            '/deploy/hooks/after_activate', '/env/APP_ENV', '/env/WORKERS', '/expose', '/php', '/runtime', '/type',
        ]);

    // A second apply with the env key gone keeps the variable but no longer manages it.
    unset($desired['units']['web']['env']['WORKERS']);
    $this->reconciler->apply($scope, $this->reconciler->diff($scope, $desired, $this->reconciler->current($scope)), $ctx);

    expect(EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first()->variables)->toHaveKey('WORKERS')
        ->and(ConfigOwnership::query()->where('resource_id', $site->id)->pluck('path')->all())->not->toContain('/env/WORKERS');
});

it('creates a unit the file adds, then deletes it once removed; a unit it didn\'t create is detached', function () {
    $resolved = config_doc('production', [], ['branch' => 'main', 'servers' => ['web-1']]);
    $desired = ['units' => [
        'web' => ['type' => 'app', 'runtime' => 'frankenphp'],
        'api' => ['type' => 'app', 'runtime' => 'node', 'node' => '22', 'root' => 'apps/api', 'env' => ['PORT' => 3000]],
    ]];
    $scope = ($this->scope)($resolved);
    $plan = $this->reconciler->diff($scope, $desired, $this->reconciler->current($scope));

    expect(sites_changes($plan)['/units/api'][0])->toBe('create');

    $this->reconciler->apply($scope, $plan, config_ctx($this->env));

    $api = Site::query()->where('name', 'api')->with('targets')->firstOrFail();
    $service = Service::query()->where('kind', 'site')->where('ref_id', $api->id)->firstOrFail();

    expect($api->runtime->value)->toBe('node')
        ->and($api->root_directory)->toBe('apps/api')
        ->and($api->repository)->toBe('acme/shop')
        ->and($api->serverIds())->toBe([$this->server->id])
        ->and($service->environment_id)->toBe($this->env->id)
        ->and($service->name)->toBe('api')
        ->and(EnvironmentVersion::query()->where('site_id', $api->id)->orderByDesc('version')->first()->variables)->toMatchArray(['PORT' => '3000'])
        ->and(ConfigOwnership::query()->where('resource_id', $api->id)->where('created_by_file', true)->exists())->toBeTrue();

    // Both units leave the file. Without allow_destroy nothing is deleted: both are detached (sites kept).
    $scope = ($this->scope)($resolved);
    $removal = sites_changes($this->reconciler->diff($scope, ['units' => []], $this->reconciler->current($scope)));

    expect($removal['/units/api'][0])->toBe('detach')
        ->and($removal['/units/web'][0])->toBe('detach');

    // With allow_destroy the unit the file created is deleted, and the plan flags it risky (unit.delete).
    $destroy = config_scope($this->env, $this->source, $resolved, allowDestroy: true);
    $plan = $this->reconciler->diff($destroy, ['units' => []], $this->reconciler->current($destroy));
    $flagged = app(RiskRules::class)->flag($plan, false, ['api' => 'app', 'web' => 'app']);
    $api = collect($flagged->changes)->firstWhere('path', '/units/api');

    expect($api->op->value)->toBe('delete')
        ->and($api->risky)->toBeTrue()
        ->and($api->rule)->toBe('unit.delete')
        ->and(collect($flagged->changes)->firstWhere('path', '/units/web')->op->value)->toBe('detach');

    $this->reconciler->apply($destroy, $plan, config_ctx($this->env, allowDestroy: true));

    expect(Site::query()->where('name', 'api')->exists())->toBeFalse()
        ->and(ConfigOwnership::query()->where('resource_id', $this->site->id)->exists())->toBeFalse()
        ->and(Site::query()->find($this->site->id))->not->toBeNull();
});

it('without allow_destroy a removed unit the file created is detached: the site stays, unmanaged', function () {
    $resolved = config_doc('production', [], ['branch' => 'main', 'servers' => ['web-1']]);
    $scope = ($this->scope)($resolved);
    $desired = ['units' => ['api' => ['type' => 'app', 'runtime' => 'node', 'node' => '22']]];
    $this->reconciler->apply($scope, $this->reconciler->diff($scope, $desired, $this->reconciler->current($scope)), config_ctx($this->env));
    $api = Site::query()->where('name', 'api')->firstOrFail();

    // Even a delete change (a stale plan) applied without allow_destroy only detaches.
    $scope = config_scope($this->env, $this->source, $resolved, allowDestroy: true);
    $plan = $this->reconciler->diff($scope, ['units' => []], $this->reconciler->current($scope));
    expect(sites_changes($plan)['/units/api'][0])->toBe('delete');

    $this->reconciler->apply($scope, $plan, config_ctx($this->env));

    expect(Site::query()->find($api->id))->not->toBeNull()
        ->and(ConfigOwnership::query()->where('resource_id', $api->id)->exists())->toBeFalse()
        // Listed as an unmanaged resource of the environment, with Sites' own delete (typed name included).
        ->and(ConfigDetached::query()->sole()->only(['resource_type', 'resource_id', 'site_id', 'label', 'delete_url', 'delete_body', 'environment_id']))->toBe([
            'resource_type' => 'site', 'resource_id' => $api->id, 'site_id' => $api->id, 'label' => 'Site api',
            'delete_url' => "/sites/{$api->id}", 'delete_body' => ['name' => 'api'], 'environment_id' => $this->env->id,
        ]);
});

it('maps units to sites through the scope, not the canvas name: a renamed service is no delete + create', function () {
    $scope = ($this->scope)();
    $ctx = config_ctx($this->env);
    $desired = ['units' => ['web' => ['type' => 'app', 'runtime' => 'frankenphp', 'php' => '8.4']]];
    $this->reconciler->apply($scope, $this->reconciler->diff($scope, $desired, $this->reconciler->current($scope)), $ctx);

    // The canvas card is renamed; Config keeps mapping the unit to its site (by the ownership's resource id).
    Service::query()->where('ref_id', $this->site->id)->update(['name' => 'storefront']);
    $mapped = new ConfigScope($scope->organizationId, $scope->projectId, $scope->environmentId, $scope->environment,
        services: ['web' => ['kind' => 'site', 'ref_id' => $this->site->id]], servers: $scope->servers);
    $changes = $this->reconciler->diff($mapped, $desired, $this->reconciler->current($mapped))->changes;

    expect(collect($changes)->pluck('op')->map->value->unique()->values()->all())->toBe(['noop'])
        ->and($mapped->unitOf($this->site->id))->toBe('web');
});

it('leaves unmanaged units alone when they are not in the file', function () {
    $scope = ($this->scope)();

    expect($this->reconciler->diff($scope, ['units' => []], $this->reconciler->current($scope))->changes)->toBe([]);
});

it('validates versions and runtime-only fields', function () {
    $issues = $this->reconciler->validate(($this->scope)(), config_doc('production', [
        'web' => ['type' => 'app', 'runtime' => 'frankenphp', 'php' => '7.0', 'port' => 8080],
        'js' => ['type' => 'app', 'runtime' => 'node', 'node' => '9', 'laravel' => ['scheduler' => true]],
    ]));

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.unsupported /units/web/php',
        'value.unsupported /units/web/port',
        'value.unsupported /units/js/node',
        'value.unsupported /units/js/laravel',
    ]);
});

it('refuses in the plan a unit switching between a container and a native runtime (it needs a new site)', function () {
    $toDocker = $this->reconciler->validate(($this->scope)(), config_doc('production', ['web' => ['type' => 'app', 'runtime' => 'docker', 'port' => 8080]]));
    expect(collect($toDocker)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toBe(['value.conflict /units/web/runtime'])
        ->and($toDocker[0]->message)->toContain('needs a new site');

    $this->site->forceFill(['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker'])->save();
    $toNative = $this->reconciler->validate(($this->scope)(), config_doc('production', ['web' => ['type' => 'app', 'runtime' => 'php-fpm', 'framework' => 'laravel', 'php' => '8.4', 'build' => ['mode' => 'native']]]));
    expect(collect($toNative)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toBe(['value.conflict /units/web/runtime']);

    // Another native runtime is fine.
    $this->site->forceFill(['runtime' => 'frankenphp', 'build_mode' => 'native', 'framework' => 'laravel'])->save();
    expect($this->reconciler->validate(($this->scope)(), config_doc('production', ['web' => ['type' => 'app', 'runtime' => 'php-fpm', 'php' => '8.4']])))->toBe([]);
});

it('splits and recomposes deploy scripts like the deployment planner', function () {
    $script = "echo one\n\$FALAK_FETCH\nmigrate\n\$FALAK_ACTIVATE\n# done\n\$FALAK_RESTART_PROCS\nnotify\n";

    expect(DeployScriptSections::split($script))->toBe(['before_fetch' => 'echo one', 'before_activate' => 'migrate', 'after_activate' => '# done', 'after_restart' => 'notify'])
        ->and(DeployScriptSections::meaningful('# done'))->toBe('')
        ->and(DeployScriptSections::split(DeployScriptSections::compose(DeployScriptSections::split($script))))->toBe(DeployScriptSections::split($script))
        ->and(DeployScriptSections::split("a\n\$FALAK_ACTIVATE\nb\n"))->toMatchArray(['before_activate' => 'a', 'after_activate' => 'b']);
});

it('laravel settings change with the release (phase B), not before the build', function () {
    $laravel = app(SitesLaravelReconciler::class);
    $phaseA = app(SitesReconciler::class);
    $desired = ['units' => ['web' => ['type' => 'app', 'runtime' => 'frankenphp', 'laravel' => ['horizon' => true, 'scheduler' => false]]]];
    $scope = ($this->scope)();
    Event::fake([SiteUpdated::class]);

    expect($laravel->phase())->toBe(ApplyPhase::WithRelease)
        ->and($phaseA->sections())->not->toContain('units.*(app).laravel')
        ->and($laravel->sections())->toBe(['units.*(app).laravel']);

    // Phase A ignores laravel entirely: nothing planned, nothing changed.
    $planA = $phaseA->diff($scope, $desired, $phaseA->current($scope));
    expect(collect($planA->changes)->filter(fn ($c) => str_contains($c->path, '/laravel'))->all())->toBe([]);
    $phaseA->apply($scope, $planA, config_ctx($this->env));
    expect($this->site->refresh()->laravel->horizon)->toBeFalse();
    Event::assertNotDispatched(SiteUpdated::class, fn ($e) => in_array('laravel', (array) ($e->changed ?? []), true));

    // Phase B (with the release) applies it and records ownership on the same paths.
    $planB = $laravel->diff($scope, $desired, $laravel->current($scope));
    expect(sites_changes($planB))->toBe([
        '/units/web/laravel/horizon' => ['update', false, true],
        '/units/web/laravel/scheduler' => ['update', true, false],
    ]);
    $laravel->apply($scope, $planB, config_ctx($this->env, ApplyPhase::WithRelease));

    $site = $this->site->refresh();
    expect($site->laravel->horizon)->toBeTrue()
        ->and($site->laravel->scheduler)->toBeFalse()
        ->and(ConfigOwnership::query()->where('resource_id', $site->id)->orderBy('path')->pluck('path')->all())->toBe(['/laravel/horizon', '/laravel/scheduler', '/runtime', '/type'])
        ->and($laravel->diff($scope, $desired, $laravel->current($scope))->isEmpty())->toBeTrue();
});
