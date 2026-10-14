<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Events\AgentCameOnline;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Servers\Events\ServerUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Protection follows what it protects (docs/plans/network/protection.md): a new or changed server, a private network
 * change → every server of the organization gets the new guard list (blocks that now cover a protected address are
 * suspended); an agent back online with an apply pending or failed gets it again.
 */
final class ReconvergeProtection implements ShouldQueue
{
    public function __construct(
        private readonly ApplyProtection $apply,
        private readonly ServerDirectory $servers,
    ) {}

    public function provisioned(ServerProvisioned $event): void
    {
        $this->apply->onGuardsChanged($event->organizationId);
    }

    public function updated(ServerUpdated $event): void
    {
        if (($server = $this->servers->find($event->serverId)) !== null) {
            $this->apply->onGuardsChanged($server->organizationId);
        }
    }

    public function network(PrivateNetworkChanged $event): void
    {
        $this->apply->onGuardsChanged($event->organizationId);
    }

    public function online(AgentCameOnline $event): void
    {
        if ($event->serverId === null) {
            return;
        }

        $state = ProtectionState::query()->find($event->serverId);

        if ($state !== null && in_array($state->status, ['failed', 'pending', 'applying'], true)) {
            ($this->apply)($event->serverId, force: $state->status === 'failed');
        }
    }
}
