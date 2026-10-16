<?php

namespace Falak\Sandboxes\Infrastructure;

use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\Data\CapacityItem;
use Falak\Sandboxes\Domain\Models\Sandbox;

/**
 * Live sandboxes on a server, each with its memory and CPU limits (a sandbox's memory is a hard cgroup limit).
 */
final class SandboxesCapacity implements CapacitySource
{
    public function onServer(string $organizationId, string $serverId): array
    {
        return Sandbox::query()->where('organization_id', $organizationId)->where('server_id', $serverId)->live()->orderBy('name')->get()
            ->map(fn (Sandbox $sandbox) => new CapacityItem('sandbox', $sandbox->id, $sandbox->name, $sandbox->memory_mb, null, $sandbox->cpus, "/sandboxes/{$sandbox->id}"))
            ->all();
    }
}
