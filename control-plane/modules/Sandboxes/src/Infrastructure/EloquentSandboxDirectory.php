<?php

namespace Falak\Sandboxes\Infrastructure;

use Falak\Sandboxes\Contracts\Data\SandboxData;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;

final class EloquentSandboxDirectory implements SandboxDirectory
{
    public function find(string $organizationId, string $ref): ?SandboxData
    {
        $sandbox = self::lookup($organizationId, $ref);

        return $sandbox === null ? null : new SandboxData($sandbox->id, $sandbox->organization_id, $sandbox->server_id, $sandbox->name, $sandbox->status);
    }

    /** By id, else by name among those not killed (names are unique there). */
    public static function lookup(string $organizationId, string $ref): ?Sandbox
    {
        $query = Sandbox::query()->where('organization_id', $organizationId);

        return (clone $query)->find(strtolower($ref)) ?? $query->where('status', '!=', Sandbox::KILLED)->where('name', $ref)->first();
    }
}
