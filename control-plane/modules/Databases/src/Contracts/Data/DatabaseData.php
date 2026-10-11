<?php

namespace Falak\Databases\Contracts\Data;

/**
 * A database (a Redis / Valkey instance's keyspace) and the container it lives in.
 */
final readonly class DatabaseData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $name,
        /** mysql | mariadb | postgresql | redis | valkey */
        public string $engine,
        /** The container's major version */
        public ?string $engineVersion,
        /** The engine's port inside its container */
        public int $port,
        /** pending | active | failed | deleting */
        public string $status,
        public ?string $siteId,
        /** The container's memory limit (MB) */
        public ?int $memoryMb = null,
        public ?string $instanceId = null,
        /** healthy | unhealthy | starting | none | stopped | missing (heartbeats), null before the first report */
        public ?string $health = null,
        /** The container's data volume (Volumes) */
        public ?string $volumeId = null,
        /** The container's CPU limit (null = none) */
        public ?float $cpus = null,
        /** The project environment whose network the container joins (null: none) */
        public ?string $environmentId = null,
    ) {}

    public function isKeyValue(): bool
    {
        return in_array($this->engine, ['redis', 'valkey'], true);
    }
}
