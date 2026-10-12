<?php

use Falak\Config\Contracts\ConfigScope;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Limits\Infrastructure\Config\LimitsReconciler;

$scope = fn () => new ConfigScope('org', 'project', 'env', 'production');
$today = ['units' => ['web' => ['type' => 'app', 'limits' => ['memory' => '1g', 'cpus' => 1.0, 'log' => ['max_files' => 5]]]]];

dataset('limit plans', [
    'sizes compare by value' => [['memory' => '1024MiB'], ['/units/web/limits/memory' => 'noop']],
    '1.5g is 1536m' => [['memory' => '1.5g'], ['/units/web/limits/memory' => 'update']],
    'cpus 1 equals 1.0' => [['cpus' => 1], ['/units/web/limits/cpus' => 'noop']],
    'nested log fields' => [['log' => ['max_files' => 5, 'max_size' => '10m']], ['/units/web/limits/log/max_files' => 'noop', '/units/web/limits/log/max_size' => 'create']],
]);

test('limits diff', function (array $limits, array $expected) use ($scope, $today) {
    $changes = app(LimitsReconciler::class)->diff($scope(), ['units' => ['web' => ['type' => 'app', 'limits' => $limits]]], $today);

    expect(collect($changes->changes)->mapWithKeys(fn ($c) => [$c->path => $c->op->value])->all())->toBe($expected);
})->with('limit plans');

test('limits convert between the file and ResourceLimits', function () {
    expect(LimitsReconciler::toFile(ResourceLimits::fromArray(['memory_limit' => 1536, 'log_max_size' => 1024, 'pids_limit' => 64, 'oom' => 'protect'])))
        ->toBe(['memory' => '1536m', 'pids' => 64, 'log' => ['max_size' => '1g'], 'oom' => 'protect'])
        ->and(LimitsReconciler::toInput(['memory' => '1.5g', 'log' => ['max_size' => '1g'], 'restart' => 'always']))
        ->toBe(['memory_limit' => 1536, 'log_max_size' => 1024, 'restart_policy' => 'always']);
});
