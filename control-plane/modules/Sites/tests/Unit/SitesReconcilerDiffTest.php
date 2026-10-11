<?php

use Falak\Config\Contracts\ConfigScope;
use Falak\Sites\Infrastructure\Config\SitesReconciler;

// Units the environment doesn't have yet are one `create` change (no database read).

test('a new unit is created whole', function () {
    $unit = ['type' => 'app', 'runtime' => 'node', 'node' => '22', 'env' => ['PORT' => 3000]];
    $changes = app(SitesReconciler::class)->diff(new ConfigScope('org', 'project', 'env', 'production'), ['units' => ['api' => $unit]], []);

    expect($changes->changes)->toHaveCount(1)
        ->and($changes->changes[0]->op->value)->toBe('create')
        ->and($changes->changes[0]->path)->toBe('/units/api')
        ->and($changes->changes[0]->after)->toBe($unit)
        ->and($changes->changes[0]->section)->toBe('units.*(app|compose|function).type');
});

test('the reconciler owns app units\' site fields, env and hooks, and compose units\' files and services', function () {
    expect(app(SitesReconciler::class)->sections())->toContain('units.*(app).runtime', 'units.*(app|compose|function).env', 'units.*(app|compose).deploy.hooks',
        'units.*(compose).files', 'units.*(compose).services.*.port', 'units.*(compose).services.*.replace_with')
        ->and(SitesReconciler::prefixes())->toContain('/deploy/hooks', '/env', '/build');
});
