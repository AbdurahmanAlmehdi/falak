<?php

namespace Falak\Sites\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * A site's own resource limits (sites_sites.limits, and compose_limits per compose service) for other modules (Limits'
 * config reconciler): same validation and live/redeploy behaviour as PUT /sites/{site}/limits.
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

    /**
     * Replace the limits of one service of a compose site (null clears them: the site's defaults apply); applied on
     * the next deploy.
     *
     * @param  ?array<string, mixed>  $limits  ResourceLimits keys
     * @return 'live'|'redeploy'|'none'
     *
     * @throws ValidationException when the service is not in the compose project, or the limits are invalid
     */
    public function setService(string $siteId, string $service, ?array $limits, ?string $userId = null): string;
}
