<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Domain\Models\TailscaleNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Settles `net.tailscale.apply` on the server's node (only the tracked command counts; a leave's has no node left).
 */
final class HandleTailscaleOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    private const MESSAGES = [
        'NeedsLogin' => 'Tailscale refused the auth key (expired, used up or not reusable): save a new key, then refresh.',
        'NeedsMachineAuth' => 'The device waits for approval in the Tailscale admin console (use a pre-approved key).',
    ];

    public function __construct(private readonly AgentGateway $agents) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'net.tailscale.apply') {
            return;
        }

        // The auth key is not kept once the command settled (the fleet sweep forgets it if Fleet is not there yet).
        $this->agents->forgetSecrets($event->commandId, ['auth_key']);

        $node = TailscaleNode::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if ($node === null) {
            return;
        }

        $result = $event->result ?? [];
        $state = is_string($result['backend_state'] ?? null) ? $result['backend_state'] : 'unknown';
        $running = $state === 'Running' || ($result['active'] ?? false) === true;
        $string = fn (string $key, int $max) => is_string($result[$key] ?? null) && $result[$key] !== '' ? mb_substr($result[$key], 0, $max) : null;

        $node->forceFill([
            'status' => $running ? TailscaleNode::ACTIVE : TailscaleNode::ERROR,
            'error' => $running ? null : (self::MESSAGES[$state] ?? "Tailscale is {$state} (journalctl -u falak-tailscaled)."),
            'hostname' => $string('hostname', 63) ?? $node->hostname,
            'ipv4' => $string('ipv4', 45),
            'ipv6' => $string('ipv6', 45),
            'routes_approved' => (bool) ($result['routes_approved'] ?? false),
            'version' => $string('version', 32),
        ])->save();
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type !== 'net.tailscale.apply') {
            return;
        }

        $this->agents->forgetSecrets($event->commandId, ['auth_key']);

        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
        // `tailscale up` failing on the key reads like the states the success path explains.
        $reason = match (true) {
            str_contains($reason, 'NeedsMachineAuth') => self::MESSAGES['NeedsMachineAuth'],
            str_contains($reason, 'NeedsLogin'), stripos($reason, 'invalid key') !== false, stripos($reason, 'authkey') !== false => self::MESSAGES['NeedsLogin'],
            default => $reason,
        };

        TailscaleNode::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->update([
            'status' => TailscaleNode::ERROR,
            // Never echo a key back, whatever the agent's error says.
            'error' => Str::limit((string) preg_replace('/tskey-[A-Za-z0-9_-]+/', 'tskey-…', $reason), 990),
            'updated_at' => now(),
        ]);
    }
}
