<?php

namespace Falak\Uptime\Application\Probing;

/**
 * Resolves a host name once per check (A and AAAA). Faked in tests.
 */
interface HostResolver
{
    /**
     * @return list<string> addresses, IPv4 first; empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
