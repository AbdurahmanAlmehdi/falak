<?php

namespace Falak\Insights\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\UptimeIssues;
use Falak\Insights\Domain\Models\Issue;

final class TrackedUptimeIssues implements UptimeIssues
{
    public function __construct(private readonly IssueTracker $tracker) {}

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
    ): IssueData {
        $when = CarbonImmutable::instance($at);

        [$issue] = $this->tracker->record(
            $organizationId,
            IssueKind::Uptime,
            self::fingerprint($monitorId, $problem),
            [
                'site_id' => $siteId,
                'title' => mb_substr($title, 0, 500),
                'culprit' => $culprit !== null ? mb_substr($culprit, 0, 500) : null,
                'sample' => $sample ?: null,
                'meta' => [...$meta, 'monitor_id' => strtolower($monitorId), 'problem' => $problem],
            ],
            $when,
            $when,
        );

        return $issue->toData();
    }

    public function resolve(string $organizationId, string $monitorId, string $problem): bool
    {
        $issue = Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', IssueKind::Uptime)
            ->where('fingerprint', self::fingerprint($monitorId, $problem))
            ->where('status', '!=', IssueStatus::Resolved)
            ->first();

        return $issue !== null && $this->tracker->resolve($issue, null, automatic: true);
    }

    private static function fingerprint(string $monitorId, string $problem): string
    {
        return hash('sha256', strtolower($monitorId).'|'.$problem);
    }
}
