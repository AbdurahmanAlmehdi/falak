<?php

namespace Falak\Uptime\Infrastructure;

use Falak\Uptime\Application\Probing\HostResolver;

/**
 * The control plane's resolver: A records through getaddrinfo (follows CNAMEs, /etc/hosts included — the guard judges
 * whatever comes back), AAAA through the DNS.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        $v6 = array_values(array_filter(array_map(fn (array $record) => $record['ipv6'] ?? null, $records)));

        return array_values(array_unique([...$v4, ...$v6]));
    }
}
