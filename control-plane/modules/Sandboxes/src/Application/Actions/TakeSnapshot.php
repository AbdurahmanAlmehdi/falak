<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot a running or paused sandbox (resumed first; sandbox.snapshot, sandbox.v2): the row starts `creating`, HandleCommandOutcome
 * makes it ready (or failed). With $stop the agent removes the sandbox after the checkpoint and the sandbox is killed
 * with reason "snapshot" once the snapshot is ready. Quotas (max_snapshots, max_snapshot_storage_mb) and the name are
 * checked under the organization's lock.
 */
final class TakeSnapshot
{
    public function __construct(
        private readonly SandboxAgent $agent,
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
        private readonly PauseSandbox $pauses,
    ) {}

    public function __invoke(Sandbox $sandbox, Actor $actor, ?string $name, bool $stop = false): SandboxSnapshot
    {
        abort_unless(in_array($sandbox->status, [Sandbox::RUNNING, Sandbox::PAUSED], true), 409, $sandbox->status === Sandbox::CREATING ? 'The sandbox is still starting.' : "The sandbox is {$sandbox->status}.");
        $this->agent->requireV2($sandbox->server_id);
        // The agent checkpoints running sandboxes only: a paused one is resumed first, as for an exec.
        $sandbox = $this->pauses->resume($sandbox);
        $organizationId = $sandbox->organization_id;

        try {
            $snapshot = Cache::lock("sandboxes:snapshot:{$organizationId}", 60)->block((int) config('sandboxes.create_lock_wait', 10), fn () => DB::transaction(
                fn () => $this->insert($sandbox, $actor, $name, $stop),
            ));
        } catch (LockTimeoutException) {
            abort(429, 'Another snapshot of your organization is being taken; try again.');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A snapshot of that name was created meanwhile; try again.']);
        }

        $sandbox->forceFill(['last_activity_at' => now()])->save();

        return $snapshot;
    }

    /** The idempotency key: HandleCommandOutcome reads the snapshot and whether the sandbox stops from it. */
    public static function key(string $snapshotId, bool $stop): string
    {
        return "sandbox.snapshot:{$snapshotId}".($stop ? ':stop' : '');
    }

    private function insert(Sandbox $sandbox, Actor $actor, ?string $name, bool $stop): SandboxSnapshot
    {
        $organizationId = $sandbox->organization_id;
        $kept = SandboxSnapshot::query()->where('organization_id', $organizationId)->kept();
        $count = (clone $kept)->count();
        $max = (int) config('sandboxes.max_snapshots');

        if ($count >= $max) {
            throw ValidationException::withMessages(['snapshot' => "Your organization keeps {$count} of its {$max} snapshots. Delete one first."]);
        }

        // A snapshot's size is known once taken: one still being taken reserves its sandbox's memory and disk (what a
        // checkpoint can hold at most), so snapshots taken at the same time can't pass the quota together.
        $usedMb = (int) ceil((int) (clone $kept)->where('status', SandboxSnapshot::READY)->sum('size_bytes') / 1024 / 1024)
            + (int) (clone $kept)->where('status', SandboxSnapshot::CREATING)->sum(DB::raw('memory_mb + disk_mb'));
        $maxMb = (int) config('sandboxes.max_snapshot_storage_mb');

        if ($usedMb >= $maxMb) {
            throw ValidationException::withMessages(['snapshot' => "Your organization's snapshots may take {$maxMb} MB in all; {$usedMb} MB are taken. Delete one first."]);
        }

        if ($name !== null && $name !== '' && SandboxSnapshot::query()->where('organization_id', $organizationId)->where('status', '!=', SandboxSnapshot::DELETED)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "A snapshot called {$name} already exists."]);
        }

        $snapshot = SandboxSnapshot::query()->create([
            'organization_id' => $organizationId,
            'sandbox_id' => $sandbox->id,
            'server_id' => $sandbox->server_id,
            'name' => $name !== '' ? $name : null,
            'status' => SandboxSnapshot::CREATING,
            'image' => $sandbox->image,
            'image_digest' => $sandbox->image_digest,
            'cpus' => $sandbox->cpus,
            'memory_mb' => $sandbox->memory_mb,
            'disk_mb' => $sandbox->disk_mb,
            'network' => $sandbox->network,
            'allow_hosts' => $sandbox->allow_hosts ?? [],
            'env' => $sandbox->env ?? [],
            'created_by_type' => $actor->type,
            'created_by_id' => $actor->id,
            'created_by_name' => $actor->name,
        ]);

        try {
            $handle = $this->agents->dispatch($sandbox->server_id, 'sandbox.snapshot', ['id' => $sandbox->id, 'snapshot_id' => $snapshot->id, 'leave_running' => ! $stop], 900, self::key($snapshot->id, $stop));
        } catch (AgentUnavailable) {
            abort(503, SandboxAgent::NOT_CONNECTED);
        }

        $snapshot->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('sandbox.snapshot_created', 'sandbox', $sandbox->id, array_filter([
            'snapshot_id' => $snapshot->id,
            'name' => $snapshot->name,
            'sandbox' => $sandbox->name,
            'stop' => $stop ?: null,
            'via_token' => $actor->type === 'token' ? $actor->name : null,
        ], fn ($value) => $value !== null), $organizationId);

        return $snapshot;
    }
}
