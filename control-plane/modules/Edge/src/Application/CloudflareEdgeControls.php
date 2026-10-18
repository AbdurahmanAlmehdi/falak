<?php

namespace Falak\Edge\Application;

use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Firewalls;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cloudflare edge controls: cache mode per domain (Falak-managed Cache Rules, merged with the zone's other rules),
 * purge of a site's names (after every deploy and on demand), Under Attack mode per zone, origin lock-down per server.
 */
final class CloudflareEdgeControls
{
    public const CACHE_MODES = ['standard', 'everything', 'bypass'];

    private const CACHE_PHASE = 'http_request_cache_settings';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly Firewalls $firewalls,
        private readonly AuditLog $audit,
    ) {}

    public function setCacheMode(Domain $domain, string $mode): void
    {
        if (! in_array($mode, self::CACHE_MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Pick standard, everything or bypass.']);
        }

        $zone = CloudflareZone::forHost($domain->organization_id, $domain->name)
            ?? throw ValidationException::withMessages(['mode' => 'This domain is not in a Cloudflare zone Falak manages.']);

        // Saved only once Cloudflare accepted the rules.
        $previous = $domain->cloudflare_cache;
        $domain->forceFill(['cloudflare_cache' => $mode === 'standard' ? null : $mode])->save();

        try {
            $this->syncCacheRules($zone);
        } catch (CloudflareError $e) {
            $domain->forceFill(['cloudflare_cache' => $previous])->save();

            throw $e;
        }
        $this->audit->record('edge.cloudflare_cache_mode', 'site', $domain->site_id, ['domain' => $domain->name, 'mode' => $mode], $domain->organization_id);
    }

    /**
     * The zone's Cache Rules: Falak's (description "falak:cache:<domain id>") are rebuilt from its domains; every other
     * rule stays as it is, in its place.
     */
    public function syncCacheRules(CloudflareZone $zone): void
    {
        $api = CloudflareApi::with($zone->credential->api_token);
        $entrypoint = $api->ruleset($zone->zone_id, self::CACHE_PHASE);
        $theirs = array_values(array_filter((array) ($entrypoint['rules'] ?? []), fn (array $rule) => ! str_starts_with((string) ($rule['description'] ?? ''), 'falak:cache:')));
        $ours = [];

        $domains = Domain::query()->where('organization_id', $zone->organization_id)->whereNotNull('cloudflare_cache')->orderBy('name')->get()
            ->filter(fn (Domain $d) => $zone->covers($d->name) && ! $d->isWildcard());

        foreach ($domains as $domain) {
            $hosts = implode(' ', array_map(fn (string $h) => '"'.$h.'"', $domain->hosts()));
            $ours[] = [
                'description' => 'falak:cache:'.$domain->id.' '.$domain->name,
                'expression' => "(http.host in {{$hosts}})",
                'action' => 'set_cache_settings',
                'action_parameters' => $domain->cloudflare_cache === 'bypass'
                    ? ['cache' => false]
                    // Everything, HTML included, for a day at the edge: Falak purges the site after every deploy.
                    : ['cache' => true, 'edge_ttl' => ['mode' => 'override_origin', 'default' => 86400], 'browser_ttl' => ['mode' => 'respect_origin']],
                'enabled' => true,
            ];
        }

        $rules = array_map(fn (array $rule) => array_intersect_key($rule, array_flip(['id', 'description', 'expression', 'action', 'action_parameters', 'enabled'])), [...$theirs, ...$ours]);
        $api->putRuleset($zone->zone_id, self::CACHE_PHASE, $rules);
    }

    /**
     * Purges every name of a site in managed zones (its domains, www hosts and compose public service domains).
     *
     * @return list<string> the purged hosts
     */
    public function purgeSite(string $siteId): array
    {
        $site = $this->sites->find($siteId);

        if ($site === null) {
            return [];
        }

        // Domains of every public service of a compose site are rows too; names not imported yet are added.
        $hosts = Domain::query()->where('site_id', $site->id)->get()->flatMap(fn (Domain $d) => $d->hosts())->all();
        array_push($hosts, ...CloudflareDns::unimportedComposeHosts($site));

        return $this->purge($site->organizationId, $hosts);
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     *
     * @throws CloudflareError after trying every zone, when a purge failed (the job retries)
     */
    public function purge(string $organizationId, array $hosts): array
    {
        $purged = [];
        $failure = null;
        $zones = CloudflareZone::query()->with('credential')->where('organization_id', $organizationId)->get();

        foreach ($zones as $zone) {
            $mine = array_values(array_unique(array_filter($hosts, fn (string $h) => ! str_starts_with($h, '*.') && $zone->covers($h))));

            foreach (array_chunk($mine, 30) as $chunk) {
                try {
                    CloudflareApi::with($zone->credential->api_token)->purgeHosts($zone->zone_id, $chunk);
                    array_push($purged, ...$chunk);
                } catch (CloudflareError $e) {
                    $failure = $e;
                    Log::warning('edge: cloudflare purge failed', ['zone' => $zone->name, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $purged;
    }

    /** Under Attack: a JavaScript challenge for every visitor of the zone; off restores the previous level. */
    public function underAttack(CloudflareZone $zone, bool $on): void
    {
        $api = CloudflareApi::with($zone->credential->api_token);

        try {
            if ($on) {
                $current = (string) $api->setting($zone->zone_id, 'security_level');
                $api->updateSetting($zone->zone_id, 'security_level', 'under_attack');
                $zone->forceFill(['security_level_before' => $current === 'under_attack' ? ($zone->security_level_before ?? 'medium') : $current])->save();
            } else {
                $api->updateSetting($zone->zone_id, 'security_level', $zone->security_level_before ?? 'medium');
                $zone->forceFill(['security_level_before' => null])->save();
            }
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['under_attack' => $e->getMessage()]);
        }

        $this->audit->record('edge.cloudflare_under_attack', 'dns_credential', $zone->dns_credential_id, ['zone' => $zone->name, 'on' => $on], $zone->organization_id);
    }

    /** Origin lock-down: null (open), closed (tunnel servers) or cloudflare (web ports for Cloudflare only). */
    public function lock(string $organizationId, string $serverId, ?string $mode): void
    {
        $serverId = strtolower($serverId);

        // The control plane's machine: web traffic arrives through the control plane's Docker-published edge (DNAT),
        // which the host firewall's input rules never see; a lock would protect nothing.
        if ($mode !== null && $this->servers->find($serverId)?->controlPlaneHost) {
            throw ValidationException::withMessages(['mode' => 'Origin lock-down does not apply on the control-plane machine: its web ports belong to the control plane\'s edge, which the host firewall does not filter.']);
        }

        if ($mode === OriginLock::CLOSED && ! CloudflareTunnel::query()->where('server_id', $serverId)->where('organization_id', $organizationId)->where('status', CloudflareTunnel::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['mode' => 'Close the web ports only on a server that runs through a healthy Cloudflare Tunnel.']);
        }

        if ($mode === OriginLock::CLOUDFLARE && ! CloudflareZone::query()->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['mode' => 'Manage a Cloudflare zone first.']);
        }

        if ($mode === null) {
            OriginLock::query()->where('server_id', $serverId)->where('organization_id', $organizationId)->delete();
        } else {
            OriginLock::query()->updateOrCreate(['server_id' => $serverId], ['organization_id' => $organizationId, 'mode' => $mode]);
        }

        $this->firewalls->converge($serverId);
        $this->audit->record('edge.origin_lock', 'server', $serverId, ['mode' => $mode], $organizationId);
    }
}
