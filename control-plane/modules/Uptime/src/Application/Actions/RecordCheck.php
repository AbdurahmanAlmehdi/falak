<?php

namespace Falak\Uptime\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Insights\Contracts\UptimeIssues;
use Falak\Uptime\Application\MonitorLinks;
use Falak\Uptime\Application\Rollups;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\MonitorState;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Domain\Probing\TlsReport;
use Illuminate\Support\Facades\DB;

/**
 * Applies one (confirmed) check to its monitor: the state machine, the check row and rollups, incidents, the
 * monitor's Insights issues and the `uptime.*` alerts (docs/plans/network/uptime.md).
 */
final class RecordCheck
{
    public const ALERT_DOWN = 'uptime.monitor_down';

    public const ALERT_RECOVERED = 'uptime.monitor_recovered';

    public const ALERT_TLS = 'uptime.tls_problem';

    public const ALERT_SLOW = 'uptime.slow';

    public function __construct(
        private readonly Rollups $rollups,
        private readonly UptimeIssues $issues,
        private readonly AlertConditions $conditions,
        private readonly MonitorLinks $links,
    ) {}

    public function __invoke(Monitor $monitor, ProbeResult $result, int $attempts, ?CarbonImmutable $at = null): ?Check
    {
        $at ??= CarbonImmutable::now();

        $outcome = DB::transaction(function () use ($monitor, $result, $attempts, $at) {
            /** @var Monitor|null $locked */
            $locked = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($locked === null || $locked->isPaused()) {
                return null;
            }

            [$state, $transition] = $locked->state()->next(
                $result->ok,
                $locked->isAutomatic(),
                (int) config('uptime.confirm_after', 2),
                (int) config('uptime.recover_after', 2),
            );

            $check = Check::query()->create([
                'monitor_id' => $locked->id,
                'checked_at' => $at,
                'ok' => $result->ok,
                'down' => $state->status === MonitorStatus::Down,
                'status_code' => $result->statusCode,
                'error_kind' => $result->errorKind?->value,
                'error' => $result->error !== null ? mb_substr($result->error, 0, 300) : null,
                'ip' => $result->ip,
                'dns_ms' => $result->dnsMs,
                'connect_ms' => $result->connectMs,
                'tls_ms' => $result->tlsMs,
                'ttfb_ms' => $result->ttfbMs,
                'total_ms' => $result->totalMs,
                'attempts' => max(1, min(255, $attempts)),
            ]);

            $this->rollups->add($locked->id, $at, $result, $state->status === MonitorStatus::Down);
            $incident = $this->applyIncident($locked, $state, $transition, $check, $at);

            $changes = [
                'status' => $state->status,
                'armed' => $state->armed,
                'consecutive_failures' => $state->failures,
                'consecutive_successes' => $state->successes,
                'last_checked_at' => $at,
                'last_total_ms' => $result->totalMs,
                'last_status_code' => $result->statusCode,
                'last_error_kind' => $result->errorKind?->value,
                'last_error' => $check->error,
            ];

            if ($state->status !== $locked->status) {
                $changes['status_changed_at'] = $at;
            }

            if ($result->tls !== null) {
                $changes += ['tls' => $result->tls->toArray(), 'tls_checked_at' => $at];
            }

            if (! $result->ok) {
                $retry = $at->addSeconds(min($locked->interval_seconds, (int) config('uptime.retry_interval', 30)));

                if ($locked->next_check_at === null || $locked->next_check_at->greaterThan($retry)) {
                    $changes['next_check_at'] = $retry;
                }
            }

            if ($transition === MonitorState::CLOSED && $this->recentIncidents($locked, $at) >= (int) config('uptime.flapping.incidents', 3)) {
                $changes['flapping_until'] = $at->addMinutes((int) config('uptime.flapping.hold_minutes', 15));
            }

            $locked->forceFill($changes)->save();

            return [$locked, $check, $transition, $incident];
        });

        if ($outcome === null) {
            return null;
        }

        [$monitor, $check, $transition, $incident] = $outcome;

        $this->announceIncident($monitor, $transition, $incident, $at);
        $this->observeDown($monitor, $at);

        if ($result->ok && $monitor->status === MonitorStatus::Up) {
            $this->observeSlow($monitor);
        }

        if ($result->tls !== null) {
            $this->observeTls($monitor, $result->tls, $at);
        }

        return $check;
    }

