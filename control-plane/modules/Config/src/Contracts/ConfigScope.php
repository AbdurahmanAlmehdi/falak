<?php

namespace Falak\Config\Contracts;

/**
 * Where a plan applies: one environment of one project, at one commit of its file. `services` maps the file's unit
 * keys to the environment's canvas services they are (Projects), as Config found them before planning; a unit that
 * phase A creates is added with {@see withService()} so later reconcilers find it.
 */
final readonly class ConfigScope
{
    /**
     * @param  string  $environment  the environment's slug (the key under `environments:`)
     * @param  array<string, array{kind: string, ref_id: string}>  $services  unit key => canvas service (kind site|database, the owning module's id)
     * @param  array<string, string>  $servers  name => id of the servers the project may use (attached to it)
     * @param  list<string>  $defaultServers  ids of the environment's `servers` (every unit's default)
     * @param  ?string  $connectionId  the config source's connection and repository (new units deploy from them)
     * @param  ?string  $branch  the environment's `branch` (new units deploy it)
     * @param  ?string  $actorId  the user the run is recorded as (null: a push)
     * @param  bool  $allowDestroy  the run may delete what the file created and removed (`allow_destroy`, config.destroy);
     *                              otherwise removed units and resources are only detached
     * @param  list<string>  $credentials  names of the organization's certificates, DNS credentials and storage providers an
     *                                     admin allowed this project's file to use (besides those its domains and backups
     *                                     already use)
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $environment,
        public bool $production = false,
        public ?string $commit = null,
        public array $services = [],
        public array $servers = [],
        public array $defaultServers = [],
        public ?string $connectionId = null,
        public ?string $repository = null,
        public ?string $branch = null,
        public ?string $actorId = null,
        public bool $allowDestroy = false,
        public array $credentials = [],
    ) {}

    /** A server id by name (null: not attached to the project). */
    public function serverId(string $name): ?string
    {
        return $this->servers[$name] ?? null;
    }

    /** A server's name by id. */
    public function serverName(string $id): ?string
    {
        $name = array_search($id, $this->servers, true);

        return $name === false ? null : (string) $name;
    }

    /** The unit key of a canvas service by its module id (a site id), or null. */
    public function unitOf(string $refId): ?string
    {
        foreach ($this->services as $unit => $service) {
            if ($service['ref_id'] === $refId) {
                return (string) $unit;
            }
        }

        return null;
    }

    /** The owning module's id of a unit's resource (a site or database instance id), or null when it doesn't exist yet. */
    public function refId(string $unit): ?string
    {
        return $this->services[$unit]['ref_id'] ?? null;
    }

    public function withService(string $unit, string $kind, string $refId): self
    {
        return new self($this->organizationId, $this->projectId, $this->environmentId, $this->environment, $this->production, $this->commit,
            [...$this->services, $unit => ['kind' => $kind, 'ref_id' => $refId]], $this->servers, $this->defaultServers, $this->connectionId,
            $this->repository, $this->branch, $this->actorId, $this->allowDestroy, $this->credentials);
    }
}
