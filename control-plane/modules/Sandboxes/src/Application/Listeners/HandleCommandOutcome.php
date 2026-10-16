<?php

namespace Falak\Sandboxes\Application\Listeners;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Support\Carbon;

/**
 * sandbox.create settles a creating sandbox (running with its IP, or failed); sandbox.inventory reconciles a server's
 * sandboxes with what its agent runs (SyncSandboxes).
 */
final class HandleCommandOutcome
{
    /** Sandboxes younger than this are left alone by the inventory (their create may still be on its way). */
    private const GRACE_SECONDS = 120;

    public function __construct(private readonly KillSandbox $kill) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type === 'sandbox.create') {
            $sandbox = Sandbox::query()->where('command_id', $event->commandId)->first();

            if ($sandbox?->status === Sandbox::CREATING) {
                self::started($sandbox, $event->result ?? []);
            } elseif ($sandbox !== null) {
                // Failed (it took too long) or killed meanwhile: it must not keep running.
                $this->kill->remove($sandbox, "sandbox.kill:{$sandbox->id}:late");
            }
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
            'ip' => isset($result['ip']) ? (string) $result['ip'] : null,
            'image_digest' => isset($result['image_digest']) ? mb_substr((string) $result['image_digest'], 0, 200) : null,
            'started_at' => isset($result['started_at']) && is_string($result['started_at']) ? Carbon::parse($result['started_at']) : now(),
        ])->save();
    }

    /**
     * Running here but gone on the agent: killed (lost). Stopped on the agent: removed, killed (oom or exited). On the
     * agent but killed or unknown here: removed.
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

        foreach ($byId as $id => $item) {
            $sandbox = $known->get($id);

            if ($sandbox === null || in_array($sandbox->status, [Sandbox::KILLED, Sandbox::FAILED], true)) {
                $this->kill->remove($sandbox ?? new Sandbox(['id' => $id, 'server_id' => $serverId]), "sandbox.kill:{$id}:sync:{$commandId}");
            } elseif (($item['status'] ?? null) === 'stopped') {
                $this->kill->__invoke($sandbox, ($item['oom_killed'] ?? false) ? 'oom' : 'exited');
            } elseif ($sandbox->status === Sandbox::CREATING && ($item['status'] ?? null) === 'running') {
                self::started($sandbox, []);
            }
        }

        Sandbox::query()->where('organization_id', $organizationId)->where('server_id', $serverId)->where('status', Sandbox::RUNNING)
            ->where('created_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->whereNotIn('id', array_keys($byId) ?: [''])
            ->get()
            ->each(fn (Sandbox $sandbox) => $this->kill->markKilled($sandbox, 'lost'));
    }
}
