<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\Actions\ApplyDatabaseUser;
use Falak\Databases\Application\Actions\ApplyInstance;
use Falak\Databases\Application\Actions\CreateDatabase;
use Falak\Databases\Application\Actions\RunDatabaseScript;
use Falak\Databases\Application\Actions\SettleDrill;
use Falak\Databases\Application\Actions\SettlePitr;
use Falak\Databases\Application\Actions\TakeOverInstance;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Jobs\ForgetPitrHistory;
use Falak\Databases\Application\Jobs\PruneScheduleBackups;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\DatabaseDeleted;
use Falak\Databases\Events\RestoreFinished;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles instances, databases, users, backups, restores and drills when the db.* commands Databases dispatched finish.
 * Backup keys and age identities in their payloads are forgotten then.
 * db.instance.* commands name their instance in the idempotency key (`<type>:<instance id>:…`), so concurrent commands
 * of one instance (a restart during a password rotation) each settle their own part.
 */
final class HandleCommandOutcome implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use SkipsSandboxCommands;

    private const INSTANCE_TYPES = ['db.instance.create', 'db.instance.update', 'db.instance.restart', 'db.instance.delete', 'db.instance.password', 'db.instance.secrets', 'db.instance.upgrade'];

    private const TYPES = ['db.create', 'db.drop', 'db.user.apply', 'db.backup', 'db.restore', 'db.drill', 'db.pitr.base', 'db.pitr.restore', 'db.pitr.promote', ...self::INSTANCE_TYPES];

    /** Payload secrets dropped once a command settled: backup keys, a customer's age identity. */
    private const KEY_PATHS = ['encryption.key', 'encryption.identity'];

    /** db.pitr.restore: every object's key, the identity, the copy's password and its certificate's key. */
    private const PITR_RESTORE_PATHS = ['password', 'identity', 'base.encryption.key', 'segments.*.encryption.key', 'instance.tls.private_key', 'inspection.password'];

    public function __construct(
        private readonly ApplyDatabaseUser $applyUser,
        private readonly ApplyInstance $applyInstance,
        private readonly CreateDatabase $createDatabase,
        private readonly ServiceVolumes $volumes,
        private readonly AgentCommands $commands,
        private readonly AgentGateway $agents,
        private readonly SettleDrill $drills,
        private readonly SettlePitr $pitr,
        private readonly TakeOverInstance $takeOver,
        private readonly AuditLog $audit,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        $this->forgetScriptSecrets($event->type, $event->commandId, $event->idempotencyKey);

        if (in_array($event->type, self::TYPES, true)) {
            $this->settle($event->type, $event->commandId, $event->idempotencyKey, true, null, $event->result);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        $this->forgetScriptSecrets($event->type, $event->commandId, $event->idempotencyKey);

        if (in_array($event->type, self::TYPES, true)) {
            $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
            $this->settle($event->type, $event->commandId, $event->idempotencyKey, false, mb_substr($reason, 0, 1000), $event->result);
        }
    }

    /** A database script's password ({@see RunDatabaseScript}) is not kept once it ran. */
    private function forgetScriptSecrets(string $type, string $commandId, string $key): void
    {
        if ($type === 'system.exec' && str_starts_with($key, RunDatabaseScript::KEY_PREFIX) && ! $this->agents->forgetSecrets($commandId, RunDatabaseScript::SECRET_PATHS)) {
            Log::info('Database script secrets not forgotten yet.', ['command_id' => $commandId]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function settle(string $type, string $commandId, string $key, bool $succeeded, ?string $error, ?array $result): void
    {
        if (in_array($type, self::INSTANCE_TYPES, true)) {
            $instance = DatabaseInstance::query()->find(explode(':', $key)[1] ?? '');

            if ($instance !== null) {
                $this->instance($type, $instance, $commandId, $succeeded, $error, $result ?? []);
            }

            return;
        }

        if (in_array($type, ['db.backup', 'db.restore', 'db.drill', 'db.pitr.base', 'db.pitr.restore'], true)) {
            if (! $this->agents->forgetSecrets($commandId, $type === 'db.pitr.restore' ? self::PITR_RESTORE_PATHS : self::KEY_PATHS)) {
                // Not settled yet as far as Fleet knows: the fleet sweep forgets them later.
                Log::info('Command secrets left for the fleet sweep.', ['command_id' => $commandId]);
            }
        }

        match ($type) {
            'db.create', 'db.drop' => $this->database($commandId, $succeeded, $error),
            'db.user.apply' => $this->user($commandId, $succeeded, $error),
            'db.backup' => $this->backup($commandId, $succeeded, $error, $result ?? []),
            'db.restore' => $this->restore($commandId, $succeeded, $error, $result ?? []),
            'db.drill' => ($this->drills)($commandId, $succeeded, $error, $result ?? []),
            'db.pitr.base' => $this->pitr->base($commandId, $succeeded, $error, $result ?? []),
            'db.pitr.restore' => $this->pitr->restored($commandId, $succeeded, $error, $result ?? []),
            'db.pitr.promote' => $this->pitr->promoted($key, $succeeded, $error),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function instance(string $type, DatabaseInstance $instance, string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $observed = array_filter([
            'image_digest' => is_string($result['image_digest'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/', $result['image_digest']) === 1 ? $result['image_digest'] : null,
            'health' => is_string($result['health'] ?? null) ? $result['health'] : null,
            'health_at' => is_string($result['health'] ?? null) ? now() : null,
        ], fn ($value) => $value !== null);

        match ($type) {
            'db.instance.create' => $this->created($instance, $succeeded, $error, $observed),
            'db.instance.update' => $this->updated($instance, $commandId, $succeeded, $error, $observed),
            'db.instance.restart' => $instance->forceFill([...($succeeded ? $observed : []), 'status_message' => $succeeded ? null : "Restart failed: {$error}"])->save(),
            'db.instance.delete' => $this->deleted($instance, $succeeded, $error),
            'db.instance.password' => $this->passwordRotated($instance, $succeeded, $error, $result),
            'db.instance.secrets' => $succeeded ? null : $instance->forceFill(['status_message' => "Restoring its password file failed: {$error}"])->save(),
            'db.instance.upgrade' => $this->upgraded($instance, $succeeded, $error),
        };
    }

    /**
     * The container runs: SQL instances get their databases (db.create) and then users; Redis / Valkey instances are
     * ready. The target of a major upgrade gets the data instead (db.instance.upgrade).
     *
     * @param  array<string, mixed>  $observed
     */
    private function created(DatabaseInstance $instance, bool $succeeded, ?string $error, array $observed): void
    {
        if ($instance->status !== InstanceStatus::Pending) {
            return;
        }

        $source = $instance->upgrade_of !== null ? DatabaseInstance::query()->find($instance->upgrade_of) : null;

        if (! $succeeded) {
            $instance->forceFill(['status' => InstanceStatus::Failed, 'status_message' => $error])->save();
            $instance->databases()->where('status', ResourceStatus::Pending)->update(['status' => ResourceStatus::Failed, 'status_message' => 'The database server did not start.']);
            $instance->users()->where('status', ResourceStatus::Pending)->update(['status' => ResourceStatus::Failed, 'status_message' => 'The database server did not start.']);
            $source?->forceFill(['status' => InstanceStatus::Active, 'status_message' => "The upgrade failed: the new server did not start ({$error})."])->save();

            return;
        }

        if ($source !== null) {
            $handle = $this->commands->tryDispatch($instance->server_id, 'db.instance.upgrade', CommandPayloads::upgrade($source, $instance), (int) config('databases.timeouts.upgrade', 14400), "db.instance.upgrade:{$instance->id}");
            $instance->forceFill([...$observed, 'command_id' => $handle?->id, 'status_message' => $handle === null ? AgentCommands::NOT_CONNECTED : null, 'status' => $handle === null ? InstanceStatus::Failed : InstanceStatus::Pending])->save();

            if ($handle === null) {
                $source->forceFill(['status' => InstanceStatus::Active, 'status_message' => 'The upgrade failed: '.AgentCommands::NOT_CONNECTED])->save();
            }

            return;
        }

        $instance->forceFill([...$observed, 'status' => InstanceStatus::Active, 'status_message' => null])->save();

        if ($instance->pitr_enabled) {
            $this->pitr->baseAfterTakeOver($instance);
        }

        if ($instance->engine->isKeyValue()) {
            $instance->users()->update(['status' => ResourceStatus::Active, 'status_message' => null]);

            foreach ($instance->databases()->where('status', ResourceStatus::Pending)->get() as $database) {
                $database->forceFill(['status' => ResourceStatus::Active, 'status_message' => null])->save();
                DatabaseCreated::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $instance->engine->value, $database->site_id);
            }

            return;
        }

        foreach ($instance->databases()->where('status', ResourceStatus::Pending)->get() as $database) {
            $this->createDatabase->dispatch($database);
        }

        // Users without grants on pending databases apply now; the others when their database exists.
        $instance->users()->where('status', ResourceStatus::Pending)->get()
            ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));
    }

    private function deleted(DatabaseInstance $instance, bool $succeeded, ?string $error): void
    {
        if ($instance->status !== InstanceStatus::Deleting && $instance->status !== InstanceStatus::Retired) {
            return;
        }

        if (! $succeeded) {
            if ($instance->status === InstanceStatus::Deleting) {
                $instance->forceFill(['status' => $instance->replaced_by !== null ? InstanceStatus::Retired : InstanceStatus::Active, 'status_message' => "Delete failed: {$error}"])->save();
                $instance->databases()->where('status', ResourceStatus::Deleting)->update(['status' => ResourceStatus::Active]);
            } else {
                $instance->forceFill(['status_message' => "Removing the retired container failed: {$error}"])->save();
            }

            return;
        }

        // A retired instance's container went on schedule: the row and its data volume stay until someone deletes them.
        if ($instance->status === InstanceStatus::Retired) {
            $instance->forceFill(['retire_at' => null, 'status_message' => 'Retired by a major upgrade: its container was removed, its data volume is kept. Delete it once you verified the upgraded database.'])->save();

            return;
        }

        $databases = $instance->databases()->get();

        foreach ($databases as $database) {
            $this->volumes->releaseDatabase($database->id);
        }

        if ($instance->delete_volume && $instance->volume_id !== null) {
            $this->volumes->deleteDatabaseVolume($instance->volume_id);
        }

        $instance->delete();
        // Its point-in-time recovery history can't restore anything any more.
        ForgetPitrHistory::dispatch($instance->id);
        $this->audit->record('databases.instance_deleted', 'database_instance', $instance->id, ['name' => $instance->name, 'server_id' => $instance->server_id, 'volume_deleted' => $instance->delete_volume], $instance->organization_id);

        foreach ($databases as $database) {
            DatabaseDeleted::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->site_id);
        }
    }

    /**
     * db.instance.update: what the agent runs is recorded; published addresses being applied become the instance's.
     *
     * @param  array<string, mixed>  $observed
     */
    private function updated(DatabaseInstance $instance, string $commandId, bool $succeeded, ?string $error, array $observed): void
    {
        $network = $instance->network_command_id === $commandId;

        $instance->forceFill([
            ...($succeeded ? $observed : []),
            'status_message' => $succeeded ? null : "Update failed: {$error}",
            ...($network && $succeeded ? ['published_addresses' => $instance->pending_published_addresses ?: null, 'pending_published_addresses' => null] : []),
            ...($network ? ['network_command_id' => null] : []),
        ])->save();
    }

    /**
     * db.instance.password: the new password replaces the stored one once the agent set it. A Redis / Valkey rotation
     * (mode add) keeps the previous one valid until password_overlap_until (MaintainInstances retires it); a retire ends
     * the overlap.
     *
     * @param  array<string, mixed>  $result
     */
    private function passwordRotated(DatabaseInstance $instance, bool $succeeded, ?string $error, array $result = []): void
    {
        if ($instance->next_root_password === null) {
            // A retire (the only password command without a new password) ended the overlap.
            if ($succeeded && $instance->previous_password !== null) {
                $instance->forceFill(['previous_password' => null, 'password_overlap_until' => null])->save();
            }

            return;
        }

        if (! $succeeded) {
            // The agent may have set it already (only the file swap failed): the same password is sent again on retry.
            $instance->forceFill(['status_message' => "Password rotation failed: {$error} Rotate again to finish it."])->save();

            return;
        }

        DB::transaction(function () use ($instance) {
            $password = (string) $instance->next_root_password;
            $instance->forceFill([
                'root_password' => $password,
                'next_root_password' => null,
                'status_message' => null,
                ...($instance->engine->isKeyValue() ? [
                    'previous_password' => $instance->root_password,
                    'password_overlap_until' => now()->addHours((int) config('databases.password_overlap_hours', 24)),
                ] : []),
            ])->save();

            if ($instance->engine->isKeyValue()) {
                $instance->users()->get()->each(fn (DatabaseUser $user) => $user->forceFill(['password' => $password])->save());
            }
        });
    }

    /**
     * A major upgrade copied the data: the new instance takes over the old one's databases, users, schedules, DNS name
     * and host port; the old one is retired: read-only and stopped by the agent, its container removed at retire_at once
     * the new one is healthy, its data volume kept until someone deletes it.
     */
    private function upgraded(DatabaseInstance $target, bool $succeeded, ?string $error): void
    {
        $source = $target->upgrade_of !== null ? DatabaseInstance::query()->find($target->upgrade_of) : null;

        if ($source === null || $target->status !== InstanceStatus::Pending) {
            return;
        }

        if (! $succeeded) {
            $target->forceFill(['status' => InstanceStatus::Failed, 'status_message' => "Copying the data failed: {$error}"])->save();
            $source->forceFill(['status' => InstanceStatus::Active, 'status_message' => "The upgrade to {$target->label()} failed: {$error}"])->save();

            return;
        }

        ($this->takeOver)($source, $target,
            "Replaced by {$target->label()}: read-only and stopped, its data volume kept. Delete it once you verified the upgraded database.",
            now()->addHours((int) config('databases.retire_hours', 24)));

        // Point-in-time recovery starts over on the new instance: its log continues from a base of its own.
        if ($target->refresh()->pitr_enabled) {
            $this->pitr->baseAfterTakeOver($target);
        }

        $this->audit->record('databases.instance_upgrade_finished', 'database_instance', $target->id, ['name' => $target->name, 'version' => $target->version, 'replaced' => $source->id], $target->organization_id);
    }

    private function database(string $commandId, bool $succeeded, ?string $error): void
    {
        $database = Database::query()->with('instance')->where('command_id', $commandId)->first();

        if (! $database) {
            return;
        }

        if ($database->status === ResourceStatus::Deleting) {
            if (! $succeeded) {
                $database->forceFill(['status' => ResourceStatus::Active, 'status_message' => "Drop failed: {$error}"])->save();

                return;
            }

            $userIds = $database->grants()->pluck('user_id')->all();
            $database->delete();
            $this->audit->record('databases.database_deleted', 'database', $database->id, ['name' => $database->name, 'instance_id' => $database->database_instance_id], $database->organization_id);
            DatabaseDeleted::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->site_id);

            DatabaseUser::query()->whereIn('id', $userIds)->where('status', '!=', ResourceStatus::Deleting)->get()
                ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));

            return;
        }

        $database->forceFill([
            'status' => $succeeded ? ResourceStatus::Active : ResourceStatus::Failed,
            'status_message' => $succeeded ? null : $error,
        ])->save();

        if (! $succeeded) {
            return;
        }

        DatabaseCreated::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->instance->engine->value, $database->site_id);

        // Grants on this database could not be applied before it existed.
        Grant::query()->where('database_id', $database->id)->with('user')->get()
            ->map(fn (Grant $grant) => $grant->user)
            ->filter(fn (?DatabaseUser $user) => $user !== null && $user->status !== ResourceStatus::Deleting)
            ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));
    }

    private function user(string $commandId, bool $succeeded, ?string $error): void
    {
        $user = DatabaseUser::query()->where('command_id', $commandId)->first();

        if (! $user) {
            return;
        }

        if ($user->status === ResourceStatus::Deleting) {
            if ($succeeded) {
                $user->delete();
                $this->audit->record('databases.user_deleted', 'database_user', $user->id, ['username' => $user->username, 'instance_id' => $user->database_instance_id], $user->organization_id);
            } else {
                $user->forceFill(['status' => ResourceStatus::Failed, 'status_message' => "Delete failed: {$error}"])->save();
            }

            return;
        }

        $user->forceFill([
            'status' => $succeeded ? ResourceStatus::Active : ResourceStatus::Failed,
            'status_message' => $succeeded ? null : $error,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result  db.backup $defs/result: size_bytes, sha256, location, duration_ms, uncompressed_bytes
     */
    private function backup(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $backup = Backup::query()->where('command_id', $commandId)->first();

        // A late success may overturn a control-plane timeout; anything else is settled once.
        if (! $backup || $backup->status === BackupStatus::Succeeded || $backup->status === BackupStatus::Pruned
            || ($backup->status === BackupStatus::Failed && ! $succeeded)) {
            return;
        }

        $sha = is_string($result['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1 ? $result['sha256'] : null;
        $plainSha = is_string($result['plaintext_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['plaintext_sha256']) === 1 ? $result['plaintext_sha256'] : null;

        if ($succeeded && $sha === null) {
            [$succeeded, $error] = [false, 'The agent reported no checksum for the upload.'];
        } elseif ($succeeded && ($plainSha === null || ($result['key_id'] ?? null) !== $backup->id || ($result['encryption'] ?? null) !== ($backup->isCustomerHeld() ? 'age' : 'cp'))) {
            // An agent that did not encrypt (or not with this backup's key) left a file nobody should trust.
            [$succeeded, $error] = [false, 'The agent did not report the backup as encrypted with its key.'];
        }

        $finishedAt = now();
        $durationMs = isset($result['duration_ms']) ? (int) $result['duration_ms'] : (int) $backup->created_at->diffInMilliseconds($finishedAt);

        if (! $succeeded) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => $finishedAt, 'duration_ms' => $durationMs])->save();
            BackupFailed::dispatch($backup->id, $backup->organization_id, $backup->server_id, $backup->server_name, $backup->database_name, (string) $error, $backup->schedule_id, $backup->trigger);

            return;
        }

        $backup->forceFill([
            'status' => BackupStatus::Succeeded,
            'size_bytes' => (int) ($result['size_bytes'] ?? 0),
            'uncompressed_bytes' => is_int($result['uncompressed_bytes'] ?? null) && $result['uncompressed_bytes'] > 0 ? $result['uncompressed_bytes'] : null,
            'sha256' => $sha,
            'plaintext_sha256' => $plainSha,
            'table_counts' => self::counts($result['table_counts'] ?? null),
            'duration_ms' => $durationMs,
            'error' => null,
            'finished_at' => $finishedAt,
        ])->save();

        BackupSucceeded::dispatch($backup->id, $backup->organization_id, $backup->server_id, $backup->server_name, $backup->database_name, (int) $backup->size_bytes, (string) $sha, $durationMs, $backup->schedule_id, $backup->trigger);

        if ($backup->schedule_id !== null) {
            PruneScheduleBackups::dispatch($backup->schedule_id);
        }
    }

    /**
     * At most 500 tables (the largest), as name => count.
     *
     * @return ?array<string, int>
     */
    private static function counts(mixed $counts): ?array
    {
        if (! is_array($counts) || $counts === []) {
            return null;
        }

        $clean = [];

        foreach ($counts as $name => $count) {
            if (is_string($name) && $name !== '' && strlen($name) <= 200 && is_int($count) && $count >= 0) {
                $clean[$name] = $count;
            }
        }

        arsort($clean);

        return array_slice($clean, 0, 500, true) ?: null;
    }

    /**
     * @param  array<string, mixed>  $result  db.restore $defs/result: bytes, duration_ms, warnings
     */
    private function restore(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $restore = Restore::query()->where('command_id', $commandId)->first();

        if (! $restore || $restore->status === RestoreStatus::Succeeded || ($restore->status === RestoreStatus::Failed && ! $succeeded)) {
            return;
        }

        $warnings = $succeeded ? array_values(array_slice(array_map(
            fn ($warning) => mb_substr((string) $warning, 0, 1000),
            array_filter((array) ($result['warnings'] ?? []), fn ($warning) => is_string($warning) && $warning !== ''),
        ), 0, 10)) : [];

        $restore->forceFill([
            'status' => $succeeded ? RestoreStatus::Succeeded : RestoreStatus::Failed,
            'bytes' => isset($result['bytes']) ? (int) $result['bytes'] : null,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'error' => $succeeded ? null : $error,
            'warnings' => $warnings !== [] ? $warnings : null,
            'finished_at' => now(),
        ])->save();

        // A PostgreSQL restore swaps a new database in: its users' grants on it are applied again.
        $database = $succeeded ? Database::query()->where('database_instance_id', $restore->database_instance_id)->where('name', $restore->database_name)->first() : null;

        if ($database !== null) {
            Grant::query()->where('database_id', $database->id)->with('user')->get()
                ->map(fn (Grant $grant) => $grant->user)
                ->filter(fn (?DatabaseUser $user) => $user !== null && $user->status !== ResourceStatus::Deleting)
                ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));
        }

        $this->audit->record($succeeded ? 'databases.restore_succeeded' : 'databases.restore_failed', 'backup', $restore->backup_id, ['restore_id' => $restore->id, 'database' => $restore->database_name], $restore->organization_id);

        RestoreFinished::dispatch($restore->id, $restore->organization_id, $restore->backup_id, $restore->server_id, $restore->database_name, $succeeded, $succeeded ? null : $error);
    }
}
