<?php

use Falak\Config\Contracts\ConfigScope;
use Falak\Deployments\Infrastructure\Config\DeploymentsReconciler;

// The plan is pure: today's deploy block → the file's → changes (no database).

$scope = fn () => new ConfigScope('org', 'project', 'env', 'production', true, null, ['web' => ['kind' => 'site', 'ref_id' => 'site']]);
$today = ['units' => ['web' => ['type' => 'app', 'deploy' => [
    'strategy' => 'zero-downtime', 'batch_size' => 1, 'keep_releases' => 5, 'secrets' => 'env',
    'health' => ['enabled' => true, 'status' => 200, 'timeout' => '10s', 'retries' => 3, 'retry_delay' => '5s'],
    'watch' => ['enabled' => false, 'minutes' => 5, 'on_trigger' => 'rollback', 'health' => true, 'health_failures' => 3, 'crashes' => true, 'errors' => true, 'issues' => false],
]]]];

dataset('deploy plans', [
    'same values are noops' => [['strategy' => 'zero-downtime', 'health' => ['timeout' => '10s']], ['/units/web/deploy/strategy' => 'noop', '/units/web/deploy/health/timeout' => 'noop']],
    '90s equals 1m30s' => [['health' => ['retry_delay' => '90s']], ['/units/web/deploy/health/retry_delay' => 'update']],
    'a watch block turns watching on' => [['watch' => []], ['/units/web/deploy/watch/enabled' => 'update']],
    'watch enabled false stays off' => [['watch' => ['enabled' => false]], ['/units/web/deploy/watch/enabled' => 'noop']],
    'a field set for the first time' => [['health' => ['path' => '/up']], ['/units/web/deploy/health/path' => 'create']],
    'hooks are not this reconciler\'s' => [['hooks' => ['before_activate' => 'x']], []],
]);

test('deploy diff', function (array $deploy, array $expected) use ($scope, $today) {
    $changes = app(DeploymentsReconciler::class)->diff($scope(), ['units' => ['web' => ['type' => 'app', 'deploy' => $deploy]]], $today);

    expect(collect($changes->changes)->mapWithKeys(fn ($c) => [$c->path => $c->op->value])->all())->toBe($expected);

    foreach ($changes->changes as $change) {
        expect($change->section)->toBe(DeploymentsReconciler::SECTION)->and($change->unit)->toBe('web');
    }
})->with('deploy plans');

test('a unit without a site yet: every field is created', function () use ($scope) {
    $changes = app(DeploymentsReconciler::class)->diff($scope(), ['units' => ['api' => ['type' => 'app', 'deploy' => ['strategy' => 'rolling', 'batch_size' => 2]]]], []);

    expect(collect($changes->changes)->map(fn ($c) => $c->op->value.' '.$c->path)->all())->toBe(['create /units/api/deploy/strategy', 'create /units/api/deploy/batch_size']);
});
