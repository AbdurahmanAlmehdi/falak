<?php

namespace Falak\Uptime\Application\Probing;

use Falak\Uptime\Domain\Enums\ErrorKind;

final readonly class TlsHandshake
{
    /**
     * @param  list<string>  $chain  PEM, leaf first
     */
    public function __construct(
        public bool $ok,
        public ?int $connectMs = null,
        public ?int $handshakeMs = null,
        public array $chain = [],
        public ?ErrorKind $errorKind = null,
        public ?string $error = null,
    ) {}
}
