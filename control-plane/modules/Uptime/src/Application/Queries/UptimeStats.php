<?php

namespace Falak\Uptime\Application\Queries;

use Carbon\CarbonImmutable;
use Falak\Uptime\Application\Rollups;
use Falak\Uptime\Domain\Models\Check;
use Illuminate\Support\Facades\DB;

/**
 * Uptime % and response-time series from the rollups (and raw checks for the last 24 hours).
 */
final class UptimeStats
{
    /** Window => [period, how far back]. */
    public const WINDOWS = [
        '24h' => [Rollups::HOUR, 'PT24H'],
        '7d' => [Rollups::HOUR, 'P7D'],
        '30d' => [Rollups::DAY, 'P30D'],
        '90d' => [Rollups::DAY, 'P90D'],
    ];

    /**
     * @param  list<string>  $monitorIds
     * @return array<string, array<string, ?float>> monitor id => window => uptime % (null: no checks)
     */
    public function percentages(array $monitorIds, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $out = array_fill_keys($monitorIds, array_fill_keys(array_keys(self::WINDOWS), null));

        if ($monitorIds === []) {
            return $out;
        }

        foreach (self::WINDOWS as $window => [$period, $back]) {
            $since = $now->sub(new \DateInterval($back));
            $since = $period === Rollups::DAY ? $since->addDay()->startOfDay() : $since->startOfHour();

            $rows = DB::table('uptime_rollups')
                ->whereIn('monitor_id', $monitorIds)
                ->where('period', $period)
                ->where('bucket_start', '>=', $since)
                ->groupBy('monitor_id')
                ->selectRaw('monitor_id, SUM(checks) AS checks, SUM(down_checks) AS down_checks')
                ->get();

            foreach ($rows as $row) {
                $out[$row->monitor_id][$window] = self::percent((int) $row->checks, (int) $row->down_checks);
            }
        }

        return $out;
    }

    /**
     * Daily uptime for the last $days days, oldest first (null: no checks that day).
     *
     * @param  list<string>  $monitorIds
     * @return array<string, list<array{date: string, uptime: ?float, down_minutes: int}>>
     */
    public function days(array $monitorIds, int $days = 90, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->utc()->startOfDay();
        $first = $today->subDays($days - 1);
        $rows = $monitorIds === [] ? collect() : DB::table('uptime_rollups')
            ->whereIn('monitor_id', $monitorIds)
            ->where('period', Rollups::DAY)
            ->where('bucket_start', '>=', $first)
            ->get(['monitor_id', 'bucket_start', 'checks', 'down_checks']);

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[$row->monitor_id][CarbonImmutable::parse($row->bucket_start, 'UTC')->toDateString()] = $row;
        }

        $out = [];

        foreach ($monitorIds as $id) {
            for ($day = $first; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
                $row = $byDay[$id][$day->toDateString()] ?? null;
                $checks = (int) ($row->checks ?? 0);
                $down = (int) ($row->down_checks ?? 0);
                $out[$id][] = [
                    'date' => $day->toDateString(),
                    'uptime' => self::percent($checks, $down),
                    // Approximation: each down check stands for its share of the day's checks.
                    'down_minutes' => $checks > 0 ? (int) round($down / $checks * 1440) : 0,
                ];
            }
        }

        return $out;
    }

    /**
     * Response-time series: raw checks for 24h, hourly averages for 7d, daily for 30d / 90d.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function series(string $monitorId, string $range, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        if ($range === '24h') {
            return Check::query()->where('monitor_id', $monitorId)->where('checked_at', '>=', $now->subDay())
                ->orderBy('checked_at')->get()
                ->map(fn (Check $check) => [
                    't' => $check->checked_at->utc()->toIso8601String(),
                    'ok' => $check->ok ? 1 : 0,
                    'dns' => $check->ok ? $check->dns_ms : null,
                    'connect' => $check->ok ? $check->connect_ms : null,
                    'tls' => $check->ok ? $check->tls_ms : null,
                    'ttfb' => $check->ok ? self::wait($check->ttfb_ms, $check->connect_ms, $check->tls_ms) : null,
                    'total' => $check->ok ? $check->total_ms : null,
                ])->values()->all();
        }

        [$period, $back] = self::WINDOWS[$range] ?? self::WINDOWS['7d'];
        $since = $now->sub(new \DateInterval($back));

        return DB::table('uptime_rollups')->where('monitor_id', $monitorId)->where('period', $period)
            ->where('bucket_start', '>=', $period === Rollups::DAY ? $since->startOfDay() : $since->startOfHour())
            ->orderBy('bucket_start')->get()
            ->map(function ($row) {
                $n = max(1, (int) $row->latency_count);
                $has = (int) $row->latency_count > 0;
                $avg = fn (string $column) => $has ? (int) round((int) $row->{$column} / $n) : null;

                return [
                    't' => CarbonImmutable::parse($row->bucket_start, 'UTC')->toIso8601String(),
                    'uptime' => self::percent((int) $row->checks, (int) $row->down_checks),
                    'dns' => $avg('dns_sum_ms'),
                    'connect' => $avg('connect_sum_ms'),
                    'tls' => $avg('tls_sum_ms'),
                    'ttfb' => $has ? self::wait($avg('ttfb_sum_ms'), $avg('connect_sum_ms'), $avg('tls_sum_ms')) : null,
                    'total' => $avg('latency_sum_ms'),
                    'max' => $has ? (int) $row->latency_max_ms : null,
                ];
            })->values()->all();
    }

    public static function percent(int $checks, int $down): ?float
    {
        return $checks > 0 ? round(100 * (1 - $down / $checks), 3) : null;
    }

    /** Server wait: time to first byte minus connect and TLS (stacked in the chart). */
    private static function wait(?int $ttfb, ?int $connect, ?int $tls): ?int
    {
        return $ttfb === null ? null : max(0, $ttfb - (int) $connect - (int) $tls);
    }
}
