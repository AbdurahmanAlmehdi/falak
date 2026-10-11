<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Application\Actions\RestoreToTime;
use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Array shapes sent to the Inertia pages (never secrets).
 */
trait PresentsDatabases
{
    /**
     * @return array<string, mixed>
     */
    protected function presentInstance(DatabaseInstance $instance): array
    {
        return [
            'id' => $instance->id,
            'name' => $instance->name,
            'server_id' => $instance->server_id,
            'server_name' => $instance->server_name,
            'environment_id' => $instance->environment_id,
            'engine' => $instance->engine->value,
            'engine_label' => $instance->engine->label(),
            'kind' => $instance->engine->kind()->value,
            'version' => $instance->version,
            'image' => $instance->image,
            'image_digest' => $instance->image_digest,
            'hostname' => $instance->hostname,
            'port' => $instance->port,
            'host_port' => $instance->host_port,
            'published_addresses' => array_values((array) ($instance->published_addresses ?? [])),
            // Waiting to be applied: a restart (new container) is required.
            'pending_published_addresses' => $instance->pending_published_addresses !== null ? array_values($instance->pending_published_addresses) : null,
            'allowed_sources' => array_values((array) ($instance->allowed_sources ?? [])),
            'password_overlap_until' => $instance->password_overlap_until?->toIso8601String(),
            'replaced_by' => $instance->replaced_by,
            'public_access' => $instance->public_access,
            'require_tls' => $instance->require_tls,
            'memory_mb' => intdiv($instance->memory_bytes, 1024 ** 2),
            'cpus' => $instance->cpus,
            'settings' => (object) ($instance->settings ?? []),
            'pitr_enabled' => $instance->pitr_enabled,
            'restored_from' => $instance->restored_from,
            'volume_id' => $instance->volume_id,
            'tls_expires_at' => $instance->tls_expires_at?->toIso8601String(),
            'status' => $instance->status->value,
            'status_message' => $instance->status_message,
            'health' => $instance->health,
            'health_at' => $instance->health_at?->toIso8601String(),
            'rotating_password' => $instance->next_root_password !== null,
            'upgrade_of' => $instance->upgrade_of,
            'retire_at' => $instance->retire_at?->toIso8601String(),
            'databases_count' => $instance->databases_count ?? null,
            'users_count' => $instance->users_count ?? null,
            'created_at' => $instance->created_at->toIso8601String(),
            // What falak.yaml manages (read-only here, with its badge), null when nothing.
            'managed' => app(ManagedResources::class)->managed('database_instance', $instance->id)->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDatabase(Database $database): array
    {
        return [
            'id' => $database->id,
            'instance_id' => $database->database_instance_id,
            'name' => $database->name,
            'charset' => $database->charset,
            'collation' => $database->collation,
            'site_id' => $database->site_id,
            'status' => $database->status->value,
            'status_message' => $database->status_message,
            'command_id' => $database->command_id,
            'created_at' => $database->created_at->toIso8601String(),
            'managed' => app(ManagedResources::class)->managed('database', $database->id)->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentUser(DatabaseUser $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'host' => $user->host,
            'site_id' => $user->site_id,
            'status' => $user->status->value,
            'status_message' => $user->status_message,
            'command_id' => $user->command_id,
            'grants' => $user->grants->map(fn (Grant $grant) => [
                'database_id' => $grant->database_id,
                'database' => $grant->database?->name,
                'privileges' => $grant->privileges,
            ])->sortBy('database')->values(),
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSchedule(BackupSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'managed' => app(ManagedResources::class)->managed('backup_schedule', $schedule->id)->toArray(),
            'cron' => $schedule->cron,
            'storage_provider_id' => $schedule->storage_provider_id,
            'storage_provider' => $schedule->storageProvider?->name,
            'database_ids' => $schedule->databases->pluck('id')->values(),
            'databases' => $schedule->databases->pluck('name')->values(),
            'retention_count' => $schedule->retention_count,
            'retention_days' => $schedule->retention_days,
            'enabled' => $schedule->enabled,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'encryption_mode' => $schedule->encryption_mode,
            'age_recipient' => $schedule->age_recipient,
            'drill' => $schedule->drill->value,
            'drill_query' => $schedule->drill_query,
            'drill_server_id' => $schedule->drill_server_id,
            'last_drill_at' => $schedule->last_drill_at?->toIso8601String(),
            'next_drill_at' => $schedule->next_drill_at?->toIso8601String(),
            'drills' => $schedule->drills()->limit(10)->get()->map(fn (Drill $drill) => $this->presentDrill($drill))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDrill(Drill $drill): array
    {
        return [
            'id' => $drill->id,
            'backup_id' => $drill->backup_id,
            'database_name' => $drill->database_name,
            'server_name' => $drill->server_name,
            'status' => $drill->status->value,
            'reason' => $drill->reason,
            'error' => $drill->error,
            'checks' => $drill->checks ?? [],
            'duration_ms' => $drill->duration_ms,
            'rto_estimate_seconds' => $drill->rto_estimate_seconds,
            'created_at' => $drill->created_at->toIso8601String(),
            'finished_at' => $drill->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBackup(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'database_name' => $backup->database_name,
            'server_id' => $backup->server_id,
            'server_name' => $backup->server_name,
            'instance_id' => $backup->database_instance_id,
            'instance_name' => $backup->instance_name,
            'engine' => $backup->engine->value,
            'engine_version' => $backup->engine_version,
            'storage_provider' => $backup->storageProvider?->name,
            'object_key' => $backup->object_key,
            'compression' => $backup->compression->value,
            'encryption_mode' => $backup->encryption_mode,
            'cipher' => $backup->cipher,
            'plaintext_sha256' => $backup->plaintext_sha256,
            'drill_status' => $backup->drill_status,
            'verified_at' => $backup->verified_at?->toIso8601String(),
            'trigger' => $backup->trigger,
            'schedule_id' => $backup->schedule_id,
            'status' => $backup->status->value,
            'size_bytes' => $backup->size_bytes,
            'sha256' => $backup->sha256,
            'duration_ms' => $backup->duration_ms,
            'error' => $backup->error,
            'prune_error' => $backup->prune_error,
            'command_id' => $backup->command_id,
            'restorable' => $backup->isRestorable() && ! $backup->isBase(),
            'type' => $backup->type,
            'log_start' => $backup->log_start,
            'base_started_at' => $backup->base_started_at?->toIso8601ZuluString('millisecond'),
            'base_finished_at' => $backup->base_finished_at?->toIso8601ZuluString('millisecond'),
            'created_at' => $backup->created_at->toIso8601String(),
            'started_at' => $backup->started_at?->toIso8601String(),
            'finished_at' => $backup->finished_at?->toIso8601String(),
            'pruned_at' => $backup->pruned_at?->toIso8601String(),
        ];
    }

    /**
     * The organization's other servers a schedule may run its restore drills on.
     *
     * @return list<array{id: string, name: string}>
     */
    protected function drillServers(DatabaseInstance $instance): array
    {
        return array_values(array_map(
            fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name],
            array_filter(app(ServerDirectory::class)->forOrganization($instance->organization_id, activeOnly: true), fn (ServerData $server) => $server->id !== $instance->server_id),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRestore(Restore $restore): array
    {
        return [
            'id' => $restore->id,
            'type' => $restore->type,
            'backup_id' => $restore->backup_id,
            'target_time' => $restore->target_time?->toIso8601ZuluString('millisecond'),
            'source_database' => $restore->backup->database_name,
            'database_name' => $restore->database_name,
            'status' => $restore->status->value,
            'bytes' => $restore->bytes,
            'duration_ms' => $restore->duration_ms,
            'error' => $restore->error,
            'warnings' => $restore->warnings ?? [],
            'command_id' => $restore->command_id,
            'created_at' => $restore->created_at->toIso8601String(),
            'finished_at' => $restore->finished_at?->toIso8601String(),
        ];
    }

    /**
     * An instance's point-in-time recovery: settings, the spool as the agent last reported it, the timeline (recovery
     * ranges and gaps), recent bases, and its restores to a time with their read-only copies (connection details on the
     * server only, row counts) while they wait for a decision.
     *
     * @return array<string, mixed>
     */
    protected function presentPitr(DatabaseInstance $instance): array
    {
        $bases = Backup::query()->with('storageProvider')->where('database_instance_id', $instance->id)->where('type', Backup::BASE)
            ->latest()->orderByDesc('id')->limit(10)->get();
        $restores = Restore::query()->where('source_instance_id', $instance->id)->where('type', Restore::PITR)->latest()->orderByDesc('id')->limit(10)->get();
        $copies = DatabaseInstance::query()->whereIn('id', $restores->pluck('restored_instance_id')->filter()->all())->get()->keyBy('id');

        return [
            'supported' => $instance->supportsPitr(),
            'enabled' => $instance->pitr_enabled,
            'storage_provider_id' => $instance->pitr_storage_provider_id,
            'encryption_mode' => $instance->pitr_encryption_mode,
            'age_recipient' => $instance->pitr_age_recipient,
            'window_days' => $instance->pitr_window_days,
            'base_interval_days' => $instance->pitr_base_interval_days,
            'next_base_at' => $instance->pitr_next_base_at?->toIso8601String(),
            'last_shipped_at' => $instance->pitr_last_shipped_at?->toIso8601String(),
            'report' => $instance->pitr_report,
            // MySQL / MariaDB binlogs have whole seconds: a restore lands on the second before.
            'second_precision' => $instance->engine->isMysqlFamily(),
            'timeline' => $instance->supportsPitr() ? app(PitrTimeline::class)->for($instance) : null,
            'bases' => $bases->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'restores' => $restores->map(function (Restore $restore) use ($copies) {
                /** @var ?DatabaseInstance $copy */
                $copy = $copies->get((string) $restore->restored_instance_id);

                return [
                    'id' => $restore->id,
                    'status' => $restore->status->value,
                    'target_time' => $restore->target_time?->toIso8601ZuluString('millisecond'),
                    // Recovered to the end of the shipped log (target_time: how far it reached).
                    'to_latest' => $restore->to_latest,
                    'decision' => $restore->decision,
                    'error' => $restore->error,
                    'warnings' => $restore->warnings ?? [],
                    'segments' => $restore->segments,
                    'table_counts' => (object) ($restore->table_counts ?? []),
                    'duration_ms' => $restore->duration_ms,
                    'created_at' => $restore->created_at->toIso8601String(),
                    'finished_at' => $restore->finished_at?->toIso8601String(),
                    'copy' => $copy === null ? null : [
                        'id' => $copy->id,
                        'name' => $copy->name,
                        'status' => $copy->status->value,
                        'status_message' => $copy->status_message,
                        'server_name' => $copy->server_name,
                        // Published on the server's loopback only, on no network: reach it from the server (an SSH tunnel).
                        'host' => '127.0.0.1',
                        'port' => $copy->host_port,
                        // The read-only account (its password: POST pitr-restores/{restore}/inspection).
                        'username' => RestoreToTime::INSPECTION_USER,
                    ],
                ];
            })->values(),
        ];
    }

    /**
     * Running instances a backup of the source's engine can be restored into (RestoreBackup::compatible), with their
     * active databases (a restore goes into an existing one).
     *
     * @return list<array{id: string, label: string, engine: string, databases: list<string>}>
     */
    protected function restoreTargets(DatabaseInstance $source): array
    {
        $probe = new Backup(['engine' => $source->engine, 'engine_version' => $source->version]);

        return DatabaseInstance::query()->where('organization_id', $source->organization_id)->where('status', InstanceStatus::Active)->orderBy('name')->get()
            ->filter(fn (DatabaseInstance $target) => RestoreBackup::compatible($probe, $target))
            ->map(fn (DatabaseInstance $target) => [
                'id' => $target->id,
                'label' => "{$target->name} ({$target->label()} on {$target->server_name})",
                'engine' => $target->engine->value,
                'databases' => $target->databases()->where('status', 'active')->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentProvider(StorageProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'driver' => $provider->driver->value,
            'driver_label' => $provider->driver->label(),
            'endpoint' => $provider->endpoint,
            'region' => $provider->region,
            'bucket' => $provider->bucket,
            'prefix' => $provider->prefix,
            'path_style' => $provider->path_style,
            // Only a hint of which key is configured; the secret never leaves the server.
            'access_key_hint' => '…'.substr($provider->access_key_id, -4),
            'verified_at' => $provider->verified_at?->toIso8601String(),
            'created_at' => $provider->created_at->toIso8601String(),
        ];
    }
}
