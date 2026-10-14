<?php

namespace Falak\Edge\Application;

use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareGraphqlError;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cloudflare traffic of a site's proxied names (docs/plans/network/tools.md part 2), from the GraphQL Analytics API
 * with the token of the connection managing each zone. The zone's settings node says which datasets the token / plan
 * can read and how far back; every dataset degrades on its own (a note instead of its block).
 *
 * Reports are cached for five minutes per zone, hosts and window; the token never leaves this class.
 */
final class CloudflareAnalytics
{
    public const CACHE_TTL = 300;

    /** Errors are cached shortly: a token fixed in Cloudflare shows within a minute. */
    public const ERROR_TTL = 60;

    public const WINDOW = 86400;

    public const DAYS = 7;

    /** cacheStatus values served from Cloudflare's cache; everything else (miss, expired, bypass, dynamic, none) hit the origin. */
    public const CACHED_STATUSES = ['hit', 'stale', 'updating', 'revalidated'];

    /** Firewall actions that stopped or challenged a request (Cloudflare's "mitigated"). */
    public const MITIGATED_ACTIONS = ['block', 'challenge', 'jschallenge', 'managed_challenge', 'connection_close', 'force_connect_error'];

    public const DATASETS = ['httpRequestsAdaptiveGroups', 'firewallEventsAdaptiveGroups', 'httpRequests1dGroups'];