    /**
     * Closes the monitor's open incident without a recovery (paused, deleted) and clears its alerts.
     */
    public function stop(Monitor $monitor, string $resolution, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();
        $incident = Incident::query()->where('monitor_id', $monitor->id)->whereNull('resolved_at')->first();

        if ($incident !== null) {
            $incident->forceFill([
                'resolved_at' => $at,
                'duration_seconds' => max(0, (int) $incident->started_at->diffInSeconds($at, true)),
                'resolution' => $resolution,
            ])->save();
        }

        $this->issues->resolve($monitor->organization_id, $monitor->id, 'down');
        $this->issues->resolve($monitor->organization_id, $monitor->id, 'tls');

        $note = $resolution === 'paused' ? 'Monitor paused' : 'Monitor removed';

        foreach (['down', 'slow', 'tls'] as $kind) {
            $this->conditions->observe($monitor->organization_id, self::key($kind, $monitor->id), false,
                fn () => $this->alert($monitor, self::ALERT_DOWN, Severity::Critical, "Down: {$monitor->name}", ''),
                fn () => $this->alert($monitor, self::ALERT_RECOVERED, Severity::Info, "{$note}: {$monitor->name}", 'Checks stopped.'),
                announceRecovery: $kind === 'down');
        }
    }

    public static function key(string $kind, string $monitorId): string
    {
        return "uptime.{$kind}:{$monitorId}";
    }

    private function applyIncident(Monitor $monitor, MonitorState $state, ?string $transition, Check $check, CarbonImmutable $at): ?Incident
    {
        if ($transition === MonitorState::OPENED) {
            // The outage started with the first failed check after the last success.
            $lastOk = Check::query()->where('monitor_id', $monitor->id)->where('ok', true)->where('checked_at', '<', $at)->max('checked_at');
            $first = Check::query()->where('monitor_id', $monitor->id)->where('ok', false)
                ->when($lastOk, fn ($q) => $q->where('checked_at', '>', $lastOk))
                ->orderBy('checked_at')->orderBy('id')->first() ?? $check;

            return Incident::query()->create([
                'organization_id' => $monitor->organization_id,
                'monitor_id' => $monitor->id,
                'started_at' => $first->checked_at,
                'confirmed_at' => $at,
                'cause_kind' => $first->error_kind,
                'cause' => $first->error,
                'cause_status_code' => $first->status_code,
                'last_error' => $check->error,
            ]);
        }

        $incident = Incident::query()->where('monitor_id', $monitor->id)->whereNull('resolved_at')->latest('started_at')->first();

        if ($incident === null) {
            return null;
        }

        if ($transition === MonitorState::CLOSED) {
            $incident->forceFill([
                'resolved_at' => $at,
                'duration_seconds' => max(0, (int) $incident->started_at->diffInSeconds($at, true)),
                'resolution' => 'recovered',
            ])->save();
        } elseif ($state->status === MonitorStatus::Down && ! $check->ok) {
            $incident->forceFill(['last_error' => $check->error])->save();
        }

        return $incident;
    }

    private function announceIncident(Monitor $monitor, ?string $transition, ?Incident $incident, CarbonImmutable $at): void
    {
        if ($transition === MonitorState::OPENED && $incident !== null) {
            $issue = $this->issues->record(
                $monitor->organization_id,
                $monitor->site_id,
                $monitor->id,
                'down',
                "Down: {$monitor->name}",
                $incident->cause ?? 'Check failed',
                $at,
                array_filter(['error' => $incident->cause, 'kind' => $incident->cause_kind, 'status_code' => $incident->cause_status_code, 'incident_id' => $incident->id], fn ($v) => $v !== null),
                ['target' => $monitor->target()],
            );
            $incident->forceFill(['issue_id' => $issue->id])->save();
        } elseif ($transition === MonitorState::CLOSED) {
            $this->issues->resolve($monitor->organization_id, $monitor->id, 'down');
        }
    }

    private function observeDown(Monitor $monitor, CarbonImmutable $at): void
    {
        $flapping = $monitor->flapping_until !== null && $monitor->flapping_until->greaterThan($at);
        $holds = $monitor->status === MonitorStatus::Down || $flapping;

        $this->conditions->observe($monitor->organization_id, self::key('down', $monitor->id), $holds, function () use ($monitor) {
            $incident = Incident::query()->where('monitor_id', $monitor->id)->latest('started_at')->first();

            return $this->alert($monitor, self::ALERT_DOWN, Severity::Critical, "Down: {$monitor->name}",
                trim(($incident?->cause ?? 'Check failed').' · '.$monitor->target()), ['error_kind' => $incident?->cause_kind, 'status_code' => $incident?->cause_status_code]);
        }, function () use ($monitor) {
            $incident = Incident::query()->where('monitor_id', $monitor->id)->latest('started_at')->first();
            $duration = $incident ? self::duration($incident->seconds()) : null;

            return $this->alert($monitor, self::ALERT_RECOVERED, Severity::Info, "Recovered: {$monitor->name}",
                $duration ? "Back up after {$duration}." : 'Back up.');
        });
    }

