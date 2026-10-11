<?php

use Falak\Config\Application\Planning\Sections;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Reconcilable;

function sections_owner(array $sections): Reconcilable
{
    return new class($sections) implements Reconcilable
    {
        public function __construct(private readonly array $owned) {}

        public function sections(): array
        {
            return $this->owned;
        }

        public function phase(): ApplyPhase
        {
            return ApplyPhase::BeforeBuild;
        }

        public function current(ConfigScope $scope): array
        {
            return [];
        }

        public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
        {
            return new ChangeSet;
        }

        public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void {}
    };
}

test('a * inside a unit matches every key of a map: compose services are shared between owners', function () {
    $sites = sections_owner(['units.*(compose).services.*.port', 'units.*(compose).files']);
    $limits = sections_owner(['units.*(compose).services.*.limits']);
    $sections = new Sections([$sites, $limits]);
    $document = ['units' => [
        'shop' => ['type' => 'compose', 'files' => ['compose.yaml'], 'services' => [
            'web' => ['port' => 80, 'limits' => ['cpus' => 1], 'keep' => true],
            'db' => ['limits' => ['memory' => '1g']],
        ]],
        'web' => ['type' => 'app', 'services' => ['x' => ['port' => 1]]],
    ]];

    expect($sections->prune($sites, $document))->toBe(['units' => ['shop' => ['services' => ['web' => ['port' => 80]], 'files' => ['compose.yaml']]]])
        ->and($sections->prune($limits, $document))->toBe(['units' => ['shop' => ['services' => ['web' => ['limits' => ['cpus' => 1]], 'db' => ['limits' => ['memory' => '1g']]]]]])
        ->and(collect($sections->unsupported($document))->pluck('pointer')->all())->toEqualCanonicalizing(['/units/shop/type', '/units/shop/services/web/keep', '/units/web']);
});
