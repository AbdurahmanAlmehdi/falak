<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\Data\DatabaseInstanceData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;

final class EloquentDatabaseDirectory implements DatabaseDirectory
{
    public function find(string $databaseId): ?DatabaseData
    {
        $database = Database::query()->with('instance')->find($databaseId);

        return $database ? $this->toData($database) : null;
    }

    public function findMany(array $databaseIds): array
    {
        if ($databaseIds === []) {
            return [];
        }

        return Database::query()->with('instance')->whereIn('id', array_values(array_unique($databaseIds)))->get()
            ->mapWithKeys(fn (Database $database) => [$database->id => $this->toData($database)])->all();
    }

    public function forOrganization(string $organizationId): array
    {
        return Database::query()->with('instance')->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    public function forServer(string $serverId): array
    {
        return Database::query()->with('instance')->where('server_id', $serverId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    public function forSite(string $organizationId, string $siteId): array
    {
        return Database::query()->with('instance')->where('organization_id', $organizationId)->where('site_id', $siteId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    public function instance(string $instanceId): ?DatabaseInstanceData
    {
        $instance = DatabaseInstance::query()->find(strtolower($instanceId));

        return $instance ? $this->toInstanceData($instance) : null;
    }

    public function instancesForOrganization(string $organizationId): array
    {
        return DatabaseInstance::query()->where('organization_id', $organizationId)->where('status', '!=', InstanceStatus::Retired->value)->orderBy('name')->get()
            ->map(fn (DatabaseInstance $instance) => $this->toInstanceData($instance))->values()->all();
    }

    private function toInstanceData(DatabaseInstance $instance): DatabaseInstanceData
    {
        return new DatabaseInstanceData(
            id: $instance->id,
            organizationId: $instance->organization_id,
            serverId: $instance->server_id,
            serverName: $instance->server_name,
            name: $instance->name,
            engine: $instance->engine->value,
            version: $instance->version,
            status: $instance->status->value,
            container: $instance->container(),
            hostPort: $instance->host_port,
            publishedAddresses: array_values((array) ($instance->published_addresses ?? [])),
            publicAccess: (bool) $instance->public_access,
        );
    }

    private function toData(Database $database): DatabaseData
    {
        $instance = $database->instance;

        return new DatabaseData(
            id: $database->id,
            organizationId: $database->organization_id,
            serverId: $database->server_id,
            name: $database->name,
            engine: $instance->engine->value,
            engineVersion: $instance->version,
            port: $instance->port,
            status: $database->status->value,
            siteId: $database->site_id,
            memoryMb: intdiv($instance->memory_bytes, 1024 ** 2),
            instanceId: $instance->id,
            health: $instance->health,
            volumeId: $instance->volume_id,
            cpus: $instance->cpus,
            environmentId: $instance->environment_id,
        );
    }
}
