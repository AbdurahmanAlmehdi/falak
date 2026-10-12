<?php

use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Infrastructure\Config\DeploymentsReconciler;
use Falak\Identity\Contracts\Role;

require_once __DIR__.'/../../../../Sites/tests/Support/config.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    $this->env = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', [], $this->env, [$this->server]);
    $this->source = config_source($this->organization, $this->env);
    $this->reconciler = app(DeploymentsReconciler::class);
    $this->scope = config_scope($this->env, $this->source);
});

it('reads deploy settings in the file\'s shape (defaults when the site has none yet)', function () {
    expect($this->reconciler->current($this->scope)['units']['web']['deploy'])->toMatchArray([
        'strategy' => 'zero-downtime',
        'batch_size' => 1,
        'keep_releases' => 5,
        'secrets' => 'env',
        'health' => ['enabled' => true, 'status' => 200, 'timeout' => '10s', 'retries' => 3, 'retry_delay' => '5s'],
    ])->and(SiteSettings::query()->find($this->site->id))->toBeNull(); // planning writes nothing
});

it('plans per field, durations compared by length of time, watch present means enabled', function () {
    $desired = ['units' => ['web' => ['deploy' => [
        'strategy' => 'rolling',
        'batch_size' => 1,
        'health' => ['timeout' => '10s', 'retry_delay' => '1m30s', 'path' => '/up'],
        'watch' => ['minutes' => 15, 'on_trigger' => 'alert_only'],
    ]]]];
    $changes = collect($this->reconciler->diff($this->scope, $desired, $this->reconciler->current($this->scope))->changes)
        ->mapWithKeys(fn ($c) => [$c->path => [$c->op->value, $c->before, $c->after]])->all();

    expect($changes)->toBe([
        '/units/web/deploy/strategy' => ['update', 'zero-downtime', 'rolling'],
        '/units/web/deploy/batch_size' => ['noop', 1, 1],
        '/units/web/deploy/health/timeout' => ['noop', '10s', '10s'],
        '/units/web/deploy/health/retry_delay' => ['update', '5s', '1m30s'],
        '/units/web/deploy/health/path' => ['create', null, '/up'],
        '/units/web/deploy/watch/minutes' => ['update', 5, 15],
        '/units/web/deploy/watch/on_trigger' => ['update', 'rollback', 'alert_only'],
        '/units/web/deploy/watch/enabled' => ['update', false, true],
    ]);
});

it('applies through UpdateDeploySettings and UpdateWatchSettings and records the paths', function () {
    $desired = ['units' => ['web' => ['deploy' => [
        'strategy' => 'rolling',
        'health' => ['retry_delay' => '1m30s'],
        'watch' => ['minutes' => 15],
    ]]]];
    $this->reconciler->apply($this->scope, $this->reconciler->diff($this->scope, $desired, $this->reconciler->current($this->scope)), config_ctx($this->env));

    $settings = SiteSettings::query()->findOrFail($this->site->id);

    expect($settings->strategy)->toBe(Strategy::Rolling)
        ->and($settings->health_retry_delay_s)->toBe(90)
        ->and($settings->health_timeout_s)->toBe(10)
        ->and($settings->watch_enabled)->toBeTrue()
        ->and($settings->watch_minutes)->toBe(15)
        ->and(ConfigOwnership::query()->where('resource_id', $this->site->id)->orderBy('path')->pluck('path')->all())
        ->toBe(['/deploy/health/retry_delay', '/deploy/strategy', '/deploy/watch/enabled', '/deploy/watch/minutes']);

    // Removing the watch from the file forgets those paths and keeps the settings.
    unset($desired['units']['web']['deploy']['watch']);
    $this->reconciler->apply($this->scope, $this->reconciler->diff($this->scope, $desired, $this->reconciler->current($this->scope)), config_ctx($this->env));

    expect(ConfigOwnership::query()->where('resource_id', $this->site->id)->orderBy('path')->pluck('path')->all())->toBe(['/deploy/health/retry_delay', '/deploy/strategy'])
        ->and($settings->refresh()->watch_enabled)->toBeTrue();
});

it('validates the strategy against the runtime and the health durations', function () {
    $issues = $this->reconciler->validate($this->scope, config_doc('production', [
        'web' => ['type' => 'app', 'runtime' => 'docker', 'deploy' => ['strategy' => 'zero-downtime', 'health' => ['timeout' => '5m', 'retry_delay' => '10m']]],
        'api' => ['type' => 'app', 'runtime' => 'node', 'deploy' => ['strategy' => 'rolling', 'secrets' => 'files']],
    ]));

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.unsupported /units/web/deploy/strategy',
        'value.range /units/web/deploy/health/timeout',
        'value.range /units/web/deploy/health/retry_delay',
        'value.unsupported /units/api/deploy/secrets',
    ]);
});
