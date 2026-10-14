<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Issues of uptime monitors (kind `uptime`, docs/plans/network/uptime.md): a monitor that is down, or whose certificate
 * chain has a problem. They are ordinary Insights issues (status, assignee, priority, comments) scoped to one monitor:
 * the stored fingerprint is sha256("<monitor id>|<problem>") and `meta.monitor_id` names the monitor. The Uptime
 * module resolves them when the problem clears; the next occurrence regresses the issue, so one issue per monitor and
 * problem counts its outages. Alerting does not announce them (the `uptime.*` alerts do).
 */
interface UptimeIssues
{
    /**
     * Records one occurrence. Must be called outside of a transaction.
     *
     * @param  string  $problem  "down", "tls"
     * @param  array<string, mixed>  $sample  the occurrence (error, status code, …)
     * @param  array<string, mixed>  $meta  merged with monitor_id and problem
     */
    public function record(
        string $organizationId,
        ?string $siteId,
        string $monitorId,
        string $problem,
        string $title,
        ?string $culprit,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData;

    /**
     * Resolves the monitor's open issue for $problem (automatic recovery). False when there is none open.
     */
    public function resolve(string $organizationId, string $monitorId, string $problem): bool;
}
