<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Domain\Models\Grant;

/**
 * Builds db.* agent command payloads (contracts/agent-protocol/commands/db.*.schema.json).
 */
final class CommandPayloads
{
    /**
     * db.instance.create / db.instance.update: the instance's full desired state. The password is the superuser's
     * (Redis / Valkey: `default`'s); the agent hands it to the container as a file, never in its environment.
     *
     * The published addresses are the confirmed ones unless $addresses (pending ones being applied) are given; the
     * allowed sources are what the caller last computed (firewall_sources).
     *
     * @param  ?array{certificate: string, private_key: string, ca: string}  $tls
     * @param  ?list<string>  $addresses
     * @return array<string, mixed>
     */
    public static function instance(DatabaseInstance $instance, ?array $tls = null, ?array $addresses = null): array
    {
        $settings = (array) ($instance->settings ?? []);

        if ($instance->require_tls || $instance->public_access) {
            $settings['require_tls'] = true;
        }

        $publish = array_filter([
            'addresses' => array_values($addresses ?? (array) ($instance->published_addresses ?? [])) ?: null,
            'public' => $instance->public_access ?: null,
        ], fn ($value) => $value !== null);

        if ($publish !== []) {
            $publish['allowed_sources'] = array_values((array) ($instance->firewall_sources ?? []));
        }

        return [
            'instance' => array_filter([
                'id' => $instance->id,
                'engine' => $instance->engine->protocol(),
                'version' => $instance->version,
                'image' => $instance->image,
                'digest' => $instance->image_digest,
                'volume_id' => $instance->volume_id,
                'host_port' => $instance->host_port,
                'memory_bytes' => $instance->memory_bytes,
                'cpus' => $instance->cpus,
                'settings' => $settings !== [] ? $settings : null,
                'network' => $instance->network(),
                'aliases' => [$instance->hostname],
                'publish' => $publish !== [] ? $publish : null,
                'tls' => $tls,
                // The agent ships the spool of SQL instances with point-in-time recovery on (and empties it otherwise).
                // Top queries (pg_stat_statements / MariaDB performance_schema) need a restart: omitted unless on, so
                // instances that never had it keep the same spec (agent feature db.observability).
                'query_insights' => $instance->query_insights ? true : null,
                'pitr' => $instance->supportsPitr() ? ['enabled' => $instance->pitr_enabled && $instance->pitr_storage_provider_id !== null] : null,
            ], fn ($value) => $value !== null),
            'password' => $instance->root_password,
        ];
    }

