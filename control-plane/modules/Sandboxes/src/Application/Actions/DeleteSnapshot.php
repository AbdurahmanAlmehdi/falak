<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;

/**
 * Delete a snapshot: the row is deleted at once and sandbox.snapshot.delete (idempotent, redeliverable) removes it from
 * its server; SyncSandboxes sends it again until the agent confirmed it. Sandboxes made from it keep running.
 */
final class DeleteSnapshot
{
    public function __construct(
        private readonly SandboxAgent $agent,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SandboxSnapshot $snapshot): void
    {
        if ($snapshot->status === SandboxSnapshot::DELETED) {
            return;
        }

        $snapshot->forceFill(['status' => SandboxSnapshot::DELETED, 'deleted_at' => now(), 'delete_confirmed_at' => null])->save();
        $this->remove($snapshot, "sandbox.snapshot.delete:{$snapshot->id}");

        $this->audit->record('sandbox.snapshot_deleted', 'sandbox', $snapshot->sandbox_id, array_filter([
            'snapshot_id' => $snapshot->id,
            'name' => $snapshot->name,
        ]), $snapshot->organization_id);
    }

    /** sandbox.snapshot.delete only, the row unchanged. */
    public function remove(SandboxSnapshot $snapshot, string $key): void
    {
        $this->agent->tryDispatch($snapshot, 'sandbox.snapshot.delete', ['snapshot_id' => $snapshot->id], $key);
    }
}
