<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Issues of managed databases (kind `database`, docs/plans/OBSERVABILITY.md phase 3): slow query shapes and engine
 * errors the Databases module finds in the agent's reports. They are ordinary Insights issues (status, assignee,
 * priority, comments; a resolved one regresses when it happens again), scoped to one instance: the stored fingerprint
 * is sha256("<instance id>|<fingerprint>") and `meta.instance_id` names the instance.
 */
interface DatabaseIssues
{
    /**
     * Records $count occurrences (at least 1). Must be called outside of a transaction.
     *
     * @param  string  $fingerprint  the shape or error within the instance, e.g. "pg:123", "error:deadlock"
     * @param  array<string, mixed>  $sample  the latest occurrence (query, mean_ms, …)
     * @param  array<string, mixed>  $meta  merged with instance_id and fingerprint
     */
    public function record(
        string $organizationId,
        string $serverId,
        string $instanceId,
        string $fingerprint,
        string $title,
        ?string $culprit,
        int $count,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData;

    /**
     * Open issues of one instance, most recently seen first.
     *
     * @return list<IssueData>
     */
    public function open(string $organizationId, string $instanceId, int $limit = 50): array;
}
