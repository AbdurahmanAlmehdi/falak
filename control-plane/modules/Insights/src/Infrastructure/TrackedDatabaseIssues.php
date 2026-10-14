<?php

namespace Falak\Insights\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\DatabaseIssues;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;

final class TrackedDatabaseIssues implements DatabaseIssues
{
    public function __construct(private readonly IssueTracker $tracker) {}

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
    ): IssueData {
        $when = CarbonImmutable::instance($at);

        [$issue] = $this->tracker->record(
            $organizationId,
            IssueKind::Database,
            hash('sha256', strtolower($instanceId).'|'.$fingerprint),
            [
                'server_id' => $serverId,
                'title' => mb_substr($title, 0, 500),
                'culprit' => $culprit !== null ? mb_substr($culprit, 0, 500) : null,
                'sample' => $sample ?: null,
                'meta' => [...$meta, 'instance_id' => strtolower($instanceId), 'fingerprint' => $fingerprint],
            ],
            $when,
            $when,
            max(1, $count),
        );

        return $issue->toData();
    }

    public function open(string $organizationId, string $instanceId, int $limit = 50): array
    {
        return Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', IssueKind::Database)
            ->where('status', IssueStatus::Open)
            ->where('meta->instance_id', strtolower($instanceId))
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (Issue $issue) => $issue->toData())
            ->all();
    }
}
