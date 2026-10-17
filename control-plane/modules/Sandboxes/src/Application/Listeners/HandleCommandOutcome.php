<?php

namespace Falak\Sandboxes\Application\Listeners;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Sandboxes\Application\Actions\DeleteSnapshot;
use Falak\Sandboxes\Application\Actions\ExecInSandbox;
use Falak\Sandboxes\Application\Actions\ImportDatabase;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Actions\PauseSandbox;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * sandbox.create settles a creating sandbox (running with its IP, or failed) and starts the database copy it asked for;
 * sandbox.extend records the expiry the agent enforces; sandbox.kill confirms a kill; sandbox.pause/resume settle the
 * status; sandbox.snapshot makes a snapshot ready (or failed); sandbox.snapshot.delete confirms a delete;
 * sandbox.db.import (a create's copy) goes to the exec log; sandbox.inventory reconciles a server's sandboxes and
 * snapshots with what its agent has (SyncSandboxes).
 */
final class HandleCommandOutcome
{
    /** Sandboxes younger than this are left alone by the inventory (their create may still be on its way). */
    private const GRACE_SECONDS = 120;

    public function __construct(
        private readonly KillSandbox $kill,
        private readonly SandboxAgent $agent,
        private readonly DeleteSnapshot $deleteSnapshot,
        private readonly ExecInSandbox $exec,
        private readonly ImportDatabase $import,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type === 'sandbox.create') {
            $id = Sandbox::query()->where('command_id', $event->commandId)->value('id');

            if ($id === null) {
                return;
            }

            // Under the row's lock: an extend at the same time either saved its expiry first (sent here) or sees the
            // sandbox running afterwards (and sends it itself).
            $late = DB::transaction(function () use ($id, $event): ?Sandbox {
                $sandbox = Sandbox::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                // Running already: the ?wait=1 request saw the result first. Its result still completes the row.
                if (! in_array($sandbox->status, [Sandbox::CREATING, Sandbox::RUNNING], true)) {
                    return $sandbox;
                }

                self::started($sandbox, $event->result ?? []);
                $this->sendExpiry($sandbox);

                // The database copy asked for on create (only this listener sends it: once per create).
                if ($sandbox->db_import !== null) {
                    $this->agent->tryDispatch($sandbox, 'sandbox.db.import', ImportDatabase::payload($sandbox, $sandbox->db_import), "sandbox.db.import:{$sandbox->id}:create", 900);
                }

                return null;
            });

            if ($late !== null) {
                // Failed (it took too long) or killed meanwhile: it must not keep running.
                $this->kill->remove($late, "sandbox.kill:{$late->id}:late");
            }
        } elseif ($event->type === 'sandbox.extend' && preg_match('/^sandbox\.extend:([0-9a-z]{26}):/', $event->idempotencyKey, $m) === 1 && is_numeric($event->result['expires_at'] ?? null)) {
            // What the agent now enforces (SyncSandboxes sends it again while it differs from expires_at).
            Sandbox::query()->whereKey($m[1])->update(['agent_expires_at' => Carbon::createFromTimestamp((int) $event->result['expires_at'])]);
        } elseif ($event->type === 'sandbox.kill' && preg_match('/^sandbox\.kill:([0-9a-z]{26})(:|$)/', $event->idempotencyKey, $m) === 1) {
            Sandbox::query()->whereKey($m[1])->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')->update(['kill_confirmed_at' => now()]);
        } elseif (in_array($event->type, ['sandbox.pause', 'sandbox.resume'], true) && preg_match('/^sandbox\.(?:pause|resume):([0-9a-z]{26})(:|$)/', $event->idempotencyKey, $m) === 1) {
            PauseSandbox::settle($m[1], $event->type === 'sandbox.pause' ? Sandbox::PAUSED : Sandbox::RUNNING);
        } elseif ($event->type === 'sandbox.snapshot' && preg_match('/^sandbox\.snapshot:([0-9a-z]{26})(:stop)?$/', $event->idempotencyKey, $m) === 1) {
            $snapshot = SandboxSnapshot::query()->find($m[1]);

            if ($snapshot !== null) {
                $this->snapshotted($snapshot, $event->result ?? [], ($m[2] ?? '') === ':stop');
            }
        } elseif ($event->type === 'sandbox.snapshot.delete' && preg_match('/^sandbox\.snapshot\.delete:([0-9a-z]{26})(:|$)/', $event->idempotencyKey, $m) === 1) {
            SandboxSnapshot::query()->whereKey($m[1])->where('status', SandboxSnapshot::DELETED)->whereNull('delete_confirmed_at')->update(['delete_confirmed_at' => now()]);
        } elseif ($event->type === 'sandbox.db.import') {
            $this->imported($event->idempotencyKey, null);
        } elseif ($event->type === 'sandbox.inventory') {
            $this->reconcile($event->organizationId, $event->serverId, $event->commandId, (array) ($event->result['sandboxes'] ?? []));

            // sandbox.v2 agents report their snapshots too.
            if (is_array($event->result) && array_key_exists('snapshots', $event->result)) {
                $this->reconcileSnapshots($event->serverId, $event->commandId, (array) $event->result['snapshots']);
            }
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type === 'sandbox.snapshot' && preg_match('/^sandbox\.snapshot:([0-9a-z]{26})/', $event->idempotencyKey, $m) === 1) {
            SandboxSnapshot::query()->whereKey($m[1])->where('status', SandboxSnapshot::CREATING)
                ->update(['status' => SandboxSnapshot::FAILED, 'error' => mb_substr($event->error ?: "sandbox.snapshot {$event->status}", 0, 1000), 'updated_at' => now()]);

            return;
        }

        if ($event->type === 'sandbox.db.import') {
            $this->imported($event->idempotencyKey, $event->error ?: "sandbox.db.import {$event->status}");

            return;
        }

        if ($event->type !== 'sandbox.create') {
            return;
        }

        $sandbox = Sandbox::query()->where('command_id', $event->commandId)->first();

        if ($sandbox !== null && $sandbox->status === Sandbox::CREATING) {
            $reason = $event->error ?: "sandbox.create {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
            self::failed($sandbox, $reason);
        }
    }

    /** A create that failed: the agent's error is the sandbox's error and its kill_reason (what the CLI shows). */
    public static function failed(Sandbox $sandbox, string $error): void
    {
        $error = mb_substr($error, 0, 1000);
        $sandbox->forceFill(['status' => Sandbox::FAILED, 'error' => $error, 'kill_reason' => mb_substr($error, 0, 255)])->save();
    }

    /**
     * A sandbox.create result: running, and active from now (idle pause doesn't count the time it took to start).
     *
     * @param  array<string, mixed>  $result
     */
    public static function started(Sandbox $sandbox, array $result): void
    {
        $sandbox->forceFill([
            'status' => Sandbox::RUNNING,
            'error' => null,
            'last_activity_at' => now(),
            'ip' => isset($result['ip']) ? (string) $result['ip'] : $sandbox->ip,
            'image_digest' => isset($result['image_digest']) ? mb_substr((string) $result['image_digest'], 0, 200) : $sandbox->image_digest,
            'started_at' => isset($result['started_at']) && is_string($result['started_at']) ? Carbon::parse($result['started_at']) : ($sandbox->started_at ?? now()),
        ])->save();
    }

    /**
     * A sandbox.snapshot result (here, or the ?wait=1 request's): ready with its size. One deleted meanwhile is removed
     * from the agent again (it has it now). With $stop the agent removed the sandbox: killed, reason "snapshot".
     *
     * @param  array<string, mixed>  $result
     */
    public function snapshotted(SandboxSnapshot $snapshot, array $result, bool $stop): void
    {
        $deleted = DB::transaction(function () use ($snapshot, $result): bool {
            $fresh = SandboxSnapshot::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === SandboxSnapshot::CREATING) {
                $fresh->forceFill([
                    'status' => SandboxSnapshot::READY,
                    'error' => null,
                    'size_bytes' => isset($result['size_bytes']) ? (int) $result['size_bytes'] : $fresh->size_bytes,
                    'image_digest' => isset($result['image_digest']) ? mb_substr((string) $result['image_digest'], 0, 200) : $fresh->image_digest,
                ])->save();
            }

            $snapshot->setRawAttributes($fresh->getAttributes(), true);

            return $fresh->status === SandboxSnapshot::DELETED;
        });

        if ($deleted) {
            $this->deleteSnapshot->remove($snapshot, "sandbox.snapshot.delete:{$snapshot->id}:late");
        }

        if ($stop && ($sandbox = Sandbox::query()->find($snapshot->sandbox_id)) !== null) {
            $this->kill->markKilled($sandbox, 'snapshot');
        }
    }

    /** A create's database copy finished ($error null) or failed: an exec log row for its sandbox's creator. */
    private function imported(string $key, ?string $error): void
    {
        if (preg_match('/^sandbox\.db\.import:([0-9a-z]{26}):create$/', $key, $m) !== 1 || ($sandbox = Sandbox::query()->find($m[1])) === null || $sandbox->db_import === null) {
            return;
        }

        $actor = new Actor($sandbox->created_by_type, $sandbox->created_by_id, $sandbox->created_by_name);
        $cmd = ImportDatabase::describe($sandbox->db_import, ImportDatabase::payload($sandbox, $sandbox->db_import)['path']);
        $this->exec->record($sandbox, $actor, $cmd, null, [], now(), $error === null ? ['exit_code' => 0] : ['error' => $error]);

        if ($error === null) {
            $this->import->recorded($sandbox, $sandbox->db_import);
        }
    }

    /**
     * The agent's expiry behind the sandbox's (extended while it was being created, or meanwhile): sandbox.extend,
     * without waiting. agent_expires_at follows once the agent answered (handleFinished); until then SyncSandboxes
     * sends it again ($attempt: its slot).
     */
    public function sendExpiry(Sandbox $sandbox, string $attempt = ''): void
    {
        if ($sandbox->agent_expires_at?->getTimestamp() === $sandbox->expires_at->getTimestamp()) {
            return;
        }

        $expires = $sandbox->expires_at->getTimestamp();
        $this->agent->tryDispatch($sandbox, 'sandbox.extend', ['id' => $sandbox->id, 'expires_at' => $expires], "sandbox.extend:{$sandbox->id}:{$expires}".($attempt !== '' ? ":{$attempt}" : ''), 300);
    }

    /**
     * Running here but gone on the agent: killed (lost). Stopped on the agent: removed, killed (oom or exited). On the
     * agent but killed or unknown here: removed. A sandbox still being created is the create's to settle: neither
     * promoted nor killed here (the agent reports it `creating`, or `stopped` before it started).
     *
     * @param  list<array<string, mixed>>  $reported
     */
    private function reconcile(string $organizationId, string $serverId, string $commandId, array $reported): void
    {
        $byId = [];

        foreach ($reported as $item) {
            if (is_array($item) && isset($item['id'])) {
                $byId[strtolower((string) $item['id'])] = $item;
            }
        }

        $known = Sandbox::query()->where('server_id', $serverId)->whereIn('id', array_keys($byId) ?: [''])->get()->keyBy('id');
        $settled = now()->subSeconds(self::GRACE_SECONDS);

        foreach ($byId as $id => $item) {
            $sandbox = $known->get($id);

            if ($sandbox === null || in_array($sandbox->status, [Sandbox::KILLED, Sandbox::FAILED], true)) {
                $this->kill->remove($sandbox ?? new Sandbox(['id' => $id, 'server_id' => $serverId]), "sandbox.kill:{$id}:sync:{$commandId}");
            } elseif (in_array($sandbox->status, [Sandbox::RUNNING, Sandbox::PAUSED], true) && $sandbox->created_at->lt($settled)) {
                match ($item['status'] ?? null) {
                    'stopped' => $this->kill->__invoke($sandbox, ($item['oom_killed'] ?? false) ? 'oom' : 'exited'),
                    'paused' => PauseSandbox::settle($sandbox->id, Sandbox::PAUSED),
                    'running' => PauseSandbox::settle($sandbox->id, Sandbox::RUNNING),
                    default => null,
                };
            }
        }

        Sandbox::query()->where('organization_id', $organizationId)->where('server_id', $serverId)->whereIn('status', [Sandbox::RUNNING, Sandbox::PAUSED])
            ->where('created_at', '<', $settled)
            ->whereNotIn('id', array_keys($byId) ?: [''])
            ->get()
            ->each(fn (Sandbox $sandbox) => $this->kill->markKilled($sandbox, 'lost'));

        // Killed here and not on the agent any more: nothing left to kill.
        Sandbox::query()->where('server_id', $serverId)->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')
            ->whereNotIn('id', array_keys($byId) ?: [''])
            ->update(['kill_confirmed_at' => now()]);
    }

    /**
     * When the inventory command was sent (its id is a ULID): what the agent reports may predate anything changed after
     * that. Seconds only, as the rows' timestamps are: a change within the same second counts as after.
     */
    public static function sentAt(string $commandId): Carbon
    {
        return Str::isUlid($commandId)
            ? Carbon::createFromInterface(Ulid::fromString($commandId)->getDateTime())->setTimezone(now()->getTimezone())->startOfSecond()
            : now()->subSeconds(self::GRACE_SECONDS);
    }

    /**
     * Ready here (since before the inventory was sent) but gone on the agent: failed. On the agent but deleted, failed
     * or unknown here: removed. Deleted here and gone there: confirmed. A snapshot still being taken is its command's to
     * settle (SweepSandboxes fails it late); one that became ready after the inventory was sent is left to the next one.
     *
     * @param  array<mixed>  $reported
     */
    private function reconcileSnapshots(string $serverId, string $commandId, array $reported): void
    {
        $ids = [];

        foreach ($reported as $item) {
            if (is_array($item) && isset($item['snapshot_id'])) {
                $ids[] = strtolower((string) $item['snapshot_id']);
            }
        }

        $known = SandboxSnapshot::query()->where('server_id', $serverId)->whereIn('id', $ids ?: [''])->get()->keyBy('id');

        foreach ($ids as $id) {
            $snapshot = $known->get($id);

            if ($snapshot === null || in_array($snapshot->status, [SandboxSnapshot::DELETED, SandboxSnapshot::FAILED], true)) {
                $this->deleteSnapshot->remove($snapshot ?? new SandboxSnapshot(['id' => $id, 'server_id' => $serverId]), "sandbox.snapshot.delete:{$id}:sync:{$commandId}");
            }
        }

        // After the removals above: a snapshot failed here is only removed by a later inventory that still reports it.
        SandboxSnapshot::query()->where('server_id', $serverId)->where('status', SandboxSnapshot::READY)->whereNotIn('id', $ids ?: [''])
            ->where('updated_at', '<', self::sentAt($commandId))
            ->update(['status' => SandboxSnapshot::FAILED, 'error' => 'The snapshot is gone from its server.', 'updated_at' => now()]);

        SandboxSnapshot::query()->where('server_id', $serverId)->where('status', SandboxSnapshot::DELETED)->whereNull('delete_confirmed_at')
            ->whereNotIn('id', $ids ?: [''])
            ->update(['delete_confirmed_at' => now()]);
    }
}
