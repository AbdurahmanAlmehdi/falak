<?php

namespace Falak\Uptime\Application\Probing;

use Falak\Uptime\Domain\Enums\ErrorKind;

final readonly class ConnectOutcome
{
    public function __construct(
        public bool $ok,
        public ?int $connectMs = null,
        public ?ErrorKind $errorKind = null,
        public ?string $error = null,
    ) {}
}
