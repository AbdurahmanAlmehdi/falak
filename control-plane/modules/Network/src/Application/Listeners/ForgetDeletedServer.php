<?php

namespace Falak\Network\Application\Listeners;

use Falak\Network\Application\Actions\RemoveNetworkMember;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\NetworkObservation;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Domain\Models\TailscaleNode;
use Falak\Servers\Events\ServerDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Drops a deleted server's firewall, its latest network report, its Tailscale node and removes it from its private networks (re-applying the rest).
 */
final class ForgetDeletedServer implements ShouldQueue
{
    public function __construct(private readonly RemoveNetworkMember $remove) {}

    public function handle(ServerDeleted $event): void
    {
        FirewallRule::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        FirewallState::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        NetworkObservation::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        IpBlock::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        ProtectionState::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        TailscaleNode::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();

        PrivateNetworkMember::query()
            ->with('network')
            ->where('server_id', $event->serverId)
            ->where('organization_id', $event->organizationId)
            ->get()
            ->each(fn (PrivateNetworkMember $member) => ($this->remove)($member, serverGone: true));
    }
}
