<?php

namespace Falak\Telemetry\Contracts\Data;

/**
 * One site's entry in telemetry.configure (`sites[]` plus its log files).
 */
final readonly class SiteTelemetryTarget
{
    /**
     * Log sources are files the agent tails as the site's logs: kind app by default, `multiline: laravel` merges
     * stack traces into their record. The edge access logs are tailed without an entry.
     *
     * @param  list<array{path: string, service?: string, format?: 'plain'|'json', kind?: 'app'|'access', multiline?: 'laravel'}>  $logSources
     * @param  ?string  $unixUser  the site's unix user: the agent sums the processes it owns when the site has no slice
     * @param  ?string  $runtime  the site's runtime (Sites' SiteRuntime value, e.g. "php-fpm", "compose")
     */
    public function __construct(
        public string $siteId,
        public string $slug,
        public ?string $environment = null,
        public ?string $deploymentId = null,
        public ?string $releaseId = null,
        public array $logSources = [],
        public ?string $unixUser = null,
        public ?string $runtime = null,
    ) {}
}
