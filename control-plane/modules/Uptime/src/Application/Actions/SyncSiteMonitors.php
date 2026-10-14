<?php

namespace Falak\Uptime\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Keeps one automatic `https://<domain>/` monitor per non-wildcard domain of every site (and its test domain): creates
 * the missing ones, deletes those whose domain is gone (history included). Run on domain events and every 10 minutes.
 */
final class SyncSiteMonitors
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DomainRecords $domains,
        private readonly DeleteMonitor $delete,
    ) {}

    public function organization(string $organizationId): void
    {
        $sites = $this->sites->forOrganization($organizationId);
        $wanted = [];

        foreach ($sites as $site) {
            $wanted[$site->id] = [];

            if ($site->testDomain !== null) {
                $wanted[$site->id][] = strtolower($site->testDomain);
            }
        }

        foreach ($this->domains->forSites(array_keys($wanted)) as $domain) {
            if (! str_starts_with($domain['name'], '*.')) {
                $wanted[$domain['site_id']][] = strtolower($domain['name']);
            }
        }

        $names = [];

        foreach ($sites as $site) {
            $names[$site->id] = $site->name;
        }

        $keep = [];

        foreach ($wanted as $siteId => $hosts) {
            foreach (array_unique($hosts) as $host) {
                $key = self::key($siteId, $host);
                $keep[] = $key;
                $this->ensure($organizationId, $siteId, $host, $key, $names[$siteId] ?? $host);
            }
        }

        Monitor::query()->where('organization_id', $organizationId)->where('source', MonitorSource::Auto)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('auto_key', $keep))
            ->get()->each(fn (Monitor $monitor) => ($this->delete)($monitor));
    }

    public static function key(string $siteId, string $host): string
    {
        return 'site:'.strtolower($siteId).':'.$host;
    }

    private function ensure(string $organizationId, string $siteId, string $host, string $key, string $siteName): void
    {
        if (Monitor::query()->where('organization_id', $organizationId)->where('auto_key', $key)->exists()) {
            return;
        }

        try {
            Monitor::query()->create([
                'organization_id' => $organizationId,
                'site_id' => $siteId,
                'source' => MonitorSource::Auto,
                'auto_key' => $key,
                'type' => MonitorType::Http,
                'name' => mb_substr($host === $siteName ? $host : "{$siteName} ({$host})", 0, 120),
                'url' => "https://{$host}/",
                'expected_status' => '2xx,3xx',
                'max_redirects' => (int) config('uptime.max_redirects', 5),
                'timeout_seconds' => (int) config('uptime.default_timeout', 10),
                'interval_seconds' => (int) config('uptime.default_interval', 60),
                'status' => MonitorStatus::Pending,
                // Spread the first checks over a minute.
                'next_check_at' => CarbonImmutable::now()->addSeconds(random_int(0, 59)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently.
        }
    }
}
