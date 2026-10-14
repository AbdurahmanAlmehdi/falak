<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Network\Application\ConfigureConnections;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Re-sends net.connections.configure when what it names changed: a private network (every server of the organization:
 * peers and their addresses), or the agent (an upgrade may have brought `net.connections`). The agent's reports also
 * re-check it hourly (NetworkObserved), which picks up new databases.
 */
final class ReconfigureConnections implements ShouldQueue
{
    public function __construct(
        private readonly ConfigureConnections $configure,
        private readonly ServerDirectory $servers,
    ) {}

    public function handleNetworkChanged(PrivateNetworkChanged $event): void
    {
        foreach ($this->servers->forOrganization($event->organizationId, activeOnly: true) as $server) {
            ($this->configure)($server->id);
        }
    }

    public function handleAgentVersion(AgentVersionChanged $event): void
    {
        if ($event->serverId !== null) {
            ($this->configure)($event->serverId, force: true);
        }
    }
}
