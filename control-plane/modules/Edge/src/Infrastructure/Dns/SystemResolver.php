<?php

namespace Falak\Edge\Infrastructure\Dns;

/**
 * The control plane host's resolver (dns_get_record). Answers can be cached by the host (including "not found").
 */
final class SystemResolver implements DnsResolver
{
    public function resolve(string $name): DnsAnswer
    {
        $records = @dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME);

        if ($records === false) {
            // dns_get_record also fails for names that do not exist: tell them apart with a plain lookup.
            if (@gethostbynamel($name) === false) {
                return new DnsAnswer;
            }

            throw new DnsLookupFailed('The DNS lookup failed.');
        }

        $cnames = $ipv4 = $ipv6 = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if ($type === 'CNAME') {
                $cnames[] = strtolower((string) $record['target']);
            } elseif ($type === 'A') {
                $ipv4[] = (string) $record['ip'];
            } elseif ($type === 'AAAA') {
                $ipv6[] = strtolower((string) $record['ipv6']);
            }
        }

        return new DnsAnswer(array_values(array_unique($cnames)), array_values(array_unique($ipv4)), array_values(array_unique($ipv6)));
    }

    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (($record['type'] ?? null) === 'TXT') {
                $values[] = implode('', (array) ($record['txt'] ?? ($record['entries'] ?? [])));
            }
        }

        return $values;
    }
}
