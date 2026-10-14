<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Domain\Models\ProtectionState;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Settles `net.protection.apply` (only the tracked command counts) and, when an agent reports `net.protection` after an
 * upgrade, re-sends its firewall (rule counters) and its blocks (docs/plans/network/protection.md).
 */
final class HandleProtectionOutcome implements ShouldQueue
{
    public function __construct(
        private readonly ApplyProtection $apply,
        private readonly Firewalls $firewalls,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'net.protection.apply') {
            return;
        }

        $state = ProtectionState::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if ($state === null) {
            return;
        }

        $failed = array_values(array_filter((array) ($event->result['failed'] ?? []), 'is_array'));

        $state->forceFill([
            'status' => 'applied',
            'applied_hash' => $state->desired_hash,
            'error' => null,
            'failed' => $failed === [] ? null : array_slice($failed, 0, 100),
            'set_counters' => (bool) ($event->result['set_counters'] ?? true),
            // The agent forgot the lifted auto-bans.
            'released' => null,
            'applied_at' => now(),
        ])->save();
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type !== 'net.protection.apply') {
            return;
        }

        ProtectionState::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)
            ->update(['status' => 'failed', 'error' => mb_substr($event->error ?: "Command {$event->status}", 0, 1000), 'updated_at' => now()]);
    }

    public function handleUpgrade(AgentVersionChanged $event): void
    {
        if ($event->serverId === null || ! in_array(ApplyProtection::FEATURE, $event->features, true)) {
            return;
        }

        // Same rules, now with counters (the agent's ruleset file changes once: an atomic reload).
        $this->firewalls->reapply($event->serverId);
        ($this->apply)($event->serverId, force: true);
    }
}
