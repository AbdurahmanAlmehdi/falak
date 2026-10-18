<?php

namespace Falak\Edge\Infrastructure;

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\MakePrimaryDomain;
use Falak\Edge\Application\Actions\RemoveDomain;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsRecord;
use Falak\Edge\Domain\Models\Domain;
use Falak\Sites\Contracts\SiteDirectory;

final class EloquentDomainRecords implements DomainRecords
{
    private const RANK = ['synced' => 0, 'pending' => 1, 'conflict' => 2, 'error' => 3];

    public function forSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $domains = Domain::query()->whereIn('site_id', $siteIds)->orderBy('name')->get();
        $records = DnsRecord::query()->whereIn('domain_id', $domains->modelKeys())->get()->groupBy('domain_id');
        $zones = CloudflareZone::query()->whereIn('organization_id', $domains->pluck('organization_id')->unique()->all())->get();

        return $domains->map(function (Domain $domain) use ($records, $zones) {
            $own = $records->get($domain->id, collect());
            $inZone = $zones->contains(fn (CloudflareZone $zone) => $zone->organization_id === $domain->organization_id
                && ($domain->name === $zone->name || str_ends_with($domain->name, ".{$zone->name}")));
            $status = $own->map(fn (DnsRecord $record) => $record->status)->sortBy(fn (string $status) => self::RANK[$status] ?? 0)->last();

            return ['site_id' => $domain->site_id, 'name' => $domain->name, 'managed' => $own->isNotEmpty() || $inZone, 'status' => $status];
        })->values()->all();
    }

    public function followServer(string $siteId, string $fromIpv4, string $toIpv4): array
    {
        $site = app(SiteDirectory::class)->find($siteId);
        $old = '.'.str_replace('.', '-', $fromIpv4).'.';

        if ($site === null || $fromIpv4 === $toIpv4) {
            return [];
        }

        $renamed = [];

        foreach (Domain::query()->where('site_id', $siteId)->where('name', 'like', "%{$old}%")->get() as $domain) {
            /** @var Domain $domain */
            $name = str_replace($old, '.'.str_replace('.', '-', $toIpv4).'.', $domain->name);
            $new = app(AddDomain::class)($site, $name, $domain->tls_mode, $domain->www_redirect, $domain->certificate_id, $domain->dns_credential_id, $domain->compose_service);

            if ($domain->is_primary) {
                app(MakePrimaryDomain::class)($new);
            }

            app(RemoveDomain::class)($domain->refresh());
            $renamed[$domain->name] = $name;
        }

        return $renamed;
    }
}
