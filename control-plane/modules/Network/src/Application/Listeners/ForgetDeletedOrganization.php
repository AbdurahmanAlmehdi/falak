<?php

namespace Falak\Network\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Network\Domain\Models\TailscaleNode;
use Falak\Network\Domain\Models\TailscaleSetting;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Drops a deleted organization's Tailscale key and nodes. */
final class ForgetDeletedOrganization implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        TailscaleNode::query()->where('organization_id', $event->organizationId)->delete();
        TailscaleSetting::query()->whereKey($event->organizationId)->delete();
    }
}
