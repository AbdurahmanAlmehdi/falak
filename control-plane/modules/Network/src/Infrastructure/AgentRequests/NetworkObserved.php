<?php

namespace Falak\Network\Infrastructure\AgentRequests;

use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Network\Application\Actions\RecordNetworkObservation;
use Falak\Network\Application\ConfigureConnections;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Cache;

/**
 * net.observed: the server's connections, listeners, peers, conntrack, WireGuard and probes of the last minute
 * (docs/plans/network/connections.md). Recorded for the agent's own server only.
 */
final class NetworkObserved implements AgentRequestHandler
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly RecordNetworkObservation $record,
        private readonly ConfigureConnections $configure,
    ) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $server = $this->servers->find($caller->serverId);

        if ($server === null || $server->organizationId !== $caller->organizationId) {
            return ['accepted' => false];
        }

        ($this->record)($server, $body);

        // Databases come and go without an event Network listens to: the configuration is re-checked (sent only when
        // it changed) at most once an hour per server.
        if (Cache::add("network.connections.configured:{$server->id}", true, 3600)) {
            ($this->configure)($server->id);
        }

        return ['accepted' => true];
    }
}
