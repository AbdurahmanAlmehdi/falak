<?php

namespace Falak\Sites\Infrastructure;

use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\Data\PhpSettings;
use Falak\Sites\Application\SiteLogSources;
use Falak\Sites\Domain\Models\Site;

/**
 * Agent command payloads built by Sites (validated against contracts/agent-protocol by the gateway).
 */
final class CommandPayloads
{
    /**
     * @return array<string, mixed> system.user.create
     */
    public static function user(Site $site): array
    {
        return [
            'name' => $site->unix_user,
            'home' => $site->rootPath(),
            'shell' => '/bin/bash',
            'isolated' => true,
        ];
    }

    /**
     * @param  ?ResourceLimits  $limits  the site's effective limits: with memory, CPU or process limits the pool runs in
     *                                   its own PHP-FPM master inside the site's slice (a shared master's pools share
     *                                   its cgroup)
     * @return array<string, mixed> runtime.fpm.pool
     */
    public static function fpmPool(Site $site, string $phpVersion, ?PhpSettings $settings, string $state = 'present', ?ResourceLimits $limits = null): array
    {
        if ($state === 'absent') {
            return ['php_version' => $phpVersion, 'pool' => $site->slug, 'user' => $site->unix_user, 'state' => 'absent'];
        }

        $fpm = $settings->fpm ?? ['pm' => 'dynamic', 'max_children' => 5, 'start_servers' => 2, 'min_spare_servers' => 1, 'max_spare_servers' => 3, 'max_requests' => 500];

        $payload = [
            'php_version' => $phpVersion,
            'pool' => $site->slug,
            'user' => $site->unix_user,
            'group' => $site->unix_user,
            'listen' => "/run/php/falak-{$site->slug}-{$phpVersion}.sock",
            'pm' => $fpm['pm'],
            'max_children' => max(1, (int) $fpm['max_children']),
            'start_servers' => max(1, (int) $fpm['start_servers']),
            'min_spare_servers' => max(1, (int) $fpm['min_spare_servers']),
            'max_spare_servers' => max(1, (int) $fpm['max_spare_servers']),
            'max_requests' => max(0, (int) $fpm['max_requests']),
            'state' => 'present',
        ];

        if ($site->isolated) {
            // Keep PHP inside the site's own tree. Its .env and Laravel's config cache link to the agent's tmpfs, and PHP
            // checks the resolved path.
            $tmpfs = rtrim((string) config('sites.env_dir', '/run/falak/env'), '/')."/{$site->slug}";
            $payload['php_admin_values'] = ['open_basedir' => $site->rootPath()."/:/tmp/:/usr/share/php/:{$tmpfs}.env:{$tmpfs}.d/"];
        }

        // The pool's slow log (stripped for agents without runtime.fpm.slowlog), shipped as the site's php_fpm_slow logs.
        if (($slowlog = SiteLogSources::slowlogTimeout($site)) > 0) {
            $payload['slowlog_timeout'] = $slowlog;
        }

        if ($limits?->hasCgroupLimits()) {
            $payload['slice'] = ResourceLimits::sliceName('site', $site->slug);

            if ($limits->oomScoreAdj() !== 0) {
                $payload['oom_score_adj'] = $limits->oomScoreAdj();
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $mask  the site's secret variable names (masked in the output; the command reads .env)
     * @return array<string, mixed> system.exec
     */
    public static function exec(Site $site, string $command, array $env = [], array $mask = []): array
    {
        $current = escapeshellarg($site->currentPath());

        $payload = [
            'script' => "set -e\nif [ ! -d {$current} ]; then echo 'The site has not been deployed yet.' >&2; exit 1; fi\ncd {$current}\n{$command}\n",
            'shell' => '/bin/bash',
            'user' => $site->unix_user,
        ];

        if ($env !== []) {
            $payload['env'] = $env;
        }

        if ($mask !== []) {
            $payload['site'] = $site->slug;
            $payload['mask'] = $mask;
        }

        return $payload;
    }
}
