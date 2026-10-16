<?php

namespace Falak\Sandboxes\Contracts\Data;

final readonly class SandboxData
{
    /**
     * @param  string  $status  creating | running | paused | failed | killed
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $name,
        public string $status,
        public ?string $createdById = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
