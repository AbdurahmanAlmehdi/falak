<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Application\ConfigureConnections;
use Falak\Network\Application\ConvergePrivateNetwork;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Events\FirewallApplied;
use Falak\Network\Events\FirewallApplyFailed;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Network\Infrastructure\WireGuardKeys;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Settles firewall and WireGuard state from the outcome of the commands Network dispatched.
 * Only the currently tracked command id counts; results of superseded commands are ignored.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(
        private readonly ConvergePrivateNetwork $converge,
        private readonly ConfigureConnections $connections,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'net.firewall.apply' => $this->firewallApplied($event),
            'net.wireguard.apply' => $this->wireguardApplied($event),
            'system.write_file' => $this->keyInstalled($event),
            'net.connections.configure' => $this->connections->applied($event->commandId, true),
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = mb_substr($event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : ''), 0, 1000);

        match ($event->type) {
            'net.firewall.apply' => $this->firewallFailed($event, $reason),
            'net.wireguard.apply' => PrivateNetworkMember::query()
                ->where('command_id', $event->commandId)
                ->where('organization_id', $event->organizationId)
                ->update(['status' => ApplyStatus::Failed, 'error' => $reason, 'updated_at' => now()]),
            'system.write_file' => PrivateNetworkMember::query()
                ->where('key_command_id', $event->commandId)
                ->where('organization_id', $event->organizationId)
                ->update(['key_status' => KeyStatus::Failed, 'status' => ApplyStatus::Failed, 'error' => "Installing the WireGuard key failed: {$reason}", 'updated_at' => now()]),
            'net.connections.configure' => $this->connections->applied($event->commandId, false),
            default => null,
        };
    }

    private function firewallFailed(CommandFailed $event, string $reason): void
    {
        $state = FirewallState::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        // Only the tracked (latest) apply counts; superseded commands are ignored.
        if (! $state) {
            return;
        }

        $firstFailure = $state->failed_at === null;
        $state->forceFill(['status' => ApplyStatus::Failed, 'error' => $reason, 'failed_at' => $state->failed_at ?? now()])->save();

        // Alert when the firewall starts failing, not on every retry of a failing apply.
        if ($firstFailure) {
            FirewallApplyFailed::dispatch($event->serverId, $event->organizationId, $event->commandId, $reason);
        }
    }

    private function firewallApplied(CommandFinished $event): void
    {
        $state = FirewallState::query()->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $state) {
            return;
        }

        $sha = is_string($event->result['ruleset_sha256'] ?? null) ? $event->result['ruleset_sha256'] : null;

        $recovered = $state->failed_at !== null;

        $state->forceFill([
            'status' => ApplyStatus::Applied,
            'applied_hash' => $state->desired_hash,
            'ruleset_sha256' => $sha,
            'error' => null,
            'applied_at' => now(),
            'failed_at' => null,
        ])->save();

        // The recovery alert ("Firewall applied again") only after a failure, not on every successful apply.
        if ($recovered) {
            FirewallApplied::dispatch($state->server_id, $state->organization_id, $event->commandId, $sha);
        }
    }

    private function keyInstalled(CommandFinished $event): void
    {
        $member = PrivateNetworkMember::query()->with('network')->where('key_command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $member) {
            return;
        }

        // Custody of the private key moves to the host.
        $member->forceFill(['key_status' => KeyStatus::Installed, 'private_key' => null, 'error' => null])->save();

        ($this->converge)($member->network);
    }

    private function wireguardApplied(CommandFinished $event): void
    {
        $member = PrivateNetworkMember::query()->with('network')->where('command_id', $event->commandId)->where('organization_id', $event->organizationId)->first();

        if (! $member) {
            return;
        }

        $reported = $event->result['public_key'] ?? null;
        $adopt = is_string($reported) && WireGuardKeys::isPublicKey($reported) && $reported !== $member->public_key;

        $member->forceFill([
            'status' => ApplyStatus::Applied,
            'applied_hash' => $member->desired_hash,
            'applied_at' => now(),
            'error' => null,
            // The host already had a different key: peers must use the one it actually runs with.
            'public_key' => $adopt ? $reported : $member->public_key,
            'key_status' => KeyStatus::Installed,
            'private_key' => null,
        ])->save();

        if ($adopt) {
            ($this->converge)($member->network);
        }

        PrivateNetworkChanged::dispatch($member->network_id, $member->organization_id, PrivateNetworkChanged::APPLIED, [$member->server_id]);
    }
}
