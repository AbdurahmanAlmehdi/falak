<?php

namespace Falak\Insights\Contracts;

use DateTimeInterface;
use Falak\Insights\Contracts\Data\IssueData;

/**
 * Security Issues (kind `security`, docs/plans/OBSERVABILITY.md phase 4): what an analysis of the traffic, the
 * listeners or the auth logs finds. Each stream records its findings through its own methods; they share the kind.
 *
 * HTTP scanners (docs/plans/network/http.md): one Issue per site and threat rule, fingerprint
 * sha256("http|<site id>|<rule>"), `meta.source = http`. They are not resolved automatically: scanning comes and goes,
 * the user resolves the Issue and a new wave regresses it.
 */
interface SecurityFindings
{
    /**
     * Records $count probes or attacks (at least 1) of one rule against a site. Must be called outside of a transaction.
     *
     * @param  string  $rule  the threat rule (env, git, wordpress, sqli, traversal, 404_storm, …)
     * @param  array<string, mixed>  $sample  the latest window: top client IPs, sample paths
     * @param  array<string, mixed>  $meta  merged with source, rule
     */
    public function scanner(
        string $organizationId,
        string $siteId,
        string $rule,
        string $title,
        int $count,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData;

    /**
     * Open HTTP scanner Issues of a site, most recently seen first.
     *
     * @return list<IssueData>
     */
    public function openScanners(string $organizationId, string $siteId, int $limit = 50): array;
}
