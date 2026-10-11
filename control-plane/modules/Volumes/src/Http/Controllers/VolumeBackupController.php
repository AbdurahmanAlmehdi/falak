<?php

namespace Falak\Volumes\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Kernel\Security\BackupKeys;
use Falak\Volumes\Application\Actions\PruneVolumeBackups;
use Falak\Volumes\Application\Actions\RestoreVolumeBackup;
use Falak\Volumes\Application\Actions\RunVolumeBackup;
use Falak\Volumes\Application\Actions\SaveBackupSchedule;
use Falak\Volumes\Application\Actions\StartVolumeDrill;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Policies\VolumePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Volume backups: run one now, schedules with retention, restore into a new volume, delete.
 */
final class VolumeBackupController extends Controller
{
    use GuardsConfigManaged;
    use PresentsVolumes;

    public function store(Request $request, Volume $volume, RunVolumeBackup $run): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);
        $data = $request->validate([
            'storage_provider_id' => ['required', 'string', 'size:26'],
            'consistency' => ['sometimes', Rule::enum(Consistency::class)],
        ]);

        $backup = $run($volume, $data['storage_provider_id'], Consistency::from($data['consistency'] ?? 'none'), 'manual', actorId: $request->user()?->getAuthIdentifier());

        return $this->done($request, $this->presentBackup($backup), 202);
    }

    public function storeSchedule(Request $request, Volume $volume, SaveBackupSchedule $save): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $volume);

        $schedule = $this->save($request, $volume, $save, null);

        return $this->done($request, $this->presentSchedule($schedule), 201);
    }

    public function updateSchedule(Request $request, BackupSchedule $schedule, SaveBackupSchedule $save): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $schedule);
        $this->guardSchedule($schedule, $request->all());

        $schedule = $this->save($request, $schedule->volume, $save, $schedule);

        return $this->done($request, $this->presentSchedule($schedule));
    }

    public function destroySchedule(Request $request, BackupSchedule $schedule, AuditLog $audit): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $schedule);
        $this->guardManaged('backup_schedule', $schedule->id, 'schedule', 'this backup schedule');

        $schedule->delete();
        $audit->record('volumes.backup_schedule_deleted', 'volume', $schedule->volume_id, ['schedule_id' => $schedule->id], $schedule->organization_id);

        return $this->done($request);
    }

    /**
     * POST /volumes/backups/{backup}/restore {server_id, name, size_bytes?, swap?}: into a new volume, never over one.
     */
    public function restore(Request $request, VolumeBackup $backup, RestoreVolumeBackup $restore): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $backup);
        $data = $request->validate([
            'server_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:63'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'swap' => ['sometimes', 'boolean'],
            // Customer-held keys only: used for this restore, never stored.
            'identity' => ['nullable', 'string', 'max:200'],
        ]);

        $operation = $restore($backup, strtolower($data['server_id']), $data['name'], isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            (bool) ($data['swap'] ?? false), $request->user()?->getAuthIdentifier(), $data['identity'] ?? null);

        return $this->done($request, $this->presentOperation($operation), 202);
    }

    /**
     * POST /volumes/backups/{backup}/key: the archive's data key as a falak-restore key file. It opens the volume's
     * data, so it takes the browse permission (admins) and a recent re-authentication, and is audited.
     */
    public function exportKey(VolumeBackup $backup, BackupKeys $keys, AuditLog $audit): JsonResponse
    {
        $this->authorize('browse', $backup);

        if ($backup->isCustomerHeld() || $backup->wrapped_key === null || $backup->encryption_mode !== BackupKeys::CP) {
            throw ValidationException::withMessages(['backup' => $backup->isCustomerHeld()
                ? 'This archive\'s key is customer-held: Falak never had it. Use your age identity with falak-restore.'
                : 'This archive has no key to export.']);
        }

        $key = $keys->unwrap($backup->wrapped_key, $backup->organization_id, $backup->id);

        try {
            $file = BackupKeys::keyFile($backup->id, $key);
        } finally {
            sodium_memzero($key);
        }

        $audit->record('volumes.backup_key_exported', 'volume', (string) $backup->volume_id, ['backup_id' => $backup->id, 'volume' => $backup->volume_name], $backup->organization_id);

        return response()->json(['key_id' => $backup->id, 'filename' => "falak-backup-{$backup->id}.key", 'content' => $file])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /volumes/schedules/{schedule}/drill: a restore drill now. */
    public function drill(Request $request, BackupSchedule $schedule, StartVolumeDrill $start): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $schedule);

        $drill = $start($schedule, true);

        return $this->done($request, ['id' => $drill->id, 'status' => $drill->status->value], 202);
    }

    public function destroy(Request $request, VolumeBackup $backup, PruneVolumeBackups $prune, AuditLog $audit): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $backup);

        if ($backup->status === BackupStatus::Pending) {
            throw ValidationException::withMessages(['backup' => 'The backup is still running.']);
        }

        if ($backup->status === BackupStatus::Succeeded && ! $prune->prune($backup)) {
            throw ValidationException::withMessages(['backup' => (string) $backup->prune_error]);
        }

        $backup->delete();
        $audit->record('volumes.backup_deleted', 'volume', (string) $backup->volume_id, ['backup_id' => $backup->id, 'volume' => $backup->volume_name], $backup->organization_id);

        return $this->done($request);
    }

    private function save(Request $request, Volume $volume, SaveBackupSchedule $save, ?BackupSchedule $schedule): BackupSchedule
    {
        $data = $request->validate([
            'storage_provider_id' => ['required', 'string', 'size:26'],
            'cron' => ['required', 'string', 'max:64'],
            'retention_count' => ['nullable', 'integer', 'between:1,1000'],
            'retention_days' => ['nullable', 'integer', 'between:1,3650'],
            'consistency' => ['sometimes', Rule::enum(Consistency::class)],
            'enabled' => ['sometimes', 'boolean'],
            'encryption_mode' => ['sometimes', 'in:cp,customer'],
            'age_recipient' => ['nullable', 'string', 'max:100'],
            'drill' => ['sometimes', 'in:off,weekly,monthly'],
            'drill_server_id' => ['nullable', 'string', 'size:26'],
        ]);

        // Drills on another server put the restored data there: that takes the browse permission (admins).
        $server = isset($data['drill_server_id']) && $data['drill_server_id'] !== '' ? strtolower((string) $data['drill_server_id']) : null;

        if ($server !== null && $server !== $schedule?->drill_server_id && ! app(OrganizationAccess::class)->can($request->user(), $volume->organization_id, VolumePolicy::BROWSE)) {
            throw ValidationException::withMessages(['drill_server_id' => 'Only admins can run drills on another server (it receives the restored data).']);
        }

        return $save(
            $volume,
            $data['storage_provider_id'],
            $data['cron'],
            isset($data['retention_count']) ? (int) $data['retention_count'] : null,
            isset($data['retention_days']) ? (int) $data['retention_days'] : null,
            Consistency::from($data['consistency'] ?? 'none'),
            (bool) ($data['enabled'] ?? true),
            $schedule,
            $request->user()?->getAuthIdentifier(),
            $data['encryption_mode'] ?? null,
            $data['age_recipient'] ?? null,
            $data['drill'] ?? null,
            array_key_exists('drill_server_id', $data) ? (string) $data['drill_server_id'] : null,
        );
    }
}