    private function observeSlow(Monitor $monitor): void
    {
        $samples = Check::query()->where('monitor_id', $monitor->id)->where('ok', true)->whereNotNull('total_ms')
            ->orderByDesc('checked_at')->orderByDesc('id')->limit(5)->pluck('total_ms')->map(fn ($v) => (int) $v)->sort()->values()->all();

        if (count($samples) < 3) {
            return;
        }

        $median = $samples[intdiv(count($samples), 2)];
        $threshold = $monitor->slowThreshold();
        $holds = $median >= $threshold ? true : ($median < $threshold * 0.8 ? false : null);

        $this->conditions->observe($monitor->organization_id, self::key('slow', $monitor->id), $holds,
            fn () => $this->alert($monitor, self::ALERT_SLOW, Severity::Warning, "Slow: {$monitor->name}",
                "Median response time {$median} ms over the last checks (threshold {$threshold} ms).", ['median_ms' => $median, 'threshold_ms' => $threshold]),
            forSeconds: (int) config('uptime.slow_seconds', 300));
    }

    private function observeTls(Monitor $monitor, TlsReport $report, CarbonImmutable $at): void
    {
        $problem = self::tlsProblem($monitor, $report, $at);

        $this->conditions->observe($monitor->organization_id, self::key('tls', $monitor->id), $problem !== null,
            fn () => $this->alert($monitor, self::ALERT_TLS, $problem === TlsReport::EXPIRED ? Severity::Critical : Severity::Warning,
                "Certificate problem: {$monitor->name}", TlsReport::describe((string) $problem).self::expiry($report, $at), ['problem' => $problem]));

        if ($problem !== null) {
            $this->issues->record($monitor->organization_id, $monitor->site_id, $monitor->id, 'tls',
                "Certificate problem: {$monitor->name}", TlsReport::describe($problem), $at,
                ['problem' => $problem, 'issuer' => $report->issuer, 'expires_at' => $report->expiresAt?->format(DATE_ATOM)], ['target' => $monitor->target()]);
        } else {
            $this->issues->resolve($monitor->organization_id, $monitor->id, 'tls');
        }
    }

    /**
     * The chain's problem, or "expiring" for user monitors whose certificate expires within uptime.tls_expiry_days
     * (site domains: Edge renews 30 days ahead and alerts itself; an expired one is reported here too).
     */
    public static function tlsProblem(Monitor $monitor, TlsReport $report, CarbonImmutable $at): ?string
    {
        if ($report->problem !== null) {
            return $report->problem;
        }

        $days = $report->daysLeft($at->toDateTimeImmutable());

        return ! $monitor->isAutomatic() && $days !== null && $days < (int) config('uptime.tls_expiry_days', 14) ? 'expiring' : null;
    }

    private static function expiry(TlsReport $report, CarbonImmutable $at): string
    {
        $days = $report->daysLeft($at->toDateTimeImmutable());

        return $report->expiresAt === null ? '' : ' (expires '.$report->expiresAt->format('Y-m-d').($days !== null && $days >= 0 ? ", in {$days} days" : '').')';
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function alert(Monitor $monitor, string $type, Severity $severity, string $title, string $body, array $context = []): AlertData
    {
        return new AlertData($monitor->organization_id, $type, $severity, $title, $body, $this->links->url($monitor),
            context: array_filter(['monitor_id' => $monitor->id, 'site_id' => $monitor->site_id, ...$context], fn ($v) => $v !== null),
            detail: $monitor->target());
    }

    private function recentIncidents(Monitor $monitor, CarbonImmutable $at): int
    {
        return Incident::query()->where('monitor_id', $monitor->id)
            ->where('started_at', '>=', $at->subMinutes((int) config('uptime.flapping.window_minutes', 60)))->count();
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min',
            $seconds < 86400 => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
            default => intdiv($seconds, 86400).' d '.intdiv($seconds % 86400, 3600).' h',
        };
    }
}
