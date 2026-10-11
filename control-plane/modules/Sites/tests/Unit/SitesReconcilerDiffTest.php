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
        ->and($changes->changes[0]->section)->toBe('units.*(app).type');
});

test('the reconciler owns app units\' site fields, env and hooks', function () {
    expect(app(SitesReconciler::class)->sections())->toContain('units.*(app).runtime', 'units.*(app).env', 'units.*(app).deploy.hooks')
        ->and(SitesReconciler::prefixes())->toContain('/deploy/hooks', '/env', '/build');
});
