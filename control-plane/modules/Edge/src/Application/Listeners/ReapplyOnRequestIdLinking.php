<?php

namespace Falak\Edge\Application\Listeners;

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Telemetry\Events\RequestIdLinkingChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Request ID linking changes every route of the organization's edges: re-apply them (unchanged payloads, e.g. agents
 * without edge.request_id, are deduplicated by the payload hash).
 */
final class ReapplyOnRequestIdLinking implements ShouldQueue
{
    public function __construct(
        private readonly EdgeRoutes $routes,
        private readonly ServerDirectory $servers,
    ) {}

    public function handle(RequestIdLinkingChanged $event): void
    {
        foreach ($this->servers->forOrganization($event->organizationId, activeOnly: true) as $server) {
            $this->routes->apply($server->id);
        }
    }
}
