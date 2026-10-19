<?php

namespace Falak\Edge\Contracts;

/**
 * "Tailscale only" sites (docs/plans/TAILSCALE.md), for Network: a server serving one must stay on the tailnet.
 */
interface TailnetSites
{
    /**
     * Names of the tailnet-only sites the server serves (as one of the site's servers or as its load balancer).
     *
     * @return list<string>
     */
    public function onServer(string $serverId): array;
}
