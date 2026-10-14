<?php

namespace Falak\Telemetry\Contracts\Data;

/**
 * One site's entry in telemetry.configure (`sites[]` plus its log files).
 */
final readonly class SiteTelemetryTarget
{
    /**
     * Log sources are files the agent tails as the site's logs: kind app by default, `multiline: laravel` merges
     * stack traces into their record. The edge access logs are tailed without an entry. `container: true`: the path is
     * inside the site's containers (of `compose_service`), `multiline_pattern` starts a record, `origin` is the
     * falak.log.origin of the records (php_fpm, php_fpm_slow; file by default).
     *
     * @param  list<array{path: string, service?: string, format?: 'plain'|'json', kind?: 'app'|'access', multiline?: 'laravel', multiline_pattern?: string, container?: bool, compose_service?: string, origin?: 'file'|'php_fpm'|'php_fpm_slow'}>  $logSources
     * @param  ?string  $unixUser  the site's unix user: the agent sums the processes it owns when the site has no slice (a
     *                             user several sites share: those it attributes to this site)
     * @param  ?string  $runtime  the site's runtime (Sites' SiteRuntime value, e.g. "php-fpm", "compose")
     * @param  bool  $isolated  the site runs as a user of its own: only then is the user's every process the site's
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
        public bool $isolated = false,
    ) {}
}
