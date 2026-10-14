<?php

namespace Falak\Uptime\Domain\Enums;

enum MonitorType: string
{
    /** GET a URL: expected status, optional keyword. */
    case Http = 'http';
    /** Open a TCP connection to host:port. */
    case Tcp = 'tcp';
    /** TLS handshake only, chain inspected. */
    case Tls = 'tls';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S)',
            self::Tcp => 'TCP port',
            self::Tls => 'TLS handshake',
        };
    }
}
