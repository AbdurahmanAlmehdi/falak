<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Security Issues of a server (kind `security`, docs/plans/network/protection.md): SSH brute force found in the auth
 * log. One Issue per server and finding, fingerprint sha256("host|<server id>|<finding>"), `meta.source = host`,
 * `meta.server_id`. Not resolved automatically: a resolved Issue regresses when the attacks start again.
 */
interface HostSecurityIssues
{
    /**
     * Records $count occurrences (at least 1). Must be called outside of a transaction.
     *
     * @param  string  $finding  e.g. "ssh.bruteforce"
     * @param  array<string, mixed>  $sample  the latest window (top sources)
     * @param  array<string, mixed>  $meta  merged with source, server_id, finding
     */
    public function record(
        string $organizationId,
        string $serverId,
        string $finding,
        string $title,
        int $count,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData;
}
