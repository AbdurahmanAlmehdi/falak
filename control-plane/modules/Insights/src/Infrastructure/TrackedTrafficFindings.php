<?php

namespace Falak\Insights\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\PerformanceFindings;
use Falak\Insights\Contracts\SecurityFindings;
use Falak\Insights\Domain\Models\Issue;

/**
 * Issues from the edge's HTTP traffic (docs/plans/network/http.md): slow endpoints (kind performance) and scanners
 * (kind security), both scoped to a site and marked meta.source so they never mix with the APM's or other streams'.
 */
final class TrackedTrafficFindings implements PerformanceFindings, SecurityFindings
{
    public const SOURCE_EDGE = 'edge';

    public const SOURCE_HTTP = 'http';

    public function __construct(private readonly IssueTracker $tracker) {}

    public static function slowFingerprint(string $siteId, string $route): string
    {
        return hash('sha256', 'edge|'.strtolower($siteId).'|'.$route);
    }

    public static function scannerFingerprint(string $siteId, string $rule): string
    {
        return hash('sha256', 'http|'.strtolower($siteId).'|'.$rule);
    }

    public function slowEndpoint(
        string $organizationId,
        string $siteId,
        string $route,
        float $p95Ms,
        int $thresholdMs,
        int $count,
        DateTimeInterface $at,
        array $meta = [],
    ): array {
        $when = CarbonImmutable::instance($at);

        [$issue, $transition] = $this->tracker->record(
            $organizationId,
            IssueKind::Performance,
            self::slowFingerprint($siteId, $route),
            [
                'site_id' => strtolower($siteId),
                'title' => mb_strcut('Slow endpoint: '.$route, 0, 500),
                'culprit' => 'p95 over '.number_format($thresholdMs).' ms in the last hour (edge access log)',
                'sample' => ['p95_ms' => round($p95Ms, 1), 'count' => $count],
                'meta' => [
                    ...$meta,
                    'source' => self::SOURCE_EDGE,
                    'route' => $route,
                    'p95_ms' => round($p95Ms, 1),
                    'threshold_ms' => $thresholdMs,
                    'count' => $count,
                ],
            ],
            $when,
            $when,
        );

        return [$issue->toData(), in_array($transition, [IssueTracker::OPENED, IssueTracker::REGRESSED], true)];
    }

    public function recoverSlowEndpoints(string $organizationId, string $siteId, array $stillSlow): array
    {
        $keep = array_map(fn (string $route) => self::slowFingerprint($siteId, $route), $stillSlow);
        $resolved = [];

        $open = Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', IssueKind::Performance)
            ->where('site_id', strtolower($siteId))
            ->where('status', IssueStatus::Open)
            ->where('meta->source', self::SOURCE_EDGE)
            ->whereNotIn('fingerprint', $keep)
            ->get();

        foreach ($open as $issue) {
            if ($this->tracker->resolve($issue, null, automatic: true)) {
                $resolved[] = $issue->toData();
            }
        }

        return $resolved;
    }

    public function openSlowEndpoints(string $organizationId, string $siteId, int $limit = 50): array
    {
        return $this->open($organizationId, $siteId, IssueKind::Performance, self::SOURCE_EDGE, $limit);
    }

    public function scanner(
        string $organizationId,
        string $siteId,
        string $rule,
        string $title,
        int $count,
        DateTimeInterface $at,
        array $sample = [],
        array $meta = [],
    ): IssueData {
        $when = CarbonImmutable::instance($at);

        [$issue] = $this->tracker->record(
            $organizationId,
            IssueKind::Security,
            self::scannerFingerprint($siteId, $rule),
            [
                'site_id' => strtolower($siteId),
                'title' => mb_strcut($title, 0, 500),
                'culprit' => 'edge access log',
                'sample' => $sample ?: null,
                'meta' => [...$meta, 'source' => self::SOURCE_HTTP, 'rule' => $rule],
            ],
            $when,
            $when,
            max(1, $count),
        );

        return $issue->toData();
    }

    public function openScanners(string $organizationId, string $siteId, int $limit = 50): array
    {
        return $this->open($organizationId, $siteId, IssueKind::Security, self::SOURCE_HTTP, $limit);
    }

    /**
     * @return list<IssueData>
     */
    private function open(string $organizationId, string $siteId, IssueKind $kind, string $source, int $limit): array
    {
        return Issue::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->where('site_id', strtolower($siteId))
            ->where('status', IssueStatus::Open)
            ->where('meta->source', $source)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (Issue $issue) => $issue->toData())
            ->values()
            ->all();
    }
}
