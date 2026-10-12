<?php

namespace Falak\Config\Application\Jobs;

use Falak\Config\Application\Drift\DriftChecker;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The drift check of one environment: one job per environment at a time (a slow one never piles up behind itself).
 */
final class CheckEnvironmentDrift implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A plan reads every reconciler's state: allow it a while. */
    public int $timeout = 300;

    public int $tries = 1;

    /** The lock outlives a stuck worker by a little, then the next tick may try again. */
    public int $uniqueFor = 600;

    public function __construct(public string $environmentId) {}

    public function uniqueId(): string
    {
        return $this->environmentId;
    }

    public function handle(DriftChecker $checker, ProjectDirectory $projects): void
    {
        $environment = $projects->environment($this->environmentId);

        if ($environment !== null && ! $environment->isPreview) {
            $checker->check($environment);
        }
    }
}
