<?php

namespace Falak\Config\Contracts;

/**
 * When a reconciler's changes are applied during a config run (docs/plans/CONFIG_AS_CODE.md, "Flow on a push").
 */
enum ApplyPhase: string
{
    /**
     * Before any build: databases, Redis, volumes, units, build and deploy settings, env versions, limits, previews. A
     * failure stops the run and touches no running release.
     */
    case BeforeBuild = 'A';

    /**
     * With each unit's release, at its activate step: workers, daemons, schedules, domains, redirects, headers,
     * mounts. They go live with the release that expects them.
     */
    case WithRelease = 'B';

    public function label(): string
    {
        return match ($this) {
            self::BeforeBuild => 'Before build',
            self::WithRelease => 'With the release',
        };
    }
}
