<?php

namespace Falak\Edge\Contracts;

/**
 * Which of the sites' domains have their DNS records kept by Falak (a Cloudflare zone of the organization): those
 * follow the site to new servers on their own; the others point wherever their owner set them (the Recovery wizard
 * lists them with the new server's addresses).
 */
interface DomainRecords
{
    /**
     * @param  list<string>  $siteIds
     * @return list<array{site_id: string, name: string, managed: bool, status: ?string}> status: the managed records'
     *                                                                                    worst state (pending | synced | conflict | error)
     */
    public function forSites(array $siteIds): array;

    /**
     * A site moved from the server at $fromIpv4 to $toIpv4: its generated names (`<label>.<ip-with-dashes>.<suffix>`,
     * sslip.io …) embed the old IP, so each is replaced by the same name for the new IP (primary kept, TLS issued anew).
     *
     * @return array<string, string> old name => new name
     */
    public function followServer(string $siteId, string $fromIpv4, string $toIpv4): array;
}
