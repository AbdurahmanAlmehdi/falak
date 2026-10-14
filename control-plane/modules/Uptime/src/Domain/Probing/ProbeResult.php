<?php

namespace Falak\Uptime\Domain\Probing;

use Falak\Uptime\Domain\Enums\ErrorKind;

/**
 * The outcome of probing a target once. Times in milliseconds.
 */
final readonly class ProbeResult
{
    public function __construct(
        public bool $ok,
        public ?ErrorKind $errorKind = null,
        public ?string $error = null,
        public ?int $statusCode = null,
        public ?string $ip = null,
        public ?int $dnsMs = null,
        public ?int $connectMs = null,
        public ?int $tlsMs = null,
        public ?int $ttfbMs = null,
        public ?int $totalMs = null,
        public ?TlsReport $tls = null,
        public int $redirects = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $extra  named constructor arguments (statusCode, ip, timings)
     */
    public static function failure(ErrorKind $kind, string $error, array $extra = []): self
    {
        return new self(false, $kind, mb_substr($error, 0, 300), ...$extra);
    }

    public function withTls(?TlsReport $tls): self
    {
        return new self($this->ok, $this->errorKind, $this->error, $this->statusCode, $this->ip, $this->dnsMs, $this->connectMs,
            $this->tlsMs, $this->ttfbMs, $this->totalMs, $tls, $this->redirects);
    }
}
