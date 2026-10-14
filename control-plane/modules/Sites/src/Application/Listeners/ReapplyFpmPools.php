<?php

namespace Falak\Sites\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Pools are only written when a site is provisioned. An upgraded agent keeps .env files on the tmpfs, which isolated
 * pools' open_basedir must allow (PHP checks the resolved path): re-apply them before the next deploy needs it. An
 * agent that understands the pool's slow log (runtime.fpm.slowlog) also gets the pools whose slow log is on (sites
 * created while it ran an older agent, which stripped it). Sites that predate the slow log have it off: their pool
 * file is unchanged, so the upgrade reloads no PHP-FPM master for them.
 */
final class ReapplyFpmPools implements ShouldQueue
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function handle(AgentVersionChanged $event): void
    {
        if ($event->serverId === null) {
            return;
        }

        $slowlog = in_array('runtime.fpm.slowlog', $event->features, true);

        $sites = Site::query()
            ->where('runtime', SiteRuntime::PhpFpm->value)
            ->where(fn ($q) => $q->where('isolated', true)->when($slowlog, fn ($q) => $q->orWhere('fpm_slowlog_timeout', '>', 0)))
            ->whereNotNull('php_version')
            ->whereHas('targets', fn ($q) => $q->where('server_id', $event->serverId))
            ->get();

        foreach ($sites as $site) {
            $payload = CommandPayloads::fpmPool($site, (string) $site->php_version, $this->servers->phpSettings($event->serverId, (string) $site->php_version), limits: ResourceLimits::fromArray($site->limits));

            try {
                $this->agents->dispatch($event->serverId, 'runtime.fpm.pool', $payload, 300, "sites.pool.reapply:{$site->id}:{$event->serverId}:".Str::ulid());
            } catch (AgentUnavailable) {
                return;
            }
        }
    }
}
