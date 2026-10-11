<?php

use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\DeployScriptSections;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Infrastructure\Config\SitesReconciler;

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
        ->and($site->laravel->horizon)->toBeTrue()
        ->and($site->laravel->scheduler)->toBeTrue() // absent in the file: kept
        ->and($environment->variables)->toMatchArray(['APP_ENV' => 'staging', 'WORKERS' => '2', 'APP_KEY' => 'base64:secret'])
        ->and($environment->exposed)->toBe(['APP_ENV'])
        ->and($sections['before_fetch'])->toBe('# warm up') // untouched sections keep their text
        ->and($sections['before_activate'])->toBe('$FALAK_PHP artisan optimize')
        ->and($sections['after_activate'])->toBe('php artisan queue:restart')
        ->and(ConfigOwnership::query()->where('resource_id', $site->id)->orderBy('path')->pluck('path')->all())->toBe([
            '/deploy/hooks/after_activate', '/env/APP_ENV', '/env/WORKERS', '/expose', '/laravel/horizon', '/php', '/runtime', '/type',
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

    // Both units leave the file.
    $scope = ($this->scope)($resolved);
    $removal = sites_changes($this->reconciler->diff($scope, ['units' => []], $this->reconciler->current($scope)));

    expect($removal['/units/api'][0])->toBe('delete')
        ->and($removal['/units/web'][0])->toBe('detach');

    $this->reconciler->apply($scope, $this->reconciler->diff($scope, ['units' => []], $this->reconciler->current($scope)), config_ctx($this->env));

    expect(Site::query()->whereKey($api->id)->exists())->toBeFalse()
        ->and(ConfigOwnership::query()->where('resource_id', $api->id)->exists())->toBeFalse()
        ->and(ConfigOwnership::query()->where('resource_id', $this->site->id)->exists())->toBeFalse()
        ->and(Site::query()->find($this->site->id))->not->toBeNull();
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

it('splits and recomposes deploy scripts like the deployment planner', function () {
    $script = "echo one\n\$FALAK_FETCH\nmigrate\n\$FALAK_ACTIVATE\n# done\n\$FALAK_RESTART_PROCS\nnotify\n";

    expect(DeployScriptSections::split($script))->toBe(['before_fetch' => 'echo one', 'before_activate' => 'migrate', 'after_activate' => '# done', 'after_restart' => 'notify'])
        ->and(DeployScriptSections::meaningful('# done'))->toBe('')
        ->and(DeployScriptSections::split(DeployScriptSections::compose(DeployScriptSections::split($script))))->toBe(DeployScriptSections::split($script))
        ->and(DeployScriptSections::split("a\n\$FALAK_ACTIVATE\nb\n"))->toMatchArray(['before_activate' => 'a', 'after_activate' => 'b']);
});
