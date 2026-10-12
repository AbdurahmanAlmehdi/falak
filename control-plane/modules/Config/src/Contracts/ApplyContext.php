<?php

namespace Falak\Config\Contracts;

/**
 * Who and what a {@see Reconcilable::apply()} call runs for. In phase B it runs once per unit, at that unit's activate
 * step: `unit`, `deploymentId` and `releaseId` say which.
 */
final readonly class ApplyContext
{
    /**
     * @param  ?string  $actorId  the user the changes are recorded as (the approver, or the CLI user; null: the push)
     * @param  bool  $allowDestroy  `falak apply --allow-destroy`: deleting databases and volumes the file removed is allowed
     */
    public function __construct(
        public string $runId,
        public ApplyPhase $phase,
        public ?string $commit = null,
        public ?string $actorId = null,
        public bool $allowDestroy = false,
        public ?string $unit = null,
        public ?string $deploymentId = null,
        public ?string $releaseId = null,
    ) {}
}
