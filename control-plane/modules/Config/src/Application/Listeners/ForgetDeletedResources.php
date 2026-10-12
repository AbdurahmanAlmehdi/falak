<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Databases\Events\DatabaseDeleted;
use Falak\Edge\Events\DomainRemoved;
use Falak\Sites\Events\SiteDeleted;

/**
 * A resource deleted through its module (any path: the dashboard, the API, a run) leaves the unmanaged list. A site
 * takes its workers, domains, rules and schedules with it. **[decision]** Modules without a deleted event (workers,
 * volumes, schedules…) leave it when the dashboard dismisses the row after its delete succeeded.
 */
final class ForgetDeletedResources
{
    public function siteDeleted(SiteDeleted $event): void
    {
        ConfigDetached::query()->where(fn ($q) => $q->where('site_id', $event->siteId)->orWhere(fn ($q) => $q->where('resource_type', 'site')->where('resource_id', $event->siteId)))->delete();
    }

    public function domainRemoved(DomainRemoved $event): void
    {
        ConfigDetached::query()->where('resource_type', 'domain')->where('resource_id', $event->domainId)->delete();
    }

    public function databaseDeleted(DatabaseDeleted $event): void
    {
        ConfigDetached::query()->where('resource_type', 'database')->where('resource_id', $event->databaseId)->delete();
    }
}
