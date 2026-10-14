<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Identity\Events\OrganizationDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Tenant cleanup. Objects already uploaded to the organization's buckets are left in place.
 */
final class DeleteOrganizationData implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        BackupSchedule::query()->where('organization_id', $event->organizationId)->delete();
        DatabaseInstance::query()->where('organization_id', $event->organizationId)->get()->each->delete();
        Backup::query()->where('organization_id', $event->organizationId)->delete();
        PitrSegment::query()->where('organization_id', $event->organizationId)->delete();
        PitrGap::query()->where('organization_id', $event->organizationId)->delete();
        StorageProvider::query()->where('organization_id', $event->organizationId)->delete();
        // Query shapes (their hourly stats and size samples go with the daily prune of what deleted instances left).
        DB::table('databases_query_shapes')->where('organization_id', $event->organizationId)->delete();
    }
}
