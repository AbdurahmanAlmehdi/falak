<?php

namespace Falak\Uptime\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Uptime\Application\Rollups;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Daily: raw checks after uptime.retention.checks_days, hourly rollups after hourly_days, daily ones after daily_days,
 * closed incidents after incidents_days. Deleted in chunks (small locks and WAL bursts).
 */
final class PruneUptime implements ShouldQueue
{
    use Dispatchable, Queueable;

    private const CHUNK = 5000;

    public function handle(): void
    {
        $now = CarbonImmutable::now();
        $retention = (array) config('uptime.retention', []);

        do {
            $ids = Check::query()->where('checked_at', '<', $now->subDays((int) ($retention['checks_days'] ?? 7)))->limit(self::CHUNK)->pluck('id');
            Check::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        foreach ([Rollups::HOUR => $retention['hourly_days'] ?? 30, Rollups::DAY => $retention['daily_days'] ?? 90] as $period => $days) {
            DB::table('uptime_rollups')->where('period', $period)->where('bucket_start', '<', $now->subDays((int) $days)->startOfDay())->delete();
        }

        Incident::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $now->subDays((int) ($retention['incidents_days'] ?? 90)))->delete();
    }
}
