<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Identifiers;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Another database in a PostgreSQL / MySQL / MariaDB instance (db.create in its container). A Redis / Valkey instance
 * has exactly one keyspace.
 */
final class CreateDatabase
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly CreateDatabaseUser $createUser,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, charset?: ?string, collation?: ?string, site_id?: ?string, user?: ?array{username: string, password?: ?string, host?: ?string}}  $data
     */
    public function __invoke(DatabaseInstance $instance, array $data, ?string $actorId = null): Database
    {
        if ($instance->engine->isKeyValue()) {
            throw ValidationException::withMessages(['name' => "A {$instance->engine->label()} instance has one keyspace: create another instance instead."]);
        }

        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['name' => "The database server is {$instance->status->value}."]);
        }

        $name = $data['name'];
        Identifiers::assertValid($instance->engine, $name, 'name');

        if ($instance->databases()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "A database named \"{$name}\" already exists in {$instance->name}."]);
        }

        $database = DB::transaction(function () use ($instance, $data, $name, $actorId) {
            $database = $instance->databases()->create([
                'organization_id' => $instance->organization_id,
                'server_id' => $instance->server_id,
                'name' => $name,
                'charset' => $instance->engine->isMysqlFamily() ? (($data['charset'] ?? null) ?: $instance->engine->defaultCharset()) : null,
                'collation' => $instance->engine->isMysqlFamily() ? (($data['collation'] ?? null) ?: $instance->engine->defaultCollation()) : null,
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            $this->dispatch($database, background: false);

            return $database;
        });

        $this->audit->record('databases.database_created', 'database', $database->id, ['name' => $name, 'instance_id' => $instance->id], $instance->organization_id);

        // The user's grant on this database is applied once db.create converges.
        if (! empty($data['user']['username'])) {
            ($this->createUser)($instance, [
                'username' => $data['user']['username'],
                'password' => $data['user']['password'] ?? null,
                'host' => $data['user']['host'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'grants' => [['database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]],
            ], $actorId);
        }

        return $database;
    }

    /**
     * Another database for falak.yaml's `databases`: the instance's owner (its oldest user, created with it) gets all
     * privileges on it. While the instance is still starting, the row waits (pending): db.create and the grant follow
     * its first start (HandleCommandOutcome), as for its default database.
     *
     * @throws ValidationException
     */
    public function withOwner(DatabaseInstance $instance, string $name, ?string $actorId = null): Database
    {
        if ($instance->status !== InstanceStatus::Pending) {
            $database = $this($instance, ['name' => $name], $actorId);
        } else {
            if ($instance->engine->isKeyValue()) {
                throw ValidationException::withMessages(['name' => "A {$instance->engine->label()} instance has one keyspace."]);
            }

            Identifiers::assertValid($instance->engine, $name, 'name');

            if ($instance->databases()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => "A database named \"{$name}\" already exists in {$instance->name}."]);
            }

            $database = $instance->databases()->create([
                'organization_id' => $instance->organization_id,
                'server_id' => $instance->server_id,
                'name' => $name,
                'charset' => $instance->engine->defaultCharset(),
                'collation' => $instance->engine->defaultCollation(),
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            $this->audit->record('databases.database_created', 'database', $database->id, ['name' => $name, 'instance_id' => $instance->id], $instance->organization_id);
        }

        $owner = $instance->users()->reorder()->orderBy('created_at')->orderBy('id')->first();
        $owner?->grants()->updateOrCreate(['database_id' => $database->id], ['privileges' => ['ALL PRIVILEGES']]);

        return $database;
    }

    /**
     * db.create for a pending database (a new one, or the default database of an instance that just started).
     *
     * @throws ValidationException when the agent is not connected and not $background
     */
    public function dispatch(Database $database, bool $background = true): void
    {
        $instance = $database->instance;
        $payload = CommandPayloads::create($instance, $database);
        $key = "db.create:{$database->id}:".Str::ulid();
        $timeout = (int) config('databases.timeouts.ddl', 300);

        $handle = $background
            ? $this->commands->tryDispatch($instance->server_id, 'db.create', $payload, $timeout, $key)
            : $this->commands->dispatch($instance->server_id, 'db.create', $payload, $timeout, $key, 'name');

        $database->forceFill($handle !== null
            ? ['command_id' => $handle->id]
            : ['status' => ResourceStatus::Failed, 'status_message' => AgentCommands::NOT_CONNECTED])->save();
    }
}
