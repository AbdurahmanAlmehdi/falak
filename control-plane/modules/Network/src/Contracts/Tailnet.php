<?php

namespace Falak\Network\Contracts;

/**
 * The organization's Tailscale tailnet (docs/plans/TAILSCALE.md), for other modules: which servers are on it, and the
 * sources a tailnet-only site lets in.
 */
interface Tailnet
{
    /** Tailscale's address ranges: traffic from tailnet devices arrives from these. */
    public const RANGES = ['100.64.0.0/10', 'fd7a:115c:a1e0::/48'];

    /** The server is reachable through the tailnet (its node is active and its route approved). */
    public function joined(string $serverId): bool;

    /** The server's node is active but its route awaits approval in the Tailscale admin console. */
    public function routesPending(string $serverId): bool;

    /** The organization saved an auth key. */
    public function configured(string $organizationId): bool;

    /**
     * The control plane's public addresses (its health and uptime checks do not come through the tailnet).
     *
     * @return list<string>
     */
    public function controlPlaneAddresses(string $organizationId): array;
}
