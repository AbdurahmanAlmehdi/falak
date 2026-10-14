<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Security findings on a server (kind `security`, docs/plans/network/connections.md): an exposed port (stream A,
 * source `network.exposed_port`), scanners and brute force (streams B and C, their own sources). Ordinary Insights
 * issues (status, assignee, comments; a resolved one regresses when it is found again), scoped to one server and
 * source: the stored fingerprint is sha256("<server id>|<source>|<key>") and `meta` carries server_id, source and key.
 */
interface SecurityIssues
{
    /**
     * Records $count occurrences (at least 1). Must be called outside of a transaction.
     *
     * @param  string  $source  who found it, e.g. "network.exposed_port"
     * @param  string  $key  the finding within the server and source, e.g. "tcp/5432"
     * @param  array<string, mixed>  $sample  the latest occurrence
     * @param  array<string, mixed>  $meta  merged with server_id, source and key
     */
    public function record(
        string $organizationId,
        string $serverId,
        string $source,
        string $key,
        string $title,
        ?string $culprit,
        DateTimeInterface $at,
        int $count = 1,
        array $sample = [],
        array $meta = [],
    ): IssueData;

    /**
     * Resolves (automatically) the open issues of the server and source whose key is not in $keep: what is no longer
     * found. Returns how many were resolved.
     *
     * @param  list<string>  $keep
     */
    public function resolveExcept(string $organizationId, string $serverId, string $source, array $keep): int;

    /**
     * Open issues of one server and source, by their key ("tcp/5432").
     *
     * @return array<string, IssueData>
     */
    public function open(string $organizationId, string $serverId, string $source, int $limit = 200): array;
}
