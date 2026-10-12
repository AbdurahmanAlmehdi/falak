<?php

use Falak\Config\Application\Runs\RiskSet;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;

it('hashes the sorted risky set and covers by op, path and rule', function () {
    $detach = (new Change(ChangeOp::Detach, '/units/db', null, null, 'units.*(database|redis)', 'db'))->withRisk('unit.remove_with_data', 'x');
    $delete = (new Change(ChangeOp::Delete, '/units/db', null, null, 'units.*(database|redis)', 'db'))->withRisk('unit.remove_with_data', 'x');
    $other = (new Change(ChangeOp::Update, '/units/web/deploy/strategy', 'a', 'b', 'x', 'web'))->withRisk('production.strategy', 'y');
    $safe = new Change(ChangeOp::Update, '/units/web/php', '8.3', '8.4', 'x', 'web');

    expect(RiskSet::of([$other, $safe, $detach]))->toBe([
        ['op' => 'detach', 'path' => '/units/db', 'rule' => 'unit.remove_with_data'],
        ['op' => 'update', 'path' => '/units/web/deploy/strategy', 'rule' => 'production.strategy'],
    ])
        ->and(RiskSet::hash([$other, $detach]))->toBe(RiskSet::hash([$detach, $safe, $other]))
        ->and(RiskSet::hash([$other, $detach]))->toBe(hash('sha256', '[{"op":"detach","path":"/units/db","rule":"unit.remove_with_data"},{"op":"update","path":"/units/web/deploy/strategy","rule":"production.strategy"}]'))
        ->and(RiskSet::beyond([$delete], RiskSet::of([$detach])))->toBe([$delete])
        ->and(RiskSet::beyond([$detach], RiskSet::of([$detach])))->toBe([]);
});