    private const SETTINGS_QUERY = <<<'GRAPHQL'
        query FalakAnalyticsSettings($zoneTag: string!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              settings {
                httpRequestsAdaptiveGroups { enabled maxDuration notOlderThan }
                firewallEventsAdaptiveGroups { enabled maxDuration notOlderThan }
                httpRequests1dGroups { enabled maxDuration notOlderThan }
              }
            }
          }
        }
        GRAPHQL;

    private const TRAFFIC_QUERY = <<<'GRAPHQL'
        query FalakTraffic($zoneTag: string!, $since: Time!, $until: Time!, $hosts: [string!]!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              cache: httpRequestsAdaptiveGroups(limit: 50, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [count_DESC]) {
                count
                sum { edgeResponseBytes visits }
                dimensions { cacheStatus }
              }
              countries: httpRequestsAdaptiveGroups(limit: 10, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [count_DESC]) {
                count
                dimensions { clientCountryName }
              }
              statuses: httpRequestsAdaptiveGroups(limit: 100, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [count_DESC]) {
                count
                dimensions { edgeResponseStatus }
              }
              series: httpRequestsAdaptiveGroups(limit: 1000, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [datetimeHour_ASC]) {
                count
                dimensions { datetimeHour cacheStatus }
              }
            }
          }
        }
        GRAPHQL;

    private const THREATS_QUERY = <<<'GRAPHQL'
        query FalakThreats($zoneTag: string!, $since: Time!, $until: Time!, $hosts: [string!]!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              actions: firewallEventsAdaptiveGroups(limit: 20, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [count_DESC]) {
                count
                dimensions { action }
              }
              countries: firewallEventsAdaptiveGroups(limit: 5, filter: {datetime_geq: $since, datetime_lt: $until, clientRequestHTTPHost_in: $hosts}, orderBy: [count_DESC]) {
                count
                dimensions { clientCountryName }
              }
            }
          }
        }
        GRAPHQL;

    private const DAILY_QUERY = <<<'GRAPHQL'
        query FalakDaily($zoneTag: string!, $from: Date!, $to: Date!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              days: httpRequests1dGroups(limit: 10, filter: {date_geq: $from, date_leq: $to}, orderBy: [date_ASC]) {
                dimensions { date }
                sum { requests cachedRequests bytes cachedBytes threats }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * One report per managed zone holding proxied names of the site; [] when it has none.
     *
     * @return list<array<string, mixed>>
     */
    public function forSite(SiteData $site, bool $refresh = false): array
    {
        $reports = [];

        foreach ($this->proxiedHosts($site) as ['zone' => $zone, 'hosts' => $hosts, 'skipped' => $skipped]) {
            $report = $this->forZone($zone, $hosts, $refresh);

            if ($skipped !== []) {
                $report['notes'][] = 'Wildcard names are not counted: '.implode(', ', $skipped).'.';
            }

            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * The site's names Cloudflare proxies, grouped by the managed zone covering them.
     *
     * @return list<array{zone: CloudflareZone, hosts: list<string>, skipped: list<string>}>
     */
    public function proxiedHosts(SiteData $site): array
    {
        $zones = CloudflareZone::query()->with('credential')->where('organization_id', $site->organizationId)->get();

        if ($zones->isEmpty()) {
            return [];
        }

        $groups = [];

        foreach (Domain::query()->where('site_id', $site->id)->orderBy('name')->get() as $domain) {
            $zone = $zones->filter(fn (CloudflareZone $z) => $z->covers($domain->name))->sortByDesc(fn (CloudflareZone $z) => strlen($z->name))->first();

            if ($zone === null || ! ($domain->cloudflare_proxied ?? $zone->proxied)) {
                continue;
            }

            $groups[$zone->id] ??= ['zone' => $zone, 'hosts' => [], 'skipped' => []];

            foreach ($domain->hosts() as $host) {
                $key = str_starts_with($host, '*') ? 'skipped' : 'hosts';
                $groups[$zone->id][$key][] = strtolower($host);
            }
        }

        return array_values(array_map(function (array $group) {
            $group['hosts'] = array_values(array_unique($group['hosts']));
            sort($group['hosts']);
            $group['skipped'] = array_values(array_unique($group['skipped']));

            return $group;
        }, array_filter($groups, fn (array $group) => $group['hosts'] !== [])));
    }

    /**
     * @param  list<string>  $hosts  sorted
     * @return array<string, mixed>
     */
    public function forZone(CloudflareZone $zone, array $hosts, bool $refresh = false): array
    {
        $now = Carbon::now()->utc();
        $bucket = intdiv($now->getTimestamp(), self::CACHE_TTL);
        $key = 'edge:cf-analytics:'.$zone->zone_id.':'.sha1(implode(',', $hosts)).':'.$bucket;

        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $report = $this->build($zone, $hosts, $now);
        Cache::put($key, $report, $report['error'] === null ? self::CACHE_TTL : self::ERROR_TTL);

        return $report;
    }

    /**
     * @param  list<string>  $hosts
     * @return array<string, mixed>
     */
    private function build(CloudflareZone $zone, array $hosts, Carbon $now): array
    {
        $connection = (string) ($zone->credential?->name ?? 'Cloudflare');
        $report = [
            'zone' => $zone->name,
            'connection' => $connection,
            'hosts' => $hosts,
            'window' => null,
            'traffic' => null,
            'threats' => null,
            'daily' => null,
            'notes' => [],
            'error' => null,
            'permission_missing' => false,
            'fetched_at' => $now->toIso8601String(),
        ];

        if ($zone->credential === null) {
            return [...$report, 'error' => 'The Cloudflare connection of this zone is gone.'];
        }

        $api = CloudflareApi::with($zone->credential->api_token);
        $vars = ['zoneTag' => $zone->zone_id];

        try {
            $settings = $this->zoneNode($api->graphql(self::SETTINGS_QUERY, $vars))['settings'] ?? null;
        } catch (CloudflareGraphqlError $e) {
            return $e->unauthorized() ? $this->missingPermission($report, $connection, $zone->name) : [...$report, 'error' => $e->getMessage()];
        } catch (CloudflareError $e) {
            return ($e->status === 401 || $e->status === 403) ? $this->missingPermission($report, $connection, $zone->name) : [...$report, 'error' => $e->getMessage()];
        }

        if (! is_array($settings)) {
            // The token does not see the zone at all.
            return $this->missingPermission($report, $connection, $zone->name);
        }

        $available = fn (string $dataset) => is_array($settings[$dataset] ?? null) && ($settings[$dataset]['enabled'] ?? false) === true;

        if (! $available('httpRequestsAdaptiveGroups') && ! $available('firewallEventsAdaptiveGroups') && ! $available('httpRequests1dGroups')) {
            return $this->missingPermission($report, $connection, $zone->name);
        }

        // The last 24 h, as far as the datasets allow.
        $until = $now->copy()->startOfMinute();
        $span = self::WINDOW;
        foreach (['httpRequestsAdaptiveGroups', 'firewallEventsAdaptiveGroups'] as $dataset) {
            if ($available($dataset)) {
                $span = min($span, $this->limit($settings[$dataset], 'maxDuration', $span), $this->limit($settings[$dataset], 'notOlderThan', $span));
            }
        }
        $since = $until->copy()->subSeconds(max(60, $span));
        $report['window'] = ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String()];

        if ($span < self::WINDOW) {
            $report['notes'][] = sprintf('Cloudflare allows %s of analytics on this zone: the figures cover that.', $this->duration($span));
        }

        $window = [...$vars, 'since' => $since->format('Y-m-d\TH:i:s\Z'), 'until' => $until->format('Y-m-d\TH:i:s\Z'), 'hosts' => $hosts];

        if ($available('httpRequestsAdaptiveGroups')) {
            [$data, $note] = $this->query($api, self::TRAFFIC_QUERY, $window, 'HTTP traffic');
            $report['traffic'] = $data !== null ? $this->traffic($data) : null;
            $report['notes'] = [...$report['notes'], ...array_filter([$note])];
        } else {
            $report['notes'][] = 'HTTP traffic analytics are not available on this zone’s plan.';
        }

        if ($available('firewallEventsAdaptiveGroups')) {
            [$data, $note] = $this->query($api, self::THREATS_QUERY, $window, 'Firewall events');
            $report['threats'] = $data !== null ? $this->threats($data) : null;
            $report['notes'] = [...$report['notes'], ...array_filter([$note])];
        } else {
            $report['notes'][] = 'Firewall events are not available on this zone’s plan.';
        }

        if ($available('httpRequests1dGroups')) {
            $days = min(self::DAYS, max(1, intdiv($this->limit($settings['httpRequests1dGroups'], 'notOlderThan', self::DAYS * 86400), 86400)));
            [$data, $note] = $this->query($api, self::DAILY_QUERY, [...$vars, 'from' => $until->copy()->subDays($days - 1)->toDateString(), 'to' => $until->toDateString()], 'Daily zone totals');
            $report['daily'] = $data !== null ? $this->daily($data) : null;
            $report['notes'] = [...$report['notes'], ...array_filter([$note])];
        }

        return $report;
    }

    /**
     * Runs one dataset query: its zone node (null when nothing came back) and a note when Cloudflare reported errors.
     *
     * @param  array<string, mixed>  $vars
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function query(CloudflareApi $api, string $query, array $vars, string $label): array
    {
        try {
            return [$this->zoneNode($api->graphql($query, $vars)), null];
        } catch (CloudflareGraphqlError $e) {
            $node = $this->zoneNode($e->data);
            $reason = $e->unauthorized() ? 'not available with this token or on this zone’s plan' : 'failed: '.$e->getMessage();

            return [$node === [] ? null : $node, "{$label} {$reason}."];
        } catch (CloudflareError $e) {
            return [null, "{$label} failed: {$e->getMessage()}."];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function zoneNode(array $data): array
    {
        $zone = $data['viewer']['zones'][0] ?? null;

        return is_array($zone) ? $zone : [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function traffic(array $node): array
    {
        $requests = $cachedRequests = $bytes = $cachedBytes = $visits = 0;
        $byStatus = [];

        foreach ((array) ($node['cache'] ?? []) as $group) {
            $count = (int) ($group['count'] ?? 0);
            $groupBytes = (int) ($group['sum']['edgeResponseBytes'] ?? 0);
            $status = (string) ($group['dimensions']['cacheStatus'] ?? 'unknown');
            $requests += $count;
            $bytes += $groupBytes;
            $visits += (int) ($group['sum']['visits'] ?? 0);
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $count;

            if (in_array($status, self::CACHED_STATUSES, true)) {
                $cachedRequests += $count;
                $cachedBytes += $groupBytes;
            }
        }

        arsort($byStatus);

        $classes = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        foreach ((array) ($node['statuses'] ?? []) as $group) {
            $code = (int) ($group['dimensions']['edgeResponseStatus'] ?? 0);
            $class = $code >= 200 && $code < 600 ? intdiv($code, 100).'xx' : 'other';
            $classes[$class] += (int) ($group['count'] ?? 0);
        }

        $series = [];
        foreach ((array) ($node['series'] ?? []) as $group) {
            $hour = (string) ($group['dimensions']['datetimeHour'] ?? '');
            if ($hour === '') {
                continue;
            }
            $t = Carbon::parse($hour)->utc()->toIso8601String();
            $series[$t] ??= ['t' => $t, 'cached' => 0, 'origin' => 0];
            $series[$t][in_array((string) ($group['dimensions']['cacheStatus'] ?? ''), self::CACHED_STATUSES, true) ? 'cached' : 'origin'] += (int) ($group['count'] ?? 0);
        }
        ksort($series);

        return [
            'requests' => $requests,
            'cached_requests' => $cachedRequests,
            'cached_ratio' => $requests > 0 ? round($cachedRequests / $requests, 4) : null,
            'bytes' => $bytes,
            'cached_bytes' => $cachedBytes,
            'visits' => $visits,
            'cache_status' => array_map(fn ($status, $count) => ['status' => (string) $status, 'requests' => $count], array_keys($byStatus), array_values($byStatus)),
            'countries' => array_values(array_map(fn ($group) => [
                'country' => (string) ($group['dimensions']['clientCountryName'] ?? ''),
                'requests' => (int) ($group['count'] ?? 0),
            ], (array) ($node['countries'] ?? []))),
            'status_classes' => $classes,
            'series' => array_values($series),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function threats(array $node): array
    {
        $actions = [];
        $mitigated = 0;

        foreach ((array) ($node['actions'] ?? []) as $group) {
            $action = (string) ($group['dimensions']['action'] ?? 'unknown');
            $count = (int) ($group['count'] ?? 0);
            $actions[] = ['action' => $action, 'events' => $count];

            if (in_array($action, self::MITIGATED_ACTIONS, true)) {
                $mitigated += $count;
            }
        }

        return [
            'mitigated' => $mitigated,
            'events' => array_sum(array_column($actions, 'events')),
            'actions' => $actions,
            'countries' => array_values(array_map(fn ($group) => [
                'country' => (string) ($group['dimensions']['clientCountryName'] ?? ''),
                'events' => (int) ($group['count'] ?? 0),
            ], (array) ($node['countries'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{date: string, requests: int, cached_requests: int, bytes: int, cached_bytes: int, threats: int}>
     */
    private function daily(array $node): array
    {
        return array_values(array_map(fn ($group) => [
            'date' => (string) ($group['dimensions']['date'] ?? ''),
            'requests' => (int) ($group['sum']['requests'] ?? 0),
            'cached_requests' => (int) ($group['sum']['cachedRequests'] ?? 0),
            'bytes' => (int) ($group['sum']['bytes'] ?? 0),
            'cached_bytes' => (int) ($group['sum']['cachedBytes'] ?? 0),
            'threats' => (int) ($group['sum']['threats'] ?? 0),
        ], (array) ($node['days'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function missingPermission(array $report, string $connection, string $zone): array
    {
        return [
            ...$report,
            'permission_missing' => true,
            'error' => "The Cloudflare token can't read analytics. In Cloudflare → My Profile → API Tokens, edit the token of the connection “{$connection}” and add Zone → Analytics → Read for {$zone}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $setting
     */
    private function limit(array $setting, string $field, int $default): int
    {
        $value = $setting[$field] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function duration(int $seconds): string
    {
        return $seconds % 3600 === 0 ? (intdiv($seconds, 3600).' h') : (intdiv($seconds, 60).' min');
    }
}
