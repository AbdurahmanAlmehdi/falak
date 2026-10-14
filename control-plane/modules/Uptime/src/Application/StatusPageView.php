<?php

namespace Falak\Uptime\Application;

use Carbon\CarbonImmutable;
use Falak\Uptime\Application\Queries\UptimeStats;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Models\StatusPage;

/**
 * What a public status page shows: only the names the user typed, states and percentages. No URL, host, IP, error,
 * status code or site name ever leaves this class.
 */
final class StatusPageView
{
    public function __construct(private readonly UptimeStats $stats) {}

    /**
     * @return array{title: string, description: ?string, overall: string, updated_at: string, interval_seconds: ?int, monitors: list<array<string, mixed>>, incidents: list<array<string, mixed>>}
     */
    public function build(StatusPage $page): array
    {
        $now = CarbonImmutable::now();
        $monitors = $page->monitors()->get();
        $ids = $monitors->modelKeys();
        $days = $this->stats->days($ids, 90, $now);
        $uptime = $this->stats->percentages($ids, $now);
        $names = $monitors->mapWithKeys(fn (Monitor $m) => [$m->id => (string) $m->pivot->getAttribute('display_name')]);

        $rows = $monitors->map(fn (Monitor $m) => [
            'name' => $names[$m->id],
            'state' => self::state($m),
            'uptime_90d' => $uptime[$m->id]['90d'] ?? null,
            'days' => array_map(fn (array $day) => ['date' => $day['date'], 'uptime' => $day['uptime']], $days[$m->id] ?? []),
        ])->values()->all();

        $incidents = Incident::query()->whereIn('monitor_id', $ids)
            ->where('started_at', '>=', $now->subDays((int) config('uptime.status_page.incident_days', 14)))
            ->latest('started_at')->limit(50)->get()
            ->map(fn (Incident $incident) => [
                'name' => $names[$incident->monitor_id] ?? '',
                'started_at' => $incident->started_at->utc()->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->utc()->toIso8601String(),
                'duration_seconds' => $incident->seconds($now),
            ])->values()->all();

        $states = array_column($rows, 'state');

        return [
            'title' => $page->title,
            'description' => $page->description,
            'overall' => match (true) {
                $states === [] => 'unknown',
                in_array('down', $states, true) && count(array_unique($states)) === 1 => 'outage',
                in_array('down', $states, true) => 'partial',
                default => 'operational',
            },
            'updated_at' => $now->utc()->toIso8601String(),
            // The shortest interval among the shown monitors (the page says how often things are checked).
            'interval_seconds' => $monitors->isEmpty() ? null : (int) $monitors->min('interval_seconds'),
            'monitors' => $rows,
            'incidents' => $incidents,
        ];
    }

    private static function state(Monitor $monitor): string
    {
        return match (true) {
            $monitor->isPaused() => 'unknown',
            $monitor->status === MonitorStatus::Down => 'down',
            $monitor->status === MonitorStatus::Up => 'up',
            default => 'unknown',
        };
    }
}
