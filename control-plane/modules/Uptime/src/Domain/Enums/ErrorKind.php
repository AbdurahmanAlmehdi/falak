<?php

namespace Falak\Uptime\Domain\Enums;

/**
 * Why a check failed.
 */
enum ErrorKind: string
{
    /** The host does not resolve. */
    case Dns = 'dns';
    /** The target resolves to an address the SSRF guard refuses. */
    case Blocked = 'blocked';
    /** Connection refused / unreachable. */
    case Connect = 'connect';
    case Timeout = 'timeout';
    /** TLS handshake or certificate verification failed. */
    case Tls = 'tls';
    /** Unexpected HTTP status. */
    case Status = 'status';
    /** Keyword (not) found in the body. */
    case Keyword = 'keyword';
    /** Too many redirects, or an invalid redirect target. */
    case Redirects = 'redirects';
    /** Any other transfer error. */
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Dns => 'DNS lookup failed',
            self::Blocked => 'Target not allowed',
            self::Connect => 'Connection failed',
            self::Timeout => 'Timed out',
            self::Tls => 'TLS error',
            self::Status => 'Unexpected status',
            self::Keyword => 'Keyword check failed',
            self::Redirects => 'Redirect error',
            self::Http => 'Request failed',
        };
    }
}
