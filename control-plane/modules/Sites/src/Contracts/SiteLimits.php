<?php

namespace Falak\Sites\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * A site's own resource limits (sites_sites.limits) for other modules (Limits' config reconciler): same validation
 * and live/redeploy behaviour as PUT /sites/{site}/limits.
 */
interface SiteLimits
{
    /**
     * Replace the site's limits (null clears them).
     *
     * @param  ?array<string, mixed>  $limits  ResourceLimits keys (memory_limit, cpus, …)
     * @return 'live'|'redeploy'|'none'
     *
     * @throws ValidationException
     */
    public function set(string $siteId, ?array $limits, ?string $userId = null): string;
}
