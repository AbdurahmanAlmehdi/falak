<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\Planning\Reconcilers;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;

/**
 * Applies a plan through the reconcilers: phase A (before any build) for the whole plan, phase B (with a unit's
 * release) per unit. Each reconciler gets only the changes it planned, in the section table's order; the scope is
 * refreshed after each phase A reconciler so later ones find units created before them.
 */
final class Applier
{
    public function __construct(
        private readonly Reconcilers $reconcilers,
        private readonly ScopeFactory $scopes,
        private readonly Planner $planner,
    ) {}

    /**
     * @throws \Throwable the first reconciler failure (the run fails; no release is touched)
     */
    public function phaseA(ConfigScope $scope, ChangeSet $plan, ApplyContext $ctx, ?string $onlyUnit = null): ConfigScope
    {
        foreach ($this->reconcilers->forPhase(ApplyPhase::BeforeBuild) as $reconciler) {
            $changes = $this->reconcilers->changesOf($reconciler, $plan);

            if ($onlyUnit !== null) {
                $changes = Planner::forUnit($changes, $onlyUnit);
            }

            if ($changes->changes !== []) {
                $reconciler->apply($scope, $changes, $ctx);
                $scope = $this->scopes->refresh($scope);
            }
        }

        return $scope;
    }

    /**
     * Phase B for one unit: planned again against today's state (the release just went live), so a retried or late
     * activation applies what is still needed.
     *
     * Risky changes are applied only when $approved covers them (op, path and rule); the others are skipped and returned
     * (a rollback passes none: it never applies a risky change).
     *
     * @param  array<string, mixed>  $resolved
     * @param  list<array{path: string, rule: ?string}>  $approved
     * @return list<Change> the risky changes skipped
     */
    public function phaseB(ConfigScope $scope, array $resolved, ApplyContext $ctx, string $unit, array $approved = []): array
    {
        $plan = $this->planner->plan($scope, $resolved, $unit)->changes;
        $skipped = RiskSet::beyond($plan->changes, $approved);
        $plan = new ChangeSet(array_values(array_filter($plan->changes, fn (Change $c) => ! in_array($c, $skipped, true))));

        foreach ($this->reconcilers->forPhase(ApplyPhase::WithRelease) as $reconciler) {
            $changes = $this->reconcilers->changesOf($reconciler, $plan);

            if ($changes->changes !== []) {
                $reconciler->apply($scope, $changes, $ctx);
            }
        }

        return array_values(array_filter($skipped, fn (Change $c) => in_array($c->section, array_merge(...array_map(fn ($r) => $r->sections(), $this->reconcilers->forPhase(ApplyPhase::WithRelease))), true)));
    }

    /**
     * Applies changes this run already planned for one unit, now, through the reconcilers of phase B whose sections end
     * in $suffix (".domains": the edge's): a never-released unit's domains, before its first deployment (RunEngine).
     */
    public function early(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx, string $suffix): void
    {
        foreach ($this->reconcilers->forPhase(ApplyPhase::WithRelease) as $reconciler) {
            if (array_filter($reconciler->sections(), fn (string $s) => str_ends_with($s, $suffix)) === []) {
                continue;
            }

            $owned = $this->reconcilers->changesOf($reconciler, $changes);

            if ($owned->changes !== []) {
                $reconciler->apply($scope, $owned, $ctx);
            }
        }
    }
}
