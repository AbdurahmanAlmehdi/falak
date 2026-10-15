<?php

namespace Falak\Network\Contracts;

/**
 * Which addresses a "Block" action must not be offered on (docs/plans/network/protection.md), for the tables that show
 * remote addresses (the Network tabs, the site HTTP tab's clients and threats).
 */
interface BlockableAddresses
{
    /**
     * A short label per address that cannot be blocked from a row ("Control plane", "Your server", "Cloudflare",
     * "Private range", …); blockable addresses are left out.
     *
     * @param  list<string>  $addresses
     * @return array<string, string>
     */
    public function unblockable(array $addresses, string $organizationId, ?string $requesterIp = null): array;
}
