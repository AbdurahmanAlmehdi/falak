<?php

namespace Falak\Uptime\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Probing\Connectivity;
use Falak\Uptime\Application\Probing\Prober;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks one monitor: one attempt, and a second one after uptime.retry_delay_ms when it fails (the check fails only
 * when both do). A failure while the control plane itself is offline (no canary answers) is recorded as unknown.
 * Queued on uptime.queue by {@see DispatchChecks}; at most one per monitor is queued or running.
 */
final class RunCheck implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Worst case: two attempts, each bounded by the monitor's timeout (≤ uptime.max_timeout), TLS inspection included. */
    public int $timeout;

    public function __construct(public string $monitorId)
    {
        $this->timeout = self::timeoutSeconds();
    }

    /** Below the queue's retry_after (90 s), so a slow check is never run twice. */
    public static function timeoutSeconds(): int
    {
        return min(85, 2 * (int) config('uptime.max_timeout', 20) + 15);
    }

    /** How long a claim holds: the job timeout plus queueing slack. Afterwards the monitor is due again. */
    public static function leaseSeconds(): int
    {
        return self::timeoutSeconds() + 15;
    }

    public function uniqueId(): string
    {
        return $this->monitorId;
    }

    public function uniqueFor(): int
    {
        return self::leaseSeconds();
    }

    public function handle(Prober $prober, RecordCheck $record, Connectivity $connectivity): void
    {
        $monitor = Monitor::query()->find($this->monitorId);

        if ($monitor === null) {
            return;
        }

        if ($monitor->isPaused()) {
            $monitor->forceFill(['claimed_at' => null])->save();

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

        if (! $result->ok && ! $connectivity->online()) {
            $record->unknown($monitor);

            return;
        }

        $record($monitor, $result, $attempts);
    }
}
