<?php

namespace Falak\Uptime\Application;

use Carbon\CarbonImmutable;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Illuminate\Support\Facades\DB;

/**
 * Hourly and daily counters per monitor (UTC buckets), incremented by every check: insert the bucket if missing, then
 * one increment (portable across PostgreSQL, MySQL and SQLite, and safe with concurrent checks).
 *
 * Uptime is time-weighted: each check stands for the time since the previous check, at most the monitor's interval
 * ($seconds), so a failing monitor checked every 30 s does not weigh twice as much as a healthy one checked every 60 s.
 */
final class Rollups
{
    public const HOUR = 'hour';

    public const DAY = 'day';

    public function add(string $monitorId, CarbonImmutable $at, ProbeResult $result, bool $down, int $seconds): void
    {
        $seconds = max(0, $seconds);

        $at = $at->utc();

        foreach ([self::HOUR => $at->startOfHour(), self::DAY => $at->startOfDay()] as $period => $bucket) {
            $key = ['monitor_id' => $monitorId, 'period' => $period, 'bucket_start' => $bucket];

            DB::table('uptime_rollups')->insertOrIgnore($key);

            $changes = [
                'checks' => DB::raw('checks + 1'),
                'down_checks' => DB::raw('down_checks + '.($down ? 1 : 0)),
                'seconds' => DB::raw("seconds + {$seconds}"),
                'down_seconds' => DB::raw('down_seconds + '.($down ? $seconds : 0)),
            ];

            if ($result->ok && $result->totalMs !== null) {
                $total = max(0, $result->totalMs);
                $changes += [
                    'latency_sum_ms' => DB::raw("latency_sum_ms + {$total}"),
                    'latency_count' => DB::raw('latency_count + 1'),
                    'latency_max_ms' => DB::raw("CASE WHEN latency_max_ms < {$total} THEN {$total} ELSE latency_max_ms END"),
                    'dns_sum_ms' => DB::raw('dns_sum_ms + '.max(0, (int) $result->dnsMs)),
                    'connect_sum_ms' => DB::raw('connect_sum_ms + '.max(0, (int) $result->connectMs)),
                    'tls_sum_ms' => DB::raw('tls_sum_ms + '.max(0, (int) $result->tlsMs)),
                    'ttfb_sum_ms' => DB::raw('ttfb_sum_ms + '.max(0, (int) $result->ttfbMs)),
                ];
            }

            DB::table('uptime_rollups')->where($key)->update($changes);
        }
    }
}
