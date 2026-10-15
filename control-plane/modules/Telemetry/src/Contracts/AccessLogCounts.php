<?php

namespace Falak\Telemetry\Contracts;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\RequestCounts;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * Aggregates of a site's edge access log ({@see AccessLogs}): request and 5xx counts, counted by Loki.
 */
interface AccessLogCounts
{
    /**
     * Requests served by one release of the site from $from up to $to (the control plane's own health checks left out).
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function forRelease(string $organizationId, string $siteId, string $releaseId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts;

    /**
     * Requests served by the site from $from up to $to, every release (the control plane's own health checks left out).
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts;
}
