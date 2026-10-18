<?php

namespace Falak\Sites\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Move a site off a server that is gone (the Recovery module's "This server is gone" wizard): the server is replaced
 * by another in its deployment group, like changing its servers in the site's settings (the new target is prepared,
 * edge routes and Falak-managed DNS records follow SiteTargetsChanged). Nothing is deployed: the caller deploys once
 * the data is back.
 */
interface SiteRelocation
{
    /**
     * @throws ValidationException when the site cannot run on $toServerId
     */
    public function replaceServer(string $siteId, string $fromServerId, string $toServerId): void;

    /**
     * Moving a site (Recovery's "Move to another server"): $serverId joins its deployment group as a member, the leader
     * stays (traffic unchanged). Nothing is deployed. A server it already targets is left alone.
     *
     * @throws ValidationException when the site cannot run there
     */
    public function addServer(string $siteId, string $serverId): void;

    /** $serverId (already a target) becomes the leader; the other servers stay members. */
    public function makeLeader(string $siteId, string $serverId): void;

    /** A cancelled move: $serverId leaves the deployment group again (never the leader; unknown servers are ignored). */
    public function removeServer(string $siteId, string $serverId): void;

    /** Why the site cannot run on $serverId (runtime, PHP version, server type …), or null when it can. */
    public function problemOn(string $siteId, string $serverId): ?string;
}
