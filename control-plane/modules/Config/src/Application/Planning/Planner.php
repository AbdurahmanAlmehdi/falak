<?php

namespace Falak\Config\Application\Planning;

use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;

/**
 * Plans a resolved file for one environment: every registered {@see Reconcilable::diff()} on its sections, then the
 * risky-change rules. Pure: nothing is written.
 */
final class Planner
{
    public function __construct(
        private readonly Reconcilers $reconcilers,
        private readonly RiskRules $risks,
    ) {}

    /**
     * @param  array<string, mixed>  $resolved
     * @param  ?string  $onlyUnit  plan one unit only (a rollback restores one unit's config)
     */
    public function plan(ConfigScope $scope, array $resolved, ?string $onlyUnit = null): Plan
    {
        $changes = new ChangeSet;
        $sections = $this->reconcilers->sections();
        $types = [];
        $withVolumes = [];

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            $types[(string) $unit] = (string) ($body['type'] ?? '');
        }

        foreach ($this->reconcilers->all() as $reconciler) {
            $desired = $sections->prune($reconciler, $resolved);
            $current = $sections->prune($reconciler, $this->withTypes($reconciler->current($scope)));

            foreach ((array) ($current['units'] ?? []) as $unit => $body) {
                $types[(string) $unit] ??= (string) ($body['type'] ?? 'app');
                $withVolumes[(string) $unit] = ($withVolumes[(string) $unit] ?? false) || ! empty($body['volumes']);
            }

            if ($onlyUnit !== null) {
                $desired = self::onlyUnit($desired, $onlyUnit);
                $current = self::onlyUnit($current, $onlyUnit);
            }

            $changes = $changes->merge($reconciler->diff($scope, $desired, $current));
        }

        $changes = $this->risks->flag($changes, $scope->production, $types, $withVolumes);
        $deploy = [];

        $phaseA = [];

        foreach ($this->reconcilers->forPhase(ApplyPhase::BeforeBuild) as $reconciler) {
            foreach ($reconciler->sections() as $section) {
                $phaseA[$section] = true;
            }
        }

        foreach ($changes->actionable() as $change) {
            if ($change->unit === null || ! isset($resolved['units'][$change->unit])) {
                continue;
            }

            // A removal deploys the unit when it changes what the release runs with (phase A: e.g. an unmounted
            // volume); removals applied with the release (phase B: workers, domains) don't need one.
            $removal = $change->op === ChangeOp::Delete || $change->op === ChangeOp::Detach;

            if (! $removal || ($change->path !== '/units/'.Pointer::escape($change->unit) && isset($phaseA[$change->section]))) {
                $deploy[$change->unit] = true;
            }
        }

        return new Plan($changes, array_keys($deploy));
    }

    /**
     * current() documents may leave out `type`; units default to app for section selection.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function withTypes(array $document): array
    {
        foreach ((array) ($document['units'] ?? []) as $unit => $body) {
            if (is_array($body) && ! isset($body['type'])) {
                $document['units'][$unit]['type'] = 'app';
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function onlyUnit(array $document, string $unit): array
    {
        if (isset($document['units'])) {
            $document['units'] = array_key_exists($unit, $document['units']) ? [$unit => $document['units'][$unit]] : [];
        }

        return array_intersect_key($document, ['units' => true]);
    }

    /**
     * The changes of one unit.
     */
    public static function forUnit(ChangeSet $changes, string $unit): ChangeSet
    {
        return new ChangeSet(array_values(array_filter($changes->changes, fn (Change $c) => $c->unit === $unit)));
    }
}
