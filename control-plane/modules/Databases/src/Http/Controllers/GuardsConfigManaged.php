<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Application\Actions\UpdateInstance;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Illuminate\Validation\ValidationException;

/**
 * What a project's falak.yaml manages here is read-only: an edit that changes a managed field answers 422 (the
 * dashboard shows those fields with the "Managed by falak.yaml" badge). Resources: database_instance (the unit's
 * fields, pointers relative to the unit), database (one of its `databases`), backup_schedule (its `backups`).
 */
trait GuardsConfigManaged
{
    private function managed(string $type, string $id): ManagedFields
    {
        return app(ManagedResources::class)->managed($type, $id);
    }

    /**
     * Limits, settings and access of an instance (PUT /databases/instances/{instance}).
     *
     * @param  array<string, mixed>  $input
     */
    private function guardInstance(DatabaseInstance $instance, array $input): void
    {
        $managed = $this->managed('database_instance', $instance->id);

        if (! $managed->any()) {
            return;
        }

        if (isset($input['allowed_sources'])) {
            try {
                $input['allowed_sources'] = UpdateInstance::sources((array) $input['allowed_sources']);
            } catch (ValidationException) {
                // the action reports it
            }
        }

        $managed->guard(
            ['memory_mb' => '/memory', 'cpus' => '/cpus', 'public_access' => '/public', 'require_tls' => '/require_tls', 'allowed_sources' => '/allowed_sources'],
            $input,
            [
                'memory_mb' => intdiv((int) $instance->memory_bytes, 1024 ** 2),
                'cpus' => $instance->cpus,
                'public_access' => $instance->public_access,
                'require_tls' => $instance->require_tls,
                'allowed_sources' => array_values((array) $instance->allowed_sources),
            ],
        );

        $errors = [];

        foreach ((array) ($input['settings'] ?? []) as $key => $value) {
            if ($managed->covers('/settings/'.$key) && (string) $value !== (string) ($instance->settings[$key] ?? '')) {
                $errors["settings.{$key}"] = $managed->badge().': edit the file to change it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A backup schedule's fields (the `falak.yaml` schedule of a unit: `backups`).
     *
     * @param  array<string, mixed>  $input
     */
    private function guardSchedule(BackupSchedule $schedule, array $input): void
    {
        $managed = $this->managed('backup_schedule', $schedule->id);

        if (! $managed->any()) {
            return;
        }

        if (array_key_exists('name', $input) && $input['name'] !== $schedule->name) {
            throw ValidationException::withMessages(['name' => $managed->badge().': the file\'s schedule keeps its name.']);
        }

        $ids = fn (array $ids) => collect($ids)->map(fn ($id) => strtolower((string) $id))->sort()->values()->all();

        if (isset($input['database_ids'])) {
            $input['database_ids'] = $ids((array) $input['database_ids']);
        }

        $managed->guard(
            ['storage_provider_id' => '/backups/storage', 'cron' => '/backups/cron', 'retention_count' => '/backups/keep', 'retention_days' => '/backups/keep_days',
                'enabled' => '/backups/enabled', 'encryption_mode' => '/backups/encryption/mode', 'age_recipient' => '/backups/encryption/recipient',
                'drill' => '/backups/drill', 'drill_query' => '/backups/drill_query', 'drill_server_id' => '/backups/drill_server', 'database_ids' => '/backups/databases'],
            $input,
            [
                'storage_provider_id' => $schedule->storage_provider_id,
                'cron' => $schedule->cron,
                'retention_count' => $schedule->retention_count,
                'retention_days' => $schedule->retention_days,
                'enabled' => $schedule->enabled,
                'encryption_mode' => $schedule->encryption_mode,
                'age_recipient' => $schedule->age_recipient,
                'drill' => $schedule->drill,
                'drill_query' => $schedule->drill_query,
                'drill_server_id' => $schedule->drill_server_id,
                'database_ids' => $ids($schedule->databases()->pluck('databases_databases.id')->all()),
            ],
        );
    }

    /**
     * Point-in-time recovery settings (PUT /databases/instances/{instance}/pitr).
     *
     * @param  array<string, mixed>  $input
     */
    private function guardPitr(DatabaseInstance $instance, array $input): void
    {
        $this->managed('database_instance', $instance->id)->guard(
            ['enabled' => '/pitr/enabled', 'storage_provider_id' => '/pitr/storage', 'window_days' => '/pitr/window_days', 'base_interval_days' => '/pitr/base_interval_days',
                'encryption_mode' => '/pitr/encryption/mode', 'age_recipient' => '/pitr/encryption/recipient'],
            $input,
            [
                'enabled' => $instance->pitr_enabled,
                'storage_provider_id' => $instance->pitr_storage_provider_id,
                'window_days' => $instance->pitr_window_days,
                'base_interval_days' => $instance->pitr_base_interval_days,
                'encryption_mode' => $instance->pitr_encryption_mode,
                'age_recipient' => $instance->pitr_age_recipient,
            ],
        );
    }

    /** Refuse deleting (or replacing) something the file manages as a whole. */
    private function guardManaged(string $type, string $id, string $field, string $what): void
    {
        $managed = $this->managed($type, $id);

        if ($managed->any()) {
            throw ValidationException::withMessages([$field => $managed->badge().": {$what} is declared in the file; remove it from the file first (the file never deletes it on its own)."]);
        }
    }
}
