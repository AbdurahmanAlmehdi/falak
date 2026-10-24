<?php

namespace Falak\Edge\Infrastructure\Dns;

/**
 * Resolves a name's A / AAAA records (following CNAMEs). A name that does not exist yields an empty answer.
 */
interface DnsResolver
{
    /**
     * @throws DnsLookupFailed when the resolver could not answer (timeout, SERVFAIL, network)
     */
    public function resolve(string $name): DnsAnswer;

    /**
     * Returns the TXT record values for $name, or an empty list when the name has none.
     *
     * @return list<string>
     *
     * @throws DnsLookupFailed when the resolver could not answer (timeout, SERVFAIL, network)
     */
    public function txt(string $name): array;
}
