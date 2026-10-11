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
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $environment,
        public bool $production = false,
        public ?string $commit = null,
        public array $services = [],
    ) {}

    /** The owning module's id of a unit's resource (a site or database instance id), or null when it doesn't exist yet. */
    public function refId(string $unit): ?string
    {
        return $this->services[$unit]['ref_id'] ?? null;
    }

    public function withService(string $unit, string $kind, string $refId): self
    {
        return new self($this->organizationId, $this->projectId, $this->environmentId, $this->environment, $this->production, $this->commit,
            [...$this->services, $unit => ['kind' => $kind, 'ref_id' => $refId]]);
    }
}
