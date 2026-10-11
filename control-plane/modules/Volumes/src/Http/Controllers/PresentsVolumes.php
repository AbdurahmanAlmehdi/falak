<?php

namespace Falak\Volumes\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Models\VolumeDrill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait PresentsVolumes
{
    /** @var array<string, array<string, ServerData>> */
    private array $serverNames = [];

    /** @var array<string, array<string, SiteData>> */
    private array $siteNames = [];

    /**
     * @param  Collection<int, Volume>  $volumes  with attachments loaded
     * @return list<array<string, mixed>>
     */
    protected function presentVolumes(Collection $volumes): array
    {
        $ids = $volumes->pluck('id')->all();
        $lastBackups = VolumeBackup::query()
            ->whereIn('volume_id', $ids)
            ->where('status', BackupStatus::Succeeded)
            ->orderByDesc('created_at')
            ->get(['id', 'volume_id', 'created_at', 'size_bytes'])
            ->unique('volume_id')
            ->keyBy('volume_id');
        $schedules = BackupSchedule::query()->whereIn('volume_id', $ids)->where('enabled', true)->pluck('volume_id')->countBy();

        return $volumes->map(fn (Volume $volume) => [
            ...$this->presentVolume($volume),
            'schedules' => (int) ($schedules[$volume->id] ?? 0),
            'last_backup_at' => $lastBackups->get($volume->id)?->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentVolume(Volume $volume): array
    {
        $server = $volume->server_id !== null ? $this->serversOf($volume->organization_id)[$volume->server_id] ?? null : null;

        return [
            'id' => $volume->id,
            'name' => $volume->name,
            'kind' => $volume->kind->value,
            'kind_label' => $volume->kind->label(),
            'server' => $server !== null ? ['id' => $server->id, 'name' => $server->name] : null,
            'docker_name' => $volume->docker_name,
            'host_path' => $volume->host_path,
            'size_limit_bytes' => $volume->size_limit_bytes,
            'used_bytes' => $volume->used_bytes,
            'used_at' => $volume->used_at?->toIso8601String(),
            'usage' => $volume->usage() !== null ? round((float) $volume->usage(), 4) : null,
            'protected' => $volume->protected,
            'status' => $volume->status->value,
            'status_message' => $volume->status_message,
            'labels' => (object) ($volume->labels ?? []),
            'compose' => $volume->composeKey() !== null ? ['site_id' => $volume->composeSiteId(), 'key' => $volume->composeKey(), 'external' => $volume->external()] : null,
            'shared_type' => $volume->kind->value === 'shared_path' ? ($volume->sharedFile() ? 'file' : 'directory') : null,
            'attachments' => $volume->attachments->map(fn (Attachment $attachment) => $this->presentAttachment($volume, $attachment))->values()->all(),
            'url' => "/volumes/{$volume->id}",
            'created_at' => $volume->created_at?->toIso8601String(),
            // What falak.yaml manages (read-only here, with its badge), null when nothing.
            'managed' => app(ManagedResources::class)->managed('volume', $volume->id)->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentAttachment(Volume $volume, Attachment $attachment): array
    {
        $site = $attachment->siteId() !== null ? $this->sitesOf($volume->organization_id)[$attachment->attachable_id] ?? null : null;

        return [
            'id' => $attachment->id,
            'type' => $attachment->attachable_type->value,
            'attachable_id' => $attachment->attachable_id,
            'name' => $site !== null ? ($attachment->service !== null ? "{$site->name} · {$attachment->service}" : $site->name) : ($attachment->service ?? $attachment->attachable_id),
            'service' => $attachment->service,
            'mount_path' => $attachment->mount_path,
            'read_only' => $attachment->read_only,
            'url' => $attachment->attachable_type !== AttachableType::Database
                ? app(ProjectDirectory::class)->serviceUrl(ServiceKind::Site, $attachment->attachable_id)
                : app(ProjectDirectory::class)->serviceUrl(ServiceKind::Database, $attachment->attachable_id),
            // Compose services mount what their file declares; databases keep their data volume.
            'detachable' => $attachment->attachable_type === AttachableType::Site,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBackup(VolumeBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'volume_id' => $backup->volume_id,
            'volume_name' => $backup->volume_name,
            'volume_kind' => $backup->volume_kind->value,
            'server_id' => $backup->server_id,
            'schedule_id' => $backup->schedule_id,
            'storage_provider_id' => $backup->storage_provider_id,
            'trigger' => $backup->trigger,
            'consistency' => $backup->consistency->value,
            'status' => $backup->status->value,
            'size_bytes' => $backup->size_bytes,
            'uncompressed_bytes' => $backup->uncompressed_bytes,
            'volume_size_bytes' => $backup->volume_size_bytes,
            'sha256' => $backup->sha256,
            'plaintext_sha256' => $backup->plaintext_sha256,
            'files' => $backup->files,
            'encryption_mode' => $backup->encryption_mode,
            'cipher' => $backup->cipher,
            'drill_status' => $backup->drill_status,
            'verified_at' => $backup->verified_at?->toIso8601String(),
            'duration_ms' => $backup->duration_ms,
            'error' => $backup->error,
            'restorable' => $backup->restorable(),
            'created_at' => $backup->created_at->toIso8601String(),
            'finished_at' => $backup->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSchedule(BackupSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'managed' => app(ManagedResources::class)->managed('backup_schedule', $schedule->id)->toArray(),
            'storage_provider_id' => $schedule->storage_provider_id,
            'cron' => $schedule->cron,
            'retention_count' => $schedule->retention_count,
            'retention_days' => $schedule->retention_days,
            'consistency' => $schedule->consistency->value,
            'enabled' => $schedule->enabled,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'encryption_mode' => $schedule->encryption_mode,
            'age_recipient' => $schedule->age_recipient,
            'drill' => $schedule->drill->value,
            'drill_server_id' => $schedule->drill_server_id,
            'next_drill_at' => $schedule->next_drill_at?->toIso8601String(),
            'drills' => $schedule->drills()->limit(10)->get()->map(fn (VolumeDrill $drill) => [
                'id' => $drill->id,
                'backup_id' => $drill->backup_id,
                'status' => $drill->status->value,
                'reason' => $drill->reason,
                'error' => $drill->error,
                'checks' => $drill->checks ?? [],
                'duration_ms' => $drill->duration_ms,
                'rto_estimate_seconds' => $drill->rto_estimate_seconds,
                'created_at' => $drill->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentOperation(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'volume_id' => $operation->volume_id,
            'kind' => $operation->kind->value,
            'status' => $operation->status->value,
            'step' => $operation->step,
            'error' => $operation->error,
            'meta' => array_intersect_key((array) $operation->meta, array_flip(['path', 'from', 'to', 'source_id', 'source_name', 'backup_id', 'swap_from'])),
            'result' => array_intersect_key((array) $operation->result, array_flip(['bytes', 'files', 'size_bytes', 'name', 'format', 'redeployed', 'source_kept'])),
            'created_at' => $operation->created_at->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, ServerData>
     */
    protected function serversOf(string $organizationId): array
    {
        return $this->serverNames[$organizationId] ??= collect(app(ServerDirectory::class)->forOrganization($organizationId))->keyBy('id')->all();
    }

    /**
     * @return array<string, SiteData>
     */
    protected function sitesOf(string $organizationId): array
    {
        return $this->siteNames[$organizationId] ??= collect(app(SiteDirectory::class)->forOrganization($organizationId))->keyBy('id')->all();
    }

    /**
     * Inertia forms go back to the page; API-style requests (the service panel) get JSON.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function done(Request $request, ?array $data = null, int $status = 200): RedirectResponse|JsonResponse
    {
        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $data], $status)
            : back();
    }
}
