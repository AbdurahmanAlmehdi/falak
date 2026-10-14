<?php

namespace Falak\Telemetry\Application\Listeners;

use Falak\Sites\Events\SiteDeleted;
use Falak\Telemetry\Domain\Models\HttpSettings;
use Illuminate\Support\Facades\DB;

/**
 * A deleted site's HTTP analytics rows go with it: its thresholds and its bandwidth history (keyed by slug, which a
 * new site may reuse).
 */
final class ForgetDeletedSiteTraffic
{
    public function handle(SiteDeleted $event): void
    {
        HttpSettings::query()->whereKey(strtolower($event->siteId))->delete();
        DB::table('telemetry_http_bandwidth')
            ->where('organization_id', strtolower($event->organizationId))
            ->where('site_slug', $event->slug)
            ->delete();
    }
}
