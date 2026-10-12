<?php

namespace Falak\Volumes\Http\Controllers;

use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * What a project's falak.yaml manages here is read-only: an edit that changes a managed field answers 422. A unit's
 * volume is the resource `volume` (pointers /volumes/<key>/…, relative to the unit), its schedule `backup_schedule`.
 */
trait GuardsConfigManaged
{
    private function managed(string $type, string $id): ManagedFields
    {
        return app(ManagedResources::class)->managed($type, $id);
    }

    /** The pointer prefix the file manages a resource under (/volumes/<key>), or null. */
    private static function managedPrefix(ManagedFields $managed): ?string
    {
        foreach ($managed->paths as $path) {
            $tokens = Pointer::tokens($path);

            if (($tokens[0] ?? null) === 'volumes' && isset($tokens[1])) {
                return '/volumes/'.Pointer::escape($tokens[1]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fields  input key => pointer relative to the volume (/protected, /size)
     * @param  array<string, mixed>  $current
     */
    private function guardVolume(Volume $volume, array $fields, array $input, array $current): void
    {
        $managed = $this->managed('volume', $volume->id);
        $prefix = self::managedPrefix($managed);

        if ($prefix !== null) {
            $managed->guard(array_map(fn (string $p) => $prefix.$p, $fields), $input, $current);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function guardSchedule(BackupSchedule $schedule, array $input): void
    {
        $managed = $this->managed('backup_schedule', $schedule->id);
        $prefix = self::managedPrefix($managed);

        if ($prefix === null) {
            return;
        }

        $fields = ['storage_provider_id' => '/storage', 'cron' => '/cron', 'retention_count' => '/keep', 'retention_days' => '/keep_days', 'consistency' => '/consistency',
            'enabled' => '/enabled', 'encryption_mode' => '/encryption/mode', 'age_recipient' => '/encryption/recipient', 'drill' => '/drill', 'drill_server_id' => '/drill_server'];

        $managed->guard(array_map(fn (string $p) => "{$prefix}/backups{$p}", $fields), $input, [
            'storage_provider_id' => $schedule->storage_provider_id,
            'cron' => $schedule->cron,
            'retention_count' => $schedule->retention_count,
            'retention_days' => $schedule->retention_days,
            'consistency' => $schedule->consistency,
            'enabled' => $schedule->enabled,
            'encryption_mode' => $schedule->encryption_mode,
            'age_recipient' => $schedule->age_recipient,
            'drill' => $schedule->drill,
            'drill_server_id' => $schedule->drill_server_id,
        ]);
    }

    /** Refuse deleting, moving or unmounting what the file manages as a whole. */
    private function guardManaged(string $type, string $id, string $field, string $what): void
    {
        $managed = $this->managed($type, $id);

        if ($managed->any()) {
            throw ValidationException::withMessages([$field => $managed->badge().": {$what} is declared in the file; change the file instead."]);
        }
    }
}
