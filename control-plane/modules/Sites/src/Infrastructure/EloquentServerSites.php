<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Volumes\Contracts\VolumeMounts;

/**
 * Sites hosted on a server for telemetry.configure: the slug → site id mapping (the agent labels logs, spans and
 * cron heartbeats with it) and the site's log files.
 *
 * PHP sites served by the edge (FrankenPHP runs PHP inside the edge process, PHP-FPM pools share the FPM master's
 * stderr) cannot be told apart on stderr, so their application logs are read from files: the shared log directory
 * (`storage/logs` — Laravel's `daily`/`single` channels, the default `LOG_CHANNEL` of new Laravel sites — or
 * Symfony's `var/log`) is tailed as the site's `app` logs, with Laravel's multi-line stack traces merged into their
 * record. Supervised programs, cron and containers are attributed by the agent itself; the edge's per-site access
 * logs are always tailed. The unix user and runtime let the agent measure a native site without a cgroup slice by the
 * processes its user owns: all of them for an isolated site's own user (`isolated`); for the user every non-isolated
 * site shares, the agent attributes each process to one site (its PHP-FPM pool, else its working directory under the
 * site's tree). Agents too old for that get the user of isolated sites only (Fleet's PayloadCompatibility).
 * Sites running in Docker (containers, functions) are measured through their containers and get no user.
 */
final class EloquentServerSites implements ServerSites
{
    /** Shared directory → log files under it. */
    private const LOG_DIRECTORIES = [
        'storage' => 'logs/*.log',
        'var/log' => '*.log',
        'var' => 'log/*.log',
    ];

    public function __construct(private readonly VolumeMounts $volumes) {}

    public function forServer(string $serverId): array
    {
        return Site::query()
            ->whereIn('id', SiteTarget::query()->select('site_id')->where('server_id', strtolower($serverId))->where('status', '!=', TargetStatus::Removing->value))
            ->orderBy('slug')
            ->get()
            ->map(fn (Site $site) => new SiteTelemetryTarget(
                $site->id,
                $site->slug,
                logSources: $this->logSources($site),
                unixUser: $site->runtime->usesDocker() ? null : $site->unix_user,
                runtime: $site->runtime->value,
                isolated: (bool) $site->isolated,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array{path: string, kind: 'app', multiline?: 'laravel'}>
     */
    private function logSources(Site $site): array
    {
        if (! $site->runtime->isPhp()) {
            return [];
        }

        $sources = [];

        foreach ($this->volumes->sharedPaths($site->id) as $shared) {
            /** @var SharedPath $shared */
            $files = self::LOG_DIRECTORIES[trim($shared->path, '/')] ?? null;

            if ($files === null || $shared->type !== 'directory') {
                continue;
            }

            $source = ['path' => $site->rootPath().'/shared/'.trim($shared->path, '/').'/'.$files, 'kind' => 'app'];

            if ($site->framework->isLaravel()) {
                $source['multiline'] = 'laravel';
            }

            $sources[] = $source;
        }

        return $sources;
    }
}
