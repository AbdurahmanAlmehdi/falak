<?php

namespace Falak\Telemetry\Application\Listeners;

use Falak\Sites\Events\SiteUpdated;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * telemetry.configure carries a site's log sources (Site → Logs → Sources, the PHP-FPM slow log): resend it to the
 * site's servers when they change.
 */
final class ReconfigureOnLogSourceChanges implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(SiteUpdated $event): void
    {
        if (! $event->changed('log_sources', 'fpm_slowlog_timeout')) {
            return;
        }

        foreach (array_unique($event->serverIds) as $serverId) {
            $this->configurator->reconfigure($serverId);
        }
    }
}
