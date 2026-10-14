<?php

namespace Falak\Databases\Contracts\Data;

/**
 * A database container (`falak-db-<id>`) on a server, for other modules (e.g. Terminal's database consoles).
 */
final readonly class DatabaseInstanceData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $name,
        /** mysql | mariadb | postgresql | redis | valkey */
        public string $engine,
        /** The container's major version */
        public string $version,
        /** pending | active | failed | upgrading | retired | deleting | inspecting */
        public string $status,
        /** The Docker container's name */
        public string $container,
        /** The port published on 127.0.0.1 and the private addresses (null once retired) */
        public ?int $hostPort = null,
        /** @var list<string> private addresses the port is published on */
        public array $publishedAddresses = [],
        /** Published to the internet (behind its allowlist) */
        public bool $publicAccess = false,
    ) {}

    /** Its container exists and is meant to run. */
    public function isRunning(): bool
    {
        return $this->status === 'active';
    }
}
