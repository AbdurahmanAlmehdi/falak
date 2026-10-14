<?php

namespace Falak\Sites\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\SiteLogSources;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves Site → Logs → Sources: the files inside the site's containers (telemetry.configure is re-sent to its servers
 * on SiteUpdated `log_sources`) and the PHP-FPM slow log threshold (the pool is re-sent to the site's ready servers;
 * the slow log source follows with telemetry.configure).
 */
final class UpdateSiteLogSources
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  `sources` (the whole list) and/or `slowlog_timeout` (seconds, 0 = off, null =
     *                                       the default); a key left out keeps its value
     * @return list<string> the attributes that changed
     *
     * @throws ValidationException
     */
    public function __invoke(Site $site, array $input, ?string $userId): array
    {
        $updates = [];

        if (array_key_exists('sources', $input)) {
            $services = $site->runtime === SiteRuntime::Compose ? $this->inspector->parse($this->compose->project($site->id) ?? '')->serviceNames() : [];
            $sources = SiteLogSources::validate($site, $input['sources'] ?? [], $services);

            if ($sources !== ($site->log_sources ?? [])) {
                $updates['log_sources'] = $sources === [] ? null : $sources;
            }
        }

        if (array_key_exists('slowlog_timeout', $input)) {
            $timeout = $input['slowlog_timeout'];

            if ($timeout !== null && $site->runtime !== SiteRuntime::PhpFpm) {
                throw ValidationException::withMessages(['slowlog_timeout' => 'Only PHP-FPM sites have a slow log.']);
            }

            if ($timeout !== null && (! is_int($timeout) || $timeout < 0 || $timeout > SiteLogSources::MAX_SLOWLOG_TIMEOUT)) {
                throw ValidationException::withMessages(['slowlog_timeout' => 'The slow log threshold is 0 (off) to '.SiteLogSources::MAX_SLOWLOG_TIMEOUT.' seconds.']);
            }

            if ($timeout !== $site->fpm_slowlog_timeout) {
                $updates['fpm_slowlog_timeout'] = $timeout;
            }
        }

        if ($updates === []) {
            return [];
        }

        $before = SiteLogSources::slowlogTimeout($site);
        $site->forceFill($updates)->save();
        $site->loadMissing('targets');

        $this->audit->record('site.log_sources_updated', 'site', $site->id, [
            'sources' => array_column($site->log_sources ?? [], 'path'),
            'slowlog_timeout' => SiteLogSources::slowlogTimeout($site),
        ], $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, array_keys($updates), $site->serverIds());

        if (SiteLogSources::slowlogTimeout($site) !== $before) {
            $this->reapplyPool($site);
        }

        return array_keys($updates);
    }

    private function reapplyPool(Site $site): void
    {
        foreach ($site->targets as $target) {
            /** @var SiteTarget $target */
            if ($target->status !== TargetStatus::Ready || $site->php_version === null) {
                continue;
            }

            $payload = CommandPayloads::fpmPool($site, (string) $site->php_version, $this->servers->phpSettings($target->server_id, (string) $site->php_version), limits: ResourceLimits::fromArray($site->limits));

            try {
                $this->agents->dispatch($target->server_id, 'runtime.fpm.pool', $payload, 300, "sites.pool.slowlog:{$site->id}:{$target->server_id}:".Str::ulid());
            } catch (AgentUnavailable) {
                // Re-sent with the pool on the next provisioning of the target.
            }
        }
    }
}
