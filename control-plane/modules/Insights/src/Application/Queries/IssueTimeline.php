<?php

namespace Falak\Insights\Application\Queries;

use Carbon\CarbonImmutable;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Domain\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Charts for the issue detail page: occurrences per hour (exceptions) or the watched metric
 * per bucket (performance issues), over the last 24 hours.
 */
final class IssueTimeline
{
    /**
     * @return list<array{t: string, value: float|int|null, secondary?: float|null}>
     */
    public function __invoke(Issue $issue, ?CarbonImmutable $now = null): array
    {
        $end = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfHour()->addHour();
        $start = $end->subHours(24);
        $buckets = [];

        for ($at = $start; $at->lessThan($end); $at = $at->addHour()) {
            $buckets[$at->timestamp] = ['t' => $at->toIso8601String(), 'value' => 0];
        }

        if ($issue->kind === IssueKind::Exception) {
            $rows = DB::table('insights_exceptions')
                ->where('issue_id', $issue->id)
                ->where('minute', '>=', $start)
                ->where('minute', '<', $end)
                ->groupBy('minute')
                ->selectRaw('minute, COUNT(*) AS total')
                ->get();

            foreach ($rows as $row) {
                $hour = CarbonImmutable::parse($row->minute, 'UTC')->startOfHour()->timestamp;

                if (isset($buckets[$hour])) {
                    $buckets[$hour]['value'] += (int) $row->total;
                }
            }

            return array_values($buckets);
        }

        // A slow endpoint found in the edge's access log: its hourly p95 lives in Loki, not in the Insights aggregates.
        // No data is null, never a p95 of 0.
        if ($issue->kind === IssueKind::Performance && ($issue->meta['source'] ?? null) === 'edge') {
            return array_map(fn (array $bucket) => [...$bucket, 'value' => null], array_values($buckets));
        }

        if ($issue->kind === IssueKind::Performance && $issue->site_id && $issue->event_type !== null && isset($issue->meta['name'])) {
            $rows = DB::table('insights_aggregates')
                ->where('organization_id', $issue->organization_id)
                ->where('site_id', $issue->site_id)
                ->where('event_type', $issue->event_type)
                ->where('name_hash', sha1((string) $issue->meta['name']))
                ->where('minute', '>=', $start)
                ->where('minute', '<', $end)
                ->groupBy('minute')
                ->selectRaw('minute, SUM(count) AS total, SUM(p95_ms * count) AS weighted_p95, MAX(max_ms) AS max_ms')
                ->get();

            $weights = [];

            foreach ($rows as $row) {
                $hour = CarbonImmutable::parse($row->minute, 'UTC')->startOfHour()->timestamp;

                if (! isset($buckets[$hour])) {
                    continue;
                }

                $weights[$hour]['count'] = ($weights[$hour]['count'] ?? 0) + (int) $row->total;
                $weights[$hour]['p95'] = ($weights[$hour]['p95'] ?? 0) + (float) $row->weighted_p95;
                $weights[$hour]['max'] = max($weights[$hour]['max'] ?? 0, (float) $row->max_ms);
            }

            foreach ($buckets as $hour => $bucket) {
                $weight = $weights[$hour] ?? null;
                $buckets[$hour]['value'] = $weight && $weight['count'] > 0 ? round($weight['p95'] / $weight['count'], 1) : null;
                $buckets[$hour]['secondary'] = $weight ? round($weight['max'], 1) : null;
            }
        }

        return array_values($buckets);
    }
}
