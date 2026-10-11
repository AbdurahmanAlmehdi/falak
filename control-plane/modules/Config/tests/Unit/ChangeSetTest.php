<?php

use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;

test('a change set summarizes, filters and round-trips its changes', function () {
    $set = ChangeSet::of(
        new Change(ChangeOp::Update, '/units/web/deploy/strategy', 'zero-downtime', 'rolling', 'units.*(app).deploy', 'web'),
        new Change(ChangeOp::Noop, '/units/web/deploy/batch_size', 1, 1, 'units.*(app).deploy', 'web'),
    )->merge(ChangeSet::of(
        (new Change(ChangeOp::Detach, '/units/db', ['type' => 'database'], null, 'units.*(database|redis)', 'db'))
            ->withRisk('unit.remove_with_data', 'db holds data: it is kept and detached'),
    ));

    expect($set->summary())->toBe(['create' => 0, 'update' => 1, 'delete' => 0, 'detach' => 1, 'noop' => 1])
        ->and($set->isEmpty())->toBeFalse()
        ->and($set->isRisky())->toBeTrue()
        ->and($set->risky()[0]->rule)->toBe('unit.remove_with_data')
        ->and($set->forUnit('web')->changes)->toHaveCount(2)
        ->and(ChangeSet::fromArray($set->toArray()))->toEqual($set)
        ->and(ChangeSet::of(new Change(ChangeOp::Noop, '/units/web/runtime', 'node', 'node'))->isEmpty())->toBeTrue();
});

test('a scope learns the units phase A creates', function () {
    $scope = new ConfigScope('org', 'project', 'env', 'production', production: true);
    $next = $scope->withService('web', 'site', '01jsite');

    expect($scope->refId('web'))->toBeNull()
        ->and($next->refId('web'))->toBe('01jsite')
        ->and($next->production)->toBeTrue();
});
