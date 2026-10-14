<?php

namespace Falak\Edge\Infrastructure\Cloudflare;

use RuntimeException;

/** Not final: CloudflareGraphqlError carries the GraphQL Analytics API's error list. */
class CloudflareError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly int $cloudflareCode = 0)
    {
        parent::__construct($message);
    }
}
