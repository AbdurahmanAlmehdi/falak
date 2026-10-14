<?php

namespace Falak\Insights\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\SecurityIssues;
use Falak\Insights\Domain\Models\Issue;

final class TrackedSecurityIssues implements SecurityIssues
{
    public function __construct(private readonly IssueTracker $tracker) {}

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
    ): IssueData {
        $when = CarbonImmutable::instance($at);
        $serverId = strtolower($serverId);

        [$issue] = $this->tracker->record(
            $organizationId,
            IssueKind::Security,
            self::fingerprint($serverId, $source, $key),
            [
                'server_id' => $serverId,
                'title' => mb_substr($title, 0, 500),
                'culprit' => $culprit !== null ? mb_substr($culprit, 0, 500) : null,
                'sample' => $sample ?: null,
                'meta' => [...$meta, 'server_id' => $serverId, 'source' => $source, 'key' => $key],
            ],
            $when,
            $when,
            max(1, $count),
        );

        return $issue->toData();
    }

    public function resolveExcept(string $organizationId, string $serverId, string $source, array $keep): int
    {
        $serverId = strtolower($serverId);
        $keep = array_map(fn (string $key) => self::fingerprint($serverId, $source, $key), $keep);
        $resolved = 0;

        $issues = Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', IssueKind::Security)
            ->where('server_id', $serverId)
            ->where('status', IssueStatus::Open)
            ->where('meta->source', $source)
            ->whereNotIn('fingerprint', $keep)
            ->get();

        foreach ($issues as $issue) {
            if ($this->tracker->resolve($issue, null, automatic: true)) {
                $resolved++;
            }
        }

        return $resolved;
    }

    public function open(string $organizationId, string $serverId, string $source, int $limit = 200): array
    {
        return Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', IssueKind::Security)
            ->where('status', IssueStatus::Open)
            ->where('server_id', strtolower($serverId))
            ->where('meta->source', $source)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Issue $issue) => [(string) ($issue->meta['key'] ?? $issue->id) => $issue->toData()])
            ->all();
    }

    private static function fingerprint(string $serverId, string $source, string $key): string
    {
        return hash('sha256', strtolower($serverId).'|'.$source.'|'.$key);
    }
}
