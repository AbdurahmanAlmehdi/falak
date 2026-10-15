<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Contracts\AgentUploads;
use InvalidArgumentException;

final class AgentUploadRegistry implements AgentUploads
{
    /** @var array<string, class-string> */
    private array $handlers = [];

    public function register(string $type, string $handler): void
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $type) !== 1) {
            throw new InvalidArgumentException("Invalid agent upload type [{$type}].");
        }

        $this->handlers[$type] = $handler;
    }

    public function handler(string $type): ?string
    {
        return $this->handlers[$type] ?? null;
    }
}
