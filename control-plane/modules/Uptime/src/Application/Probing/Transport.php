<?php

namespace Falak\Uptime\Application\Probing;

use Falak\Uptime\Domain\Probing\CertificateInspector;

/**
 * The network I/O of a check, always to an address the caller already resolved and checked (never a host name: the
 * transport must not resolve on its own, or a DNS answer could change between the guard and the connection).
 * Faked in tests.
 */
interface Transport
{
    /**
     * GET $url, connecting to $ip (TLS SNI and Host stay $url's host), without following redirects, reading at most
     * $bodyBytes of the body.
     */
    public function http(string $url, string $ip, int $timeoutSeconds, int $bodyBytes): HttpExchange;

    public function tcp(string $ip, int $port, int $timeoutSeconds): ConnectOutcome;

    /**
     * TLS handshake to $ip with SNI $serverName, without verification: returns the presented chain (PEM, leaf first)
     * for {@see CertificateInspector}.
     */
    public function tls(string $ip, int $port, string $serverName, int $timeoutSeconds): TlsHandshake;
}
