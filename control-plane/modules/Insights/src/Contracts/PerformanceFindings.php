<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Performance Issues found outside of the APM package (kind `performance`): slow endpoints from the edge's access logs
 * (docs/plans/network/http.md). One Issue per site and normalized route, fingerprint sha256("edge|<site id>|<route>"),
 * `meta.source = edge`; resolved automatically when the route is no longer slow, regressing when it is slow again.
 */
interface PerformanceFindings
{
    /**
     * Records one occurrence (one evaluation that found the route slow). Must be called outside of a transaction.
     *
     * @param  array<string, mixed>  $meta  merged with source, route, p95_ms, threshold_ms, count
     * @return array{0: IssueData, 1: bool} the issue and whether it was opened or regressed by this call
     */
    public function slowEndpoint(
        string $organizationId,
        string $siteId,
        string $route,
        float $p95Ms,
        int $thresholdMs,
        int $count,
        DateTimeInterface $at,
        array $meta = [],
    ): array;

    /**
     * Resolves (automatically) the site's open edge slow-endpoint Issues of the routes in $recovered: routes measured
     * fast again (enough requests, p95 well under the threshold). A route not measured keeps its Issue as it is.
     *
     * @param  list<string>  $recovered
     * @return list<IssueData> the issues resolved
     */
    public function recoverSlowEndpoints(string $organizationId, string $siteId, array $recovered): array;

    /**
     * Open edge slow-endpoint Issues of a site, slowest seen last first.
     *
     * @return list<IssueData>
     */
    public function openSlowEndpoints(string $organizationId, string $siteId, int $limit = 50): array;
}
