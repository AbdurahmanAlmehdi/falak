<?php

namespace Falak\Uptime\Domain\Probing;

use Falak\Uptime\Domain\Enums\MonitorType;

/**
 * What one check probes (built from a monitor).
 */
final readonly class ProbeTarget
{
    public function __construct(
        public MonitorType $type,
        /** http: the URL. */
        public ?string $url = null,
        /** tcp / tls: host and port. */
        public ?string $host = null,
        public ?int $port = null,
        public int $timeoutSeconds = 10,
        public int $maxRedirects = 5,
        public string $expectedStatus = ExpectedStatus::DEFAULT,
        public ?string $keyword = null,
        public bool $keywordAbsent = false,
        public int $bodyBytes = 65536,
        /** Inspect the certificate chain (always for tls; for https once an hour). */
        public bool $inspectTls = false,
        /** Site domain monitors may reach the control plane's own public addresses (single-host installs). */
        public bool $allowSelf = false,
    ) {}
}
