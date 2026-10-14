<?php

namespace Falak\Uptime\Application\Jobs;

use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Uptime\Application\Actions\SyncSiteMonitors;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Site monitors of one organization (after a domain event, once committed) or, every 10 minutes, of all of them
 * (domains added before the module, or events missed).
 */
final class SyncAllSiteMonitors implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public ?string $organizationId = null) {}

    public function handle(OrganizationDirectory $organizations, SyncSiteMonitors $sync): void
    {
        if ($this->organizationId !== null) {
            $sync->organization($this->organizationId);

            return;
        }

        // Fanned out: one job per organization on the uptime queue.
        foreach ($organizations->all() as $organization) {
            self::dispatch($organization->id)->onQueue((string) config('uptime.queue', 'uptime'));
        }
    }
}
