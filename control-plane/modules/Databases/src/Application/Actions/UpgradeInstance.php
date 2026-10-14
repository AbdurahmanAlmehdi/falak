<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\InstancePorts;
use Falak\Databases\Application\QueryInsights;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Version upgrades of a database container.
 *
 * - Same major (the newer build this release pins: security fixes, a new minor) and Redis / Valkey majors (they load
 *   older RDB/AOF files): db.instance.update with the new digest; the agent recreates the container on the same volume.
 * - PostgreSQL / MySQL / MariaDB majors: a new instance of the new major (new volume, same password, settings and
 *   limits). Once it runs, db.instance.upgrade applies the users there, puts the old one in read-only mode (writes fail
 *   until the copy is done: the downtime window), copies every database (logical dump piped into a restore owned by
 *   the app's user) and compares row counts table by table, then moves the DNS name apps use over and stops the old
 *   one. The new instance takes over the old one's databases, users, schedules and host port. The old one is retired:
 *   read-only, stopped, its volume kept until someone deletes it after verifying the new one; only its container goes,
 *   after databases.retire_hours and once the new one is healthy. A failed copy makes the old one writable again.
 */
final class UpgradeInstance
{
    public function __construct(
        private readonly ApplyInstance $apply,
        private readonly CreateInstance $create,
        private readonly ServiceVolumes $volumes,
        private readonly InstancePorts $ports,
        private readonly AuditLog $audit,
        private readonly QueryInsights $queryInsights,
    ) {}

    /**
     * @return DatabaseInstance the instance that runs the new version (a new one for a SQL major)
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, ?string $version = null, ?string $actorId = null): DatabaseInstance
    {
        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['version' => "The database is {$instance->status->value}."]);
        }

        $version = (string) ($version ?: $instance->version);
        $engine = $instance->engine;

        if (! in_array($version, $engine->versions(), true)) {
            throw ValidationException::withMessages(['version' => "{$engine->label()} ".implode(', ', $engine->versions()).' are supported.']);
        }

        if (version_compare($version, $instance->version, '<')) {
            throw ValidationException::withMessages(['version' => 'Databases are never downgraded: restore a backup into a new instance of the older version instead.']);
        }

        CreateInstance::assertPinned($engine, $version);

        if ($version === $instance->version && $engine->pinnedDigest($version) === $instance->image_digest) {
            throw ValidationException::withMessages(['version' => "{$instance->name} already runs the newest {$engine->label()} {$version} build of this release."]);
        }

        if ($version === $instance->version || $engine->isKeyValue()) {
            DB::transaction(function () use ($instance, $engine, $version) {
                $instance->forceFill(['version' => $version, 'image' => $engine->image($version), 'image_digest' => $engine->pinnedDigest($version)])->save();
                ($this->apply)($instance);
            });

            $this->audit->record('databases.instance_upgraded', 'database_instance', $instance->id, ['name' => $instance->name, 'version' => $version, 'mode' => 'in_place'], $instance->organization_id);

            return $instance;
        }

        $target = DB::transaction(function () use ($instance, $engine, $version, $actorId) {
            DatabaseInstance::query()->where('server_id', $instance->server_id)->lockForUpdate()->get(['id']);

            $target = new DatabaseInstance;
            $target->id = strtolower((string) Str::ulid());
            $target->forceFill([
                ...collect($instance->getAttributes())->only(['organization_id', 'server_id', 'server_name', 'environment_id', 'name', 'engine', 'port', 'public_access', 'require_tls', 'memory_bytes', 'cpus', 'pitr_enabled', 'pitr_storage_provider_id', 'pitr_encryption_mode', 'pitr_age_recipient', 'pitr_window_days', 'pitr_base_interval_days'])->all(),
                'settings' => $instance->settings,
                // The new container starts with it (no restart of a running one): kept where it still applies.
                'query_insights' => $instance->query_insights && $this->queryInsights->forNewInstance($engine, (int) $instance->memory_bytes, $instance->server_id),
                'root_password' => $instance->root_password,
                'version' => $version,
                'image' => $engine->image($version),
                'image_digest' => $engine->pinnedDigest($version),
                'hostname' => "falak-db-{$target->id}",
                'host_port' => $this->ports->allocate($instance->server_id),
                'status' => InstanceStatus::Pending,
                'upgrade_of' => $instance->id,
                'created_by' => $actorId,
            ])->save();

            // As large as the old volume: the copy needs the room.
            $disk = max($engine->defaultDisk(), (int) ($instance->volume_id !== null ? $this->volumes->find($instance->volume_id)?->sizeLimitBytes : 0));
            $volume = $this->volumes->createSized($instance->organization_id, $instance->server_id, CreateInstance::volumeName($target), $disk, ['db-instance' => $target->id], protected: true, actorId: $actorId);
            $target->forceFill(['volume_id' => $volume->id])->save();

            $instance->forceFill(['status' => InstanceStatus::Upgrading, 'status_message' => "Upgrading to {$engine->label()} {$version}."])->save();
            // The new volume is attached to the canvas database once the new instance takes over (HandleCommandOutcome).
            $this->create->dispatch($target);

            return $target;
        });

        $this->audit->record('databases.instance_upgraded', 'database_instance', $instance->id, ['name' => $instance->name, 'version' => $version, 'mode' => 'major', 'target' => $target->id], $instance->organization_id);

        return $target;
    }
}