    /**
     * db.pitr.base: a physical base backup of the whole instance to the presigned URL, encrypted like db.backup.
     *
     * @param  array<string, string>  $encryption
     * @return array<string, mixed>
     */
    public static function pitrBase(DatabaseInstance $instance, #[\SensitiveParameter] array $encryption, string $uploadUrl): array
    {
        return [
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            // The agent turns shipping on itself before the base starts (the update saying so may come later).
            'volume_id' => $instance->volume_id,
            'encryption' => $encryption,
            'destination' => ['kind' => 'presigned_url', 'url' => $uploadUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(DatabaseInstance $instance, Database $database): array
    {
        return array_filter([
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'name' => $database->name,
            'charset' => $instance->engine->isMysqlFamily() ? ($database->charset ?: $instance->engine->defaultCharset()) : null,
            'collation' => $instance->engine->isMysqlFamily() ? ($database->collation ?: $instance->engine->defaultCollation()) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function drop(DatabaseInstance $instance, Database $database): array
    {
        return ['instance' => $instance->id, 'engine' => $instance->engine->protocol(), 'name' => $database->name];
    }

    /**
     * Full desired state of a user: grants only cover databases that exist in the instance (active).
     *
     * @return array<string, mixed>
     */
    public static function userPresent(DatabaseInstance $instance, DatabaseUser $user): array
    {
        $payload = [
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'username' => $user->username,
            'password' => $user->password,
            'grants' => self::grants($user),
            'state' => 'present',
        ];

        if ($instance->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        return $payload;
    }

    /**
     * @return list<array{database: string, privileges: list<string>}>
     */
    public static function grants(DatabaseUser $user, bool $activeOnly = true): array
    {
        return $user->grants()
            ->with('database')
            ->get()
            ->filter(fn (Grant $grant) => $grant->database !== null && (! $activeOnly || $grant->database->status === ResourceStatus::Active))
            ->sortBy(fn (Grant $grant) => $grant->database->name)
            ->map(fn (Grant $grant) => ['database' => $grant->database->name, 'privileges' => array_values($grant->privileges ?: ['ALL PRIVILEGES'])])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function userAbsent(DatabaseInstance $instance, DatabaseUser $user): array
    {
        $payload = ['instance' => $instance->id, 'engine' => $instance->engine->protocol(), 'username' => $user->username, 'state' => 'absent'];

        if ($instance->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        return $payload;
    }

    /**
     * db.backup: the dump goes through zstd and AES-256-GCM (FKB1) with $encryption (BackupKeys: the data key, or the
     * customer's age recipient), straight to the presigned URL.
     *
     * @param  array<string, string>  $encryption
     * @return array<string, mixed>
     */
    public static function backup(DatabaseInstance $instance, string $database, #[\SensitiveParameter] array $encryption, string $uploadUrl, bool $tableCounts = false): array
    {
        return array_filter([
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'database' => $database,
            'encryption' => $encryption,
            'table_counts' => $tableCounts ?: null,
            'destination' => ['kind' => 'presigned_url', 'url' => $uploadUrl],
        ], fn ($value) => $value !== null);
    }

    /**
     * db.restore: the agent checks the file's sha256, opens it with $encryption and checks the dump's sha256.
     *
     * @param  array<string, string>  $encryption
     * @return array<string, mixed>
     */
    public static function restore(DatabaseInstance $instance, string $database, Backup $backup, #[\SensitiveParameter] array $encryption, string $downloadUrl): array
    {
        $row = $instance->databases()->where('name', $database)->first();

        return array_filter([
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'database' => $database,
            'encryption' => $encryption,
            'source' => ['kind' => 'url', 'url' => $downloadUrl],
            'sha256' => $backup->sha256,
            'plaintext_sha256' => $backup->plaintext_sha256,
            'archive_bytes' => $backup->size_bytes,
            'owner' => $row !== null ? self::owner($instance, $row) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * db.drill: restore $backup into a throwaway container of $instance's image digest and check it.
     *
     * @param  array<string, string>  $encryption
     * @return array<string, mixed>
     */
    public static function drill(Drill $drill, DatabaseInstance $instance, Backup $backup, #[\SensitiveParameter] array $encryption, string $downloadUrl, ?string $query): array
    {
        $memory = min((int) $instance->memory_bytes, (int) config('databases.drills.memory_bytes', 512 * 1024 ** 2));
        $memory = max($memory, (int) (config('databases.memory.min.'.$instance->engine->value) ?? 256 * 1024 ** 2));

        return [
            'drill' => $drill->id,
            'instance' => [
                'engine' => $instance->engine->protocol(),
                'version' => $instance->version,
                'image' => $instance->image,
                'digest' => $instance->image_digest,
                'memory_bytes' => $memory,
                'cpus' => (float) config('databases.drills.cpus', 1),
            ],
            'database' => $backup->database_name,
            'source' => ['kind' => 'url', 'url' => $downloadUrl],
            'sha256' => $backup->sha256,
            ...array_filter([
                'plaintext_sha256' => $backup->plaintext_sha256,
                'archive_bytes' => $backup->size_bytes,
                'uncompressed_bytes' => $backup->uncompressed_bytes,
            ], fn ($value) => $value !== null),
            'encryption' => $encryption,
            'checks' => array_filter([
                'table_counts' => $backup->table_counts ?: null,
                'tolerance_percent' => (float) config('databases.drills.tolerance_percent', 10),
                'query' => $instance->engine->isKeyValue() ? null : ($query ?: null),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * PostgreSQL: the role that owns a database's objects after a restore or a copy (pg_restore --no-owner leaves them to
     * the superuser, and the app's migrations then fail): the oldest user with all privileges on it.
     */
    public static function owner(DatabaseInstance $instance, Database $database): ?string
    {
        if ($instance->engine !== Engine::PostgreSql) {
            return null;
        }

        return Grant::query()->with('user')->where('database_id', $database->id)->get()
            ->filter(fn (Grant $grant) => $grant->user !== null && $grant->user->status !== ResourceStatus::Deleting && in_array('ALL PRIVILEGES', (array) $grant->privileges, true))
            ->sortBy(fn (Grant $grant) => [$grant->user->created_at, $grant->user->id])
            ->first()?->user->username;
    }

    /**
     * db.instance.upgrade (major): copy every database of $source into $target (a new instance of the new major), apply
     * the users there, then move the DNS name apps use over to $target and stop $source.
     *
     * @return array<string, mixed>
     */
    public static function upgrade(DatabaseInstance $source, DatabaseInstance $target): array
    {
        $sql = ! $source->engine->isKeyValue();

        return array_filter([
            'mode' => 'major',
            'source' => ['id' => $source->id, 'engine' => $source->engine->protocol()],
            'target' => ['id' => $target->id, 'engine' => $target->engine->protocol()],
            'databases' => $sql ? $source->databases()->where('status', ResourceStatus::Active)->get()
                ->map(fn (Database $database) => array_filter([
                    'name' => $database->name,
                    'charset' => $source->engine->isMysqlFamily() ? ($database->charset ?: $source->engine->defaultCharset()) : null,
                    'collation' => $source->engine->isMysqlFamily() ? ($database->collation ?: $source->engine->defaultCollation()) : null,
                    'owner' => self::owner($source, $database),
                ], fn ($value) => $value !== null))->values()->all() : [],
            'users' => $sql ? $source->users()->where('status', '!=', ResourceStatus::Deleting)->get()
                ->map(fn (DatabaseUser $user) => array_filter([
                    'username' => $user->username,
                    'password' => $user->password,
                    'host' => $source->engine->isMysqlFamily() ? $user->host : null,
                    'grants' => self::grants($user),
                ], fn ($value) => $value !== null))->values()->all() : [],
            'network' => $source->network(),
            'alias' => $source->hostname,
        ], fn ($value) => $value !== null);
    }
}
