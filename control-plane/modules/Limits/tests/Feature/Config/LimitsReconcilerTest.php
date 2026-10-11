<?php

use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Identity\Contracts\Role;
use Falak\Limits\Infrastructure\Config\LimitsReconciler;

require_once __DIR__.'/../../../../Sites/tests/Support/config.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    sites_fake_agent_memory([$this->server->id => 4 * 1024 ** 3]);
    $this->env = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', [], $this->env, [$this->server], ['runtime' => 'php-fpm', 'limits' => ['memory_limit' => 512, 'oom' => 'protect']]);
    $this->source = config_source($this->organization, $this->env);
    $this->reconciler = app(LimitsReconciler::class);
    $this->scope = config_scope($this->env, $this->source);
});

it('reads limits in the file\'s shape and plans sizes by value', function () {
    expect($this->reconciler->current($this->scope)['units']['web']['limits'])->toBe(['memory' => '512m', 'oom' => 'protect']);

    $desired = ['units' => ['web' => ['limits' => ['memory' => '512MiB', 'cpus' => 1.5, 'log' => ['max_size' => '1g']]]]];
    $changes = collect($this->reconciler->diff($this->scope, $desired, $this->reconciler->current($this->scope))->changes)
        ->mapWithKeys(fn ($c) => [$c->path => [$c->op->value, $c->before, $c->after]])->all();

    expect($changes)->toBe([
        '/units/web/limits/memory' => ['noop', '512m', '512m'],
        '/units/web/limits/cpus' => ['create', null, 1.5],
        '/units/web/limits/log/max_size' => ['create', null, '1g'],
    ]);
});

it('applies limits, keeping fields the file doesn\'t set, and records the paths', function () {
    $desired = ['units' => ['web' => ['limits' => ['memory' => '1g', 'log' => ['max_size' => '20m']]]]];
    $this->reconciler->apply($this->scope, $this->reconciler->diff($this->scope, $desired, $this->reconciler->current($this->scope)), config_ctx($this->env));

    expect($this->site->refresh()->limits)->toMatchArray(['memory_limit' => 1024, 'log_max_size' => 20, 'oom' => 'protect'])
        ->and(ConfigOwnership::query()->where('resource_id', $this->site->id)->orderBy('path')->pluck('path')->all())->toBe(['/limits/log/max_size', '/limits/memory']);
});

it('validates sizes and the unit\'s servers', function () {
    $issues = $this->reconciler->validate($this->scope, config_doc('production', [
        'web' => ['type' => 'app', 'runtime' => 'php-fpm', 'limits' => ['memory' => '16m']],
        'api' => ['type' => 'app', 'runtime' => 'node', 'limits' => ['memory' => '1g', 'memory_reservation' => '2g']],
        'big' => ['type' => 'app', 'runtime' => 'node', 'servers' => ['web-1'], 'limits' => ['memory' => '64g']],
    ]));

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.range /units/web/limits/memory',
        'value.conflict /units/api/limits/memory_reservation',
        'limit.server /units/big/limits',
    ]);
});
