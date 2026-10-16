<?php

namespace Falak\Sandboxes\Application\Listeners;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Support\Carbon;

/**
 * sandbox.create settles a creating sandbox (running with its IP, or failed); sandbox.kill confirms a kill;
 * sandbox.inventory reconciles a server's sandboxes with what its agent runs (SyncSandboxes).
 */
final class HandleCommandOutcome
{
    /** Sandboxes younger than this are left alone by the inventory (their create may still be on its way). */
    private const GRACE_SECONDS = 120;

    public function __construct(
        private readonly KillSandbox $kill,
        private readonly SandboxAgent $agent,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type === 'sandbox.create') {
            $sandbox = Sandbox::query()->where('command_id', $event->commandId)->first();

            if ($sandbox === null) {
                return;
            }

            // Running already: the ?wait=1 request saw the result first. Its result still completes the row.
            if (in_array($sandbox->status, [Sandbox::CREATING, Sandbox::RUNNING], true)) {
                self::started($sandbox, $event->result ?? []);
                $this->sendExpiry($sandbox);
            } else {
                // Failed (it took too long) or killed meanwhile: it must not keep running.
                $this->kill->remove($sandbox, "sandbox.kill:{$sandbox->id}:late");
            }
        } elseif ($event->type === 'sandbox.kill' && preg_match('/^sandbox\.kill:([0-9a-z]{26})(:|$)/', $event->idempotencyKey, $m) === 1) {
            Sandbox::query()->whereKey($m[1])->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')->update(['kill_confirmed_at' => now()]);
        } elseif ($event->type === 'sandbox.inventory') {
            $this->reconcile($event->organizationId, $event->serverId, $event->commandId, (array) ($event->result['sandboxes'] ?? []));
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
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
     * A sandbox.create result: running.
     *
     * @param  array<string, mixed>  $result
     */
    public static function started(Sandbox $sandbox, array $result): void
    {
        $sandbox->forceFill([
            'status' => Sandbox::RUNNING,
            'error' => null,
            'ip' => isset($result['ip']) ? (string) $result['ip'] : $sandbox->ip,
            'image_digest' => isset($result['image_digest']) ? mb_substr((string) $result['image_digest'], 0, 200) : $sandbox->image_digest,
            'started_at' => isset($result['started_at']) && is_string($result['started_at']) ? Carbon::parse($result['started_at']) : ($sandbox->started_at ?? now()),
        ])->save();
    }

    /** Extended while it was being created: the agent only knows the expiry sandbox.create carried. */
    private function sendExpiry(Sandbox $sandbox): void
    {
        if ($sandbox->agent_expires_at?->getTimestamp() === $sandbox->expires_at->getTimestamp()) {
            return;
        }

        $expires = $sandbox->expires_at->getTimestamp();

        if ($this->agent->tryDispatch($sandbox, 'sandbox.extend', ['id' => $sandbox->id, 'expires_at' => $expires], "sandbox.extend:{$sandbox->id}:{$expires}") !== null) {
            $sandbox->forceFill(['agent_expires_at' => $sandbox->expires_at])->save();
        }
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
            } elseif (($item['status'] ?? null) === 'stopped' && $sandbox->status === Sandbox::RUNNING && $sandbox->created_at->lt($settled)) {
                $this->kill->__invoke($sandbox, ($item['oom_killed'] ?? false) ? 'oom' : 'exited');
            }
        }

        Sandbox::query()->where('organization_id', $organizationId)->where('server_id', $serverId)->where('status', Sandbox::RUNNING)
            ->where('created_at', '<', $settled)
            ->whereNotIn('id', array_keys($byId) ?: [''])
            ->get()
            ->each(fn (Sandbox $sandbox) => $this->kill->markKilled($sandbox, 'lost'));

        // Killed here and not on the agent any more: nothing left to kill.
        Sandbox::query()->where('server_id', $serverId)->where('status', Sandbox::KILLED)->whereNull('kill_confirmed_at')
            ->whereNotIn('id', array_keys($byId) ?: [''])
            ->update(['kill_confirmed_at' => now()]);
    }
}
