<?php

namespace Falak\Edge\Infrastructure;

use Falak\Edge\Contracts\TailnetSites;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Sites\Contracts\SiteDirectory;

final class EloquentTailnetSites implements TailnetSites
{
    public function __construct(private readonly SiteDirectory $sites) {}

    public function onServer(string $serverId): array
    {
        $sites = $this->sites->forServer($serverId);
        $balanced = LoadBalancer::query()->where('server_id', $serverId)->pluck('site_id')->all();
        $ids = array_values(array_unique([...array_map(fn ($site) => $site->id, $sites), ...$balanced]));
        $tailnetOnly = SiteSetting::query()->whereIn('site_id', $ids)->where('tailnet_only', true)->pluck('site_id')->all();

        return array_values(array_map(fn ($site) => $site->name, $this->sites->findMany($tailnetOnly)));
    }
}
