<?php

namespace Falak\Uptime\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Scheduler, every 10 seconds, on the uptime queue. Fair across organizations: each gets at most
 * uptime.max_in_flight_per_org checks queued or running at once, oldest due first, so one organization whose targets
 * all time out only delays its own checks. A claim moves next_check_at past the job timeout (a lease: a lost job makes
 * the monitor due again) and sets claimed_at; {@see RecordCheck} sets the real next time and clears the claim.
 *
 * Also measures each organization's check delay (how long its oldest due check has waited), kept for the UI and
 * alerted on (uptime.checks_delayed) when it stays above uptime.lag_alert_seconds.
 */
final class DispatchChecks implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const ALERT_DELAYED = 'uptime.checks_delayed';

    public function handle(AlertConditions $conditions): int
    {
        $now = CarbonImmutable::now();
        $queue = (string) config('uptime.queue', 'uptime');
        $lease = RunCheck::leaseSeconds();
        $cap = max(1, (int) config('uptime.max_in_flight_per_org', 2));
        $budget = max(1, (int) config('uptime.dispatch_batch', 500));
        $dispatched = 0;

        $inFlight = Monitor::query()->whereNotNull('claimed_at')->where('claimed_at', '>', $now->subSeconds($lease))
            ->groupBy('organization_id')->selectRaw('organization_id, COUNT(*) AS n')->pluck('n', 'organization_id');

        $oldest = Monitor::query()->whereNull('paused_at')
            ->groupBy('organization_id')
            ->selectRaw('organization_id, MIN(next_check_at) AS oldest, SUM(CASE WHEN next_check_at IS NULL OR next_check_at <= ? THEN 1 ELSE 0 END) AS due', [$now])
            ->get();

        foreach ($oldest->sortBy('oldest') as $row) {
            $this->observeLag($conditions, (string) $row->organization_id, $row->oldest ? CarbonImmutable::parse($row->oldest) : null, $now);

            $free = min($cap - (int) ($inFlight[$row->organization_id] ?? 0), $budget - $dispatched);

            if ((int) $row->due === 0 || $free <= 0) {
                continue;
            }

            $due = Monitor::query()->where('organization_id', $row->organization_id)->whereNull('paused_at')
                ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
                ->orderBy('next_check_at')->limit($free)->get(['id', 'next_check_at']);

            foreach ($due as $monitor) {
                $claimed = Monitor::query()->whereKey($monitor->id)
                    ->when($monitor->next_check_at === null,
                        fn ($q) => $q->whereNull('next_check_at'),
                        fn ($q) => $q->where('next_check_at', $monitor->next_check_at))
                    ->update(['next_check_at' => $now->addSeconds($lease), 'claimed_at' => $now]);

                if ($claimed === 1) {
                    RunCheck::dispatch($monitor->id)->onQueue($queue);
                    $dispatched++;
                }
            }
        }

        return $dispatched;
    }

    /** Seconds the organization's oldest due check has been waiting (0: none waiting), as last measured. */
    public static function lag(string $organizationId): int
    {
        return (int) Cache::get("uptime:lag:{$organizationId}", 0);
    }

    private function observeLag(AlertConditions $conditions, string $organizationId, ?CarbonImmutable $oldest, CarbonImmutable $now): void
    {
        $lag = $oldest !== null && $oldest->lessThan($now) ? (int) $oldest->diffInSeconds($now, true) : 0;
        Cache::put("uptime:lag:{$organizationId}", $lag, 300);

        $limit = max(30, (int) config('uptime.lag_alert_seconds', 120));
        $holds = $lag >= $limit ? true : ($lag < $limit / 2 ? false : null);

        $conditions->observe($organizationId, self::ALERT_DELAYED, $holds, fn () => new AlertData(
            $organizationId, self::ALERT_DELAYED, Severity::Warning, 'Uptime checks are delayed',
            "Checks wait {$lag} s past their time: too many monitors time out for the uptime workers. Lower timeouts, raise intervals or add workers (FALAK_UPTIME_WORKERS).",
            '/observability/uptime', context: ['lag_seconds' => $lag],
        ), forSeconds: 300);
    }

    /**
     * The total load budget used by an organization's active monitors, optionally with one monitor's settings replaced.
     */
    public static function load(string $organizationId, ?Monitor $candidate = null): float
    {
        $total = (float) DB::table('uptime_monitors')->where('organization_id', $organizationId)->whereNull('paused_at')
            ->when($candidate?->exists, fn ($q) => $q->where('id', '!=', $candidate->id))
            ->selectRaw('COALESCE(SUM(2.0 * timeout_seconds / interval_seconds), 0) AS load')->value('load');

        return $total + ($candidate?->cost() ?? 0.0);
    }
}
