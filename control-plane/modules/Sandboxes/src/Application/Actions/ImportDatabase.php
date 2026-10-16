<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\Data\DatabaseInstanceData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Copy a database of an instance on the sandbox's own server into the sandbox (sandbox.db.import, sandbox.v2): the
 * instance's logical dump lands at /workspace/db/<database>.dump (postgres, pg_dump -Fc) or .sql (mysql, mariadb).
 * The sandbox never reaches the database; it gets a copy of the data. Who asks needs what downloading a backup needs
 * (the API checks it). Recorded in the exec log and the audit log.
 */
final class ImportDatabase
{
    /** Instance engine (Databases) => the agent's engine. */
    private const ENGINES = ['postgresql' => 'postgres', 'mysql' => 'mysql', 'mariadb' => 'mariadb'];

    public function __construct(
        private readonly SandboxAgent $agent,
        private readonly DatabaseDirectory $databases,
        private readonly ExecInSandbox $exec,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{instance: string, instance_name: string, engine: string, database: string}  $db  from resolve()
     * @return array{path: string, size_bytes: int}
     */
    public function __invoke(Sandbox $sandbox, Actor $actor, array $db): array
    {
        $this->agent->requireV2($sandbox->server_id);
        $payload = self::payload($sandbox, $db);
        $cmd = self::describe($db, $payload['path']);
        $startedAt = now();

        try {
            $result = $this->agent->ask($sandbox, 'sandbox.db.import', $payload, 600);
        } catch (Throwable $e) {
            $this->exec->recordFailure($sandbox, $actor, $cmd, null, [], $startedAt, $e);

            throw $e;
        }

        $this->exec->record($sandbox, $actor, $cmd, null, [], $startedAt, ['exit_code' => 0]);
        $this->recorded($sandbox, $db);

        return ['path' => (string) ($result['path'] ?? $payload['path']), 'size_bytes' => (int) ($result['size_bytes'] ?? 0)];
    }

    /**
     * An instance of the organization (id or name) on $serverId, with a database called $database, whose engine can be
     * copied. Validation errors are under "{$prefix}instance" / "{$prefix}database".
     *
     * @return array{instance: string, instance_name: string, engine: string, database: string, server_id: string}
     */
    public function resolve(string $organizationId, string $instanceRef, string $database, ?string $serverId, string $prefix = ''): array
    {
        $instance = $this->databases->instance($instanceRef);

        if ($instance === null || $instance->organizationId !== $organizationId) {
            $instance = collect($this->databases->instancesForOrganization($organizationId))->first(fn (DatabaseInstanceData $i) => $i->name === $instanceRef);
        }

        if ($instance === null) {
            throw ValidationException::withMessages(["{$prefix}instance" => "No database instance of your organization is called {$instanceRef}."]);
        }

        if (! isset(self::ENGINES[$instance->engine])) {
            throw ValidationException::withMessages(["{$prefix}instance" => 'Only PostgreSQL, MySQL and MariaDB databases can be copied into a sandbox.']);
        }

        if ($serverId !== null && $instance->serverId !== $serverId) {
            throw ValidationException::withMessages(["{$prefix}instance" => "{$instance->name} is on {$instance->serverName}: a sandbox gets a copy only of a database on its own server."]);
        }

        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(["{$prefix}instance" => "{$instance->name} is {$instance->status}, not running."]);
        }

        $exists = collect($this->databases->forServer($instance->serverId))
            ->contains(fn (DatabaseData $d) => $d->instanceId === $instance->id && $d->name === $database);

        if (! $exists) {
            throw ValidationException::withMessages(["{$prefix}database" => "{$instance->name} has no database called {$database}."]);
        }

        return ['instance' => $instance->id, 'instance_name' => $instance->name, 'engine' => self::ENGINES[$instance->engine], 'database' => $database, 'server_id' => $instance->serverId];
    }

    /**
     * @param  array{instance: string, engine: string, database: string}  $db
     * @return array{id: string, instance: string, engine: string, database: string, path: string}
     */
    public static function payload(Sandbox $sandbox, array $db): array
    {
        return [
            'id' => $sandbox->id,
            'instance' => $db['instance'],
            'engine' => $db['engine'],
            'database' => $db['database'],
            'path' => "/workspace/db/{$db['database']}.".($db['engine'] === 'postgres' ? 'dump' : 'sql'),
        ];
    }

    /** @param  array{instance: string, instance_name?: string, database: string}  $db */
    public static function describe(array $db, string $path): string
    {
        return 'db.import '.($db['instance_name'] ?? $db['instance'])."/{$db['database']} → {$path}";
    }

    /** @param  array{instance: string, instance_name?: string, database: string}  $db */
    public function recorded(Sandbox $sandbox, array $db): void
    {
        $this->audit->record('sandbox.db_imported', 'sandbox', $sandbox->id, [
            'name' => $sandbox->name,
            'instance_id' => $db['instance'],
            'instance' => $db['instance_name'] ?? $db['instance'],
            'database' => $db['database'],
        ], $sandbox->organization_id);
    }
}
