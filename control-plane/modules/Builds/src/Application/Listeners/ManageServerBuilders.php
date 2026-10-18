<?php

namespace Falak\Builds\Application\Listeners;

use Falak\Builds\Application\Actions\InstallServerBuilder;
use Falak\Builds\Application\BuildProgress;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Events\SiteDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Builder servers become build workers when provisioned; a server builder's caps follow the server's facts
 * (memory / cores); deleted servers / organizations / sites take their builders and queued builds with them.
 */
final class ManageServerBuilders implements ShouldQueue
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly InstallServerBuilder $install,
        private readonly BuildProgress $progress,
    ) {}

    public function provisioned(ServerProvisioned $event): void
    {
        if ($event->type !== ServerType::Builder->value) {
            return;
        }

        if ($server = $this->servers->find($event->serverId)) {
            ($this->install)($server);
        }
    }

    /**
     * Facts reports are frequent: only queue the ones that change a server builder's caps (checked synchronously).
     */
    public function shouldQueue(object $event): bool
    {
        return ! $event instanceof AgentFactsReported || $this->staleBuilder($event) !== null;
    }

    public function facts(AgentFactsReported $event): void
    {
        if ($builder = $this->staleBuilder($event)) {
            $this->install->refreshCaps($builder, $event->facts);
        }
    }

    private function staleBuilder(AgentFactsReported $event): ?Builder
    {
        $builder = $event->serverId !== null ? Builder::query()->where('server_id', $event->serverId)->where('kind', Builder::KIND_SERVER)->first() : null;

        return $builder !== null && InstallServerBuilder::capsStale($builder, $event->facts) ? $builder : null;
    }

    public function serverDeleted(ServerDeleted $event): void
    {
        // The control plane's own builder outlives its machine's server record: it only loses the pin.
        Builder::query()->where('server_id', $event->serverId)->where('kind', Builder::KIND_LOCAL)->update(['server_id' => null]);
        Builder::query()->where('server_id', $event->serverId)->delete();
        Build::query()->where('builder_server_id', $event->serverId)->where('status', BuildStatus::Queued)->get()
            ->each(fn (Build $build) => $this->progress->fail($build, 'The server it was to be built on was deleted.'));
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        Builder::query()->where('organization_id', $event->organizationId)->delete();
        Build::query()->where('organization_id', $event->organizationId)->whereIn('status', BuildStatus::active())
            ->update(['status' => BuildStatus::Cancelled, 'error' => 'The organization was deleted.', 'finished_at' => now()]);
    }

    public function siteDeleted(SiteDeleted $event): void
    {
        Build::query()->where('site_id', $event->siteId)->whereIn('status', BuildStatus::active())
            ->update(['status' => BuildStatus::Cancelled, 'error' => 'The site was deleted.', 'finished_at' => now()]);
    }
}
