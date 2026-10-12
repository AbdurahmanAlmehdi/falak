<?php

namespace Falak\Config\Contracts;

/**
 * A module's part of config as code: the sections of falak.yaml it owns, read and written through the module's own
 * Actions. Implemented by Sites, Deployments, Processes, Limits, Edge, Databases, Volumes, Functions, Previews and
 * Projects (the section table of the phase 0 contract in docs/plans/CONFIG_AS_CODE.md).
 *
 * Section documents: current(), diff() and apply() see the resolved file pruned to this reconciler's sections, from
 * the root (e.g. Processes: ['units' => ['web' => ['workers' => [...], 'schedules' => [...]]]]). Keys the file
 * doesn't set are absent, never null.
 */
interface Reconcilable
{
    /** Container tag modules register their reconcilers under (`$this->app->tag([X::class], Reconcilable::TAG)`). */
    public const TAG = 'falak.config.reconcilers';

    /**
     * The sections this reconciler owns, as section patterns: dotted paths where `*` is any unit key and `*(app|compose)`
     * a unit key of those types only, e.g. ['units.*(app).workers', 'units.*(app).daemons', 'units.*(app).schedules'].
     *
     * @return list<string>
     */
    public function sections(): array;

    /** When its changes are applied. */
    public function phase(): ApplyPhase;

    /**
     * Today's state of the sections, in the file's shape (what `falak config export` writes).
     *
     * @return array<string, mixed>
     */
    public function current(ConfigScope $scope): array;

    /**
     * Changes needed to go from current to desired. Pure: no writes, no agent commands. Risky changes are flagged
     * by Config afterwards (contracts/config/risky-changes.json); a reconciler only flags what those rules can't see.
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $current
     */
    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet;

    /**
     * Apply the changes through the module's own Actions. Idempotent: a retried run applies the same set again.
     *
     * @throws \Throwable on failure; Config records it on the run (phase A failures stop the run)
     */
    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void;
}
