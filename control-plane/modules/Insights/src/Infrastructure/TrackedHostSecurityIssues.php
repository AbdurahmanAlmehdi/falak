<?php

namespace Falak\Insights\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\HostSecurityIssues;
use Falak\Insights\Contracts\IssueKind;

final class TrackedHostSecurityIssues implements HostSecurityIssues
{
    public function __construct(private readonly IssueTracker $tracker) {}

    public function record(
        string $organizationId,
        string $serverId,
        string $finding,
        string $title,
        int $count,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData {
        $when = CarbonImmutable::instance($at);
        $serverId = strtolower($serverId);

        [$issue] = $this->tracker->record(
            $organizationId,
            IssueKind::Security,
            hash('sha256', "host|{$serverId}|{$finding}"),
            [
                'server_id' => $serverId,
                'title' => mb_substr($title, 0, 500),
                'culprit' => $finding,
                'sample' => $sample ?: null,
                'meta' => [...$meta, 'source' => 'host', 'server_id' => $serverId, 'finding' => $finding],
            ],
            $when,
            $when,
            max(1, $count),
        );

        return $issue->toData();
    }
}
