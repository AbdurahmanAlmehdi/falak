<?php

namespace Falak\Uptime\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Uptime\Application\Actions\DeleteMonitor;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Actions\SyncSiteMonitors;
use Falak\Uptime\Application\Queries\UptimeStats;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Policies\UptimePolicy;
use Falak\Uptime\Domain\Probing\TlsReport;
use Falak\Uptime\Http\Requests\MonitorRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MonitorController extends Controller
{
    public function __construct(
        private readonly UptimeStats $stats,
        private readonly OrganizationAccess $access,
        private readonly CurrentOrganization $organization,
        private readonly SiteDirectory $sites,
        private readonly AuditLog $audit,
    ) {}

    /** /observability/uptime: every monitor of the organization. */
    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::VIEW);

        $monitors = Monitor::query()->where('organization_id', $organizationId)->orderBy('name')->get();
        $sites = collect($this->sites->forOrganization($organizationId))->mapWithKeys(fn ($site) => [$site->id => $site->name]);

        return Inertia::render('Uptime/Index', [
            'monitors' => $this->present($monitors, $sites->all()),
            'incidents' => $this->incidents(Incident::query()->where('organization_id', $organizationId), $monitors),
            'sites' => $sites->map(fn (string $name, string $id) => ['id' => $id, 'name' => $name])->values(),
            'selected' => $request->query('monitor'),
            'limits' => $this->limits(),
            'can' => [
                'manage' => $this->access->can($request->user(), $organizationId, UptimePolicy::MANAGE),
                'publish' => $this->access->can($request->user(), $organizationId, UptimePolicy::PUBLISH),
            ],
        ]);
    }

    /** Site panel Uptime tab (JSON). */
    public function site(Request $request, string $siteId): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::VIEW);
        $site = $this->sites->find($siteId);
        abort_if($site === null || $site->organizationId !== $organizationId, 404);

        $monitors = Monitor::query()->where('organization_id', $organizationId)->where('site_id', $site->id)
            ->orderBy('source')->orderBy('name')->get();

        return response()->json(['data' => [
            'monitors' => $this->present($monitors, [$site->id => $site->name]),
            'incidents' => $this->incidents(Incident::query()->whereIn('monitor_id', $monitors->modelKeys()), $monitors),
            'limits' => $this->limits(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, UptimePolicy::MANAGE)],
        ]]);
    }

    /** One monitor's charts, 90-day bars, incidents and recent failures (JSON). */
    public function data(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorize('view', $monitor);
        $range = in_array($request->query('range'), array_keys(UptimeStats::WINDOWS), true) ? (string) $request->query('range') : '24h';

        return response()->json(['data' => [
            'monitor' => $this->present(collect([$monitor]))[0],
            'range' => $range,
            'series' => $this->stats->series($monitor->id, $range),
            'days' => $this->stats->days([$monitor->id])[$monitor->id],
            'incidents' => $this->incidents(Incident::query()->where('monitor_id', $monitor->id), collect([$monitor]), 50),
            'failures' => Check::query()->where('monitor_id', $monitor->id)->where('ok', false)->latest('checked_at')->limit(20)->get()
                ->map(fn (Check $check) => [
                    'at' => $check->checked_at->toIso8601String(),
                    'error_kind' => $check->error_kind,
                    'error' => $check->error,
                    'status_code' => $check->status_code,
                    'ip' => $check->ip,
                    'attempts' => $check->attempts,
                ])->values(),
        ]]);
    }

    public function store(Request $request, MonitorRules $rules): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::MANAGE);
        $data = $request->validate($rules->target(is_string($request->input('type')) ? $request->input('type') : null));

        if (isset($data['site_id'])) {
            $site = $this->sites->find($data['site_id']);

            if ($site === null || $site->organizationId !== $organizationId) {
                throw ValidationException::withMessages(['site_id' => 'Unknown site.']);
            }
        }

        $max = (int) config('uptime.max_monitors', 50);

        if (Monitor::query()->where('organization_id', $organizationId)->where('source', MonitorSource::User)->count() >= $max) {
            throw ValidationException::withMessages(['name' => "An organization can have at most {$max} monitors (site domains are monitored on their own)."]);
        }

        $type = MonitorType::from($data['type']);
        $monitor = Monitor::query()->create([
            ...$this->settings($data),
            'organization_id' => $organizationId,
            'site_id' => $data['site_id'] ?? null,
            'source' => MonitorSource::User,
            'type' => $type,
            'name' => $data['name'],
            'url' => $type === MonitorType::Http ? $data['url'] : null,
            'host' => $type === MonitorType::Http ? null : trim(strtolower((string) $data['host']), '[]'),
            'port' => match ($type) {
                MonitorType::Http => null,
                MonitorType::Tls => $data['port'] ?? 443,
                MonitorType::Tcp => $data['port'],
            },
            'status' => MonitorStatus::Pending,
            'next_check_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record('uptime.monitor.created', 'uptime_monitor', $monitor->id, ['name' => $monitor->name, 'target' => $monitor->target()], $organizationId);

        return response()->json(['data' => $this->present(collect([$monitor->refresh()]))[0]], 201);
    }

    public function update(Request $request, Monitor $monitor, MonitorRules $rules): JsonResponse
    {
        $this->authorize('update', $monitor);
        $data = $request->validate($rules->settings());

        $monitor->forceFill([...$this->settings($data), ...(isset($data['name']) ? ['name' => $data['name']] : [])])->save();
        $this->audit->record('uptime.monitor.updated', 'uptime_monitor', $monitor->id, ['name' => $monitor->name, 'changes' => array_keys($data)], $monitor->organization_id);

        return response()->json(['data' => $this->present(collect([$monitor]))[0]]);
    }

    public function pause(Request $request, Monitor $monitor, RecordCheck $record): JsonResponse
    {
        $this->authorize('update', $monitor);

        if (! $monitor->isPaused()) {
            $record->stop($monitor, 'paused');
            $monitor->forceFill(['paused_at' => now(), 'status' => MonitorStatus::Pending, 'consecutive_failures' => 0, 'consecutive_successes' => 0, 'status_changed_at' => now()])->save();
            $this->audit->record('uptime.monitor.paused', 'uptime_monitor', $monitor->id, ['name' => $monitor->name], $monitor->organization_id);
        }

        return response()->json(['data' => $this->present(collect([$monitor]))[0]]);
    }

    public function resume(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorize('update', $monitor);

        if ($monitor->isPaused()) {
            // Back as pending: an automatic monitor opens no incident until it has been up again.
            $monitor->forceFill(['paused_at' => null, 'next_check_at' => now(), 'armed' => $monitor->armed && ! $monitor->isAutomatic()])->save();
            $this->audit->record('uptime.monitor.resumed', 'uptime_monitor', $monitor->id, ['name' => $monitor->name], $monitor->organization_id);
        }

        return response()->json(['data' => $this->present(collect([$monitor]))[0]]);
    }

    public function destroy(Monitor $monitor, DeleteMonitor $delete): JsonResponse
    {
        $this->authorize('delete', $monitor);

        if ($monitor->isAutomatic()) {
            throw ValidationException::withMessages(['monitor' => 'Site domains are monitored as long as the domain exists: pause the monitor instead.']);
        }

        $delete($monitor);
        $this->audit->record('uptime.monitor.deleted', 'uptime_monitor', $monitor->id, ['name' => $monitor->name, 'target' => $monitor->target()], $monitor->organization_id);

        return response()->json(null, 204);
    }

    /** Re-reads the site's domains (the panel's "Sync domains" after adding one elsewhere). */
    public function sync(Request $request, SyncSiteMonitors $sync): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::MANAGE);
        $sync->organization($organizationId);

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settings(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['expected_status', 'keyword', 'keyword_absent', 'max_redirects', 'timeout_seconds', 'interval_seconds', 'slow_ms']));

        if (array_key_exists('expected_status', $fields)) {
            $fields['expected_status'] = $fields['expected_status'] ?: '2xx,3xx';
        }

        if (array_key_exists('keyword', $fields)) {
            $fields['keyword'] = $fields['keyword'] === '' ? null : $fields['keyword'];
        }

        return $fields;
    }

    /**
     * @return array{max_monitors: int, min_interval: int, max_interval: int, max_timeout: int, max_redirects: int}
     */
    private function limits(): array
    {
        return [
            'max_monitors' => (int) config('uptime.max_monitors', 50),
            'min_interval' => (int) config('uptime.min_interval', 30),
            'max_interval' => (int) config('uptime.max_interval', 600),
            'max_timeout' => (int) config('uptime.max_timeout', 20),
            'max_redirects' => (int) config('uptime.max_redirects', 5),
        ];
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @param  array<string, string>  $siteNames
     * @return list<array<string, mixed>>
     */
    private function present(Collection $monitors, array $siteNames = []): array
    {
        $uptime = $this->stats->percentages($monitors->map(fn (Monitor $m) => $m->id)->values()->all());

        return $monitors->map(fn (Monitor $m) => [
            'id' => $m->id,
            'name' => $m->name,
            'source' => $m->source->value,
            'type' => $m->type->value,
            'target' => $m->target(),
            'url' => $m->url,
            'host' => $m->host,
            'port' => $m->port,
            'site_id' => $m->site_id,
            'site_name' => $m->site_id ? ($siteNames[$m->site_id] ?? null) : null,
            'expected_status' => $m->expected_status,
            'keyword' => $m->keyword,
            'keyword_absent' => $m->keyword_absent,
            'max_redirects' => $m->max_redirects,
            'timeout_seconds' => $m->timeout_seconds,
            'interval_seconds' => $m->interval_seconds,
            'slow_ms' => $m->slow_ms,
            'slow_threshold_ms' => $m->slowThreshold(),
            'status' => $m->status->value,
            'armed' => $m->armed,
            'paused' => $m->isPaused(),
            'last_checked_at' => $m->last_checked_at?->toIso8601String(),
            'status_changed_at' => $m->status_changed_at?->toIso8601String(),
            'last_total_ms' => $m->last_total_ms,
            'last_status_code' => $m->last_status_code,
            'last_error' => $m->last_error,
            'tls' => $m->tls,
            'tls_problem' => $m->tls !== null && $m->tls_checked_at !== null
                ? RecordCheck::tlsProblem($m, TlsReport::fromArray($m->tls), CarbonImmutable::now()) : null,
            'uptime' => $uptime[$m->id] ?? null,
        ])->values()->all();
    }

    /**
     * @param  Builder<Incident>  $query
     * @param  Collection<int, Monitor>  $monitors
     * @return list<array<string, mixed>>
     */
    private function incidents(Builder $query, Collection $monitors, int $limit = 20): array
    {
        $names = $monitors->mapWithKeys(fn (Monitor $m) => [$m->id => $m->name]);

        return $query->latest('started_at')->limit($limit)->get()->map(fn (Incident $incident) => [
            'id' => $incident->id,
            'monitor_id' => $incident->monitor_id,
            'monitor_name' => $names[$incident->monitor_id] ?? null,
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'duration_seconds' => $incident->seconds(),
            'cause_kind' => $incident->cause_kind,
            'cause' => $incident->cause,
            'cause_status_code' => $incident->cause_status_code,
            'last_error' => $incident->last_error,
            'resolution' => $incident->resolution,
            'issue_id' => $incident->issue_id,
        ])->values()->all();
    }
}
