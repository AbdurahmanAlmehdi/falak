<?php

namespace Falak\Uptime\Application\Probing;

use Falak\Uptime\Domain\Enums\ErrorKind;

/**
 * One HTTP request/response (no redirect followed). Times in milliseconds from the start of the connection.
 */
final readonly class HttpExchange
{
    public function __construct(
        public ?int $status = null,
        public ?string $location = null,
        /** At most the requested number of bytes. */
        public string $body = '',
        public ?int $connectMs = null,
        public ?int $tlsMs = null,
        public ?int $ttfbMs = null,
        public ?int $totalMs = null,
        public ?ErrorKind $errorKind = null,
        public ?string $error = null,
        /** The address the transport actually connected to (null: unknown / not connected). */
        public ?string $primaryIp = null,
    ) {}

    public function failed(): bool
    {
        return $this->errorKind !== null;
    }
}
