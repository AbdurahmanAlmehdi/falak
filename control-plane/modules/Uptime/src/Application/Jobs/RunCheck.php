<?php

namespace Falak\Uptime\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Probing\Prober;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks one monitor: one attempt, and a second one after uptime.retry_delay_ms when it fails (the check fails only
 * when both do). Queued on uptime.queue by {@see DispatchChecks}.
 */
final class RunCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Worst case: two attempts and a TLS inspection, each bounded by the monitor's timeout (≤ uptime.max_timeout). */
    public int $timeout;

    public function __construct(public string $monitorId)
    {
        // Below the queue's retry_after (90 s), so a slow check is never run twice.
        $this->timeout = min(85, 3 * (int) config('uptime.max_timeout', 20) + 15);
    }

    public function handle(Prober $prober, RecordCheck $record): void
    {
        $monitor = Monitor::query()->find($this->monitorId);

        if ($monitor === null || $monitor->isPaused()) {
            return;
        }

        $result = $prober->probe($monitor->probeTarget($monitor->tlsDue(CarbonImmutable::now())));
        $attempts = 1;

        if (! $result->ok) {
            $delay = (int) config('uptime.retry_delay_ms', 1000);

            if ($delay > 0) {
                usleep($delay * 1000);
            }

            $retry = $prober->probe($monitor->probeTarget($monitor->tlsDue(CarbonImmutable::now()) && $result->tls === null));
            $result = $retry->tls === null && $result->tls !== null ? $retry->withTls($result->tls) : $retry;
            $attempts = 2;
        }

        $record($monitor, $result, $attempts);
    }
}
