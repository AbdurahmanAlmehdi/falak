<?php

namespace Falak\Uptime\Application;

use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Uptime\Domain\Models\Monitor;

/**
 * Where a monitor is shown: its site's Uptime tab, else the monitor selected on /observability/uptime.
 */
final class MonitorLinks
{
    public function __construct(private readonly ProjectDirectory $projects) {}

    public function url(Monitor $monitor): string
    {
        if ($monitor->site_id !== null) {
            $url = $this->projects->serviceUrl(ServiceKind::Site, $monitor->site_id, 'uptime');

            if ($url !== null) {
                return $url;
            }
        }

        return "/observability/uptime?monitor={$monitor->id}";
    }
}
