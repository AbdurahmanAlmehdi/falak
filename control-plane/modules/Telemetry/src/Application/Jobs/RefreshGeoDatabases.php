<?php

namespace Falak\Telemetry\Application\Jobs;

use Falak\Telemetry\Infrastructure\Geo\GeoDatabases;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Daily: downloads the DB-IP Lite databases that are missing or older than telemetry.geoip.max_age_days (DB-IP
 * publishes monthly). Off with FALAK_GEOIP_ENABLED=false.
 */
final class RefreshGeoDatabases implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 900;

    public function handle(GeoDatabases $geo): void
    {
        if (! (bool) config('telemetry.geoip.enabled', true)) {
            return;
        }

        foreach (GeoDatabases::NAMES as $name) {
            if (! $geo->stale($name)) {
                continue;
            }

            try {
                $geo->download($name);
            } catch (RuntimeException $e) {
                Log::warning($e->getMessage());
            }
        }
    }
}
