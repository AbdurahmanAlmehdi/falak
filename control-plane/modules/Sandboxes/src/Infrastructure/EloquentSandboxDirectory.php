<?php

namespace Falak\Sandboxes\Infrastructure;

use Falak\Sandboxes\Application\Actions\PauseSandbox;
use Falak\Sandboxes\Contracts\Data\SandboxData;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;

final class EloquentSandboxDirectory implements SandboxDirectory
{
    public function __construct(private readonly PauseSandbox $pauses) {}

    public function find(string $organizationId, string $ref): ?SandboxData
    {
        $sandbox = self::lookup($organizationId, $ref);

        return $sandbox === null ? null : self::data($sandbox);
    }

    public function wake(string $sandboxId): SandboxData
    {
        $sandbox = Sandbox::query()->findOrFail($sandboxId);

        if ($sandbox->status === Sandbox::PAUSED) {
            $sandbox = $this->pauses->resume($sandbox);
        }

        if ($sandbox->isLive()) {
            $sandbox->forceFill(['last_activity_at' => now()])->save();
        }

        return self::data($sandbox);
    }

    public function markActive(array $sandboxIds): void
    {
        if ($sandboxIds !== []) {
            Sandbox::query()->whereKey($sandboxIds)->live()->update(['last_activity_at' => now()]);
        }
    }

    /** By id, else by name among those not killed (names are unique there). */
    public static function lookup(string $organizationId, string $ref): ?Sandbox
    {
        $query = Sandbox::query()->where('organization_id', $organizationId);

        return (clone $query)->find(strtolower($ref)) ?? $query->where('status', '!=', Sandbox::KILLED)->where('name', $ref)->first();
    }

    private static function data(Sandbox $sandbox): SandboxData
    {
        return new SandboxData($sandbox->id, $sandbox->organization_id, $sandbox->server_id, $sandbox->name, $sandbox->status, $sandbox->created_by_id);
    }
}
