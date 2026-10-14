<?php

namespace Falak\Uptime\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Scheduler, every 10 seconds: claims the monitors that are due (moves next_check_at forward with a conditional
 * update, so two runs never claim one twice) and queues a {@see RunCheck} for each on uptime.queue.
 */
final class DispatchChecks implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $queue = (string) config('uptime.queue', 'uptime');
        $dispatched = 0;

        $due = Monitor::query()
            ->whereNull('paused_at')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->orderBy('next_check_at')
            ->limit(max(1, (int) config('uptime.dispatch_batch', 500)))
            ->get(['id', 'next_check_at', 'interval_seconds']);

        foreach ($due as $monitor) {
            $claimed = Monitor::query()->whereKey($monitor->id)
                ->when($monitor->next_check_at === null,
                    fn ($q) => $q->whereNull('next_check_at'),
                    fn ($q) => $q->where('next_check_at', $monitor->next_check_at))
                ->update(['next_check_at' => $now->addSeconds(max(1, $monitor->interval_seconds))]);

            if ($claimed === 1) {
                RunCheck::dispatch($monitor->id)->onQueue($queue);
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
