<?php

namespace Falak\Edge\Infrastructure;

use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Contracts\CloudflareRanges;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\InstallStatus;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\CertificateInstall;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\Mount;
use Falak\Edge\Domain\Models\PathRateLimit;
use Falak\Edge\Domain\Models\PreviewDomain;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Edge\Domain\Models\ServiceSetting;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Edge\Domain\Models\Upstream;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Network\Contracts\Tailnet;
use Falak\Processes\Contracts\OctaneRouting;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Telemetry\Contracts\RequestIdLinking;
use Illuminate\Support\Collection;

/**
 * Compiles the full edge.caddy.apply payload for one server from every site routed through it:
 *
 *  - sites targeting the server ("direct"; "backend" when a load balancer fronts the site — then served
 *    as plain HTTP on :80 for the LB, which enforces TLS, IP and auth rules),
 *  - sites load-balanced by the server ("lb": reverse_proxy to the targets).
 *
 * Domains of one site are grouped by TLS configuration; the first group keeps the site's stable route id
 * (see {@see routeId()}), further groups get "<routeId>-<n>". Output is deterministic.
 *
 * Laravel Octane: a PHP site with Octane on is served by `reverse_proxy 127.0.0.1:<octane port>` with `root` set
 * (the agent serves existing non-PHP files under the document root directly and proxies everything else) —
 * but only once Processes verified Octane answers on that port ({@see OctaneRouting}); until then (never
 * deployed / placeholder release, still starting, broken) the site keeps being served by FrankenPHP /
 * PHP-FPM directly. Domains, test domain, TLS, redirects, headers, auth and LB backends are unchanged.
 */
final class RouteCompiler
{
    /** The agent feature that installs Falak's Caddy build for DNS-01 certificates (and falls back to HTTP-01). */
    public const CADDY_DNS = 'edge.caddy_dns';

    /** @var Collection<int, CloudflareZone> managed Cloudflare zones of the server being compiled (see compile()) */
    private Collection $zones;

    /** The preview domain when the server being compiled is its edge with a managed wildcard certificate */
    private ?PreviewDomain $wildcard = null;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly OctaneRouting $octane,
        private readonly ?string $acmeEmail = null,
        private readonly ?string $acmeCa = null,
        private readonly string $testDomainTls = 'acme',
    ) {}

    /**
     * edge.caddy.apply gained trusted_proxies and tls.http_challenge_only in 0.3.0; agents reject unknown fields, so
     * older releases never get them (they apply the rest and pick them up after an agent update). Development builds
     * and unknown versions do.
     */
    private static function agentKnowsCloudflare(string $serverId): bool
    {
        $version = app(AgentUpgrades::class)->versionsFor([$serverId])[$serverId]->version ?? null;

        // Release versions only ("v0.2.8", "0.3.0-rc.1" counts as 0.3.0); dev / CI builds are current.
        if ($version === null || preg_match('/^v?(\d+\.\d+\.\d+)(?:-[0-9A-Za-z.]+)?$/', $version, $m) !== 1) {
            return true;
        }

        return version_compare($m[1], '0.3.0', '>=');
    }

    /**
     * ACME for a host: in a managed Cloudflare zone, HTTP-01 only (TLS-ALPN-01 cannot pass through the proxy, and
     * each failed attempt counts against Let's Encrypt's limit of 5 per hour).
     *
     * @return array{mode: string, http_challenge_only?: bool}
     */
    private function acme(string $host): array
    {
        return $this->zones->contains(fn (CloudflareZone $zone) => $zone->covers($host))
            ? ['mode' => 'acme', 'http_challenge_only' => true]
            : ['mode' => 'acme'];
    }

    public static function routeId(string $siteId): string
    {
        return strtolower($siteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(string $serverId): array
    {
        $entries = [];
        $organizationId = $this->servers->find($serverId)?->organizationId;
        $this->zones = $organizationId !== null && self::agentKnowsCloudflare($serverId)
            ? CloudflareZone::query()->where('organization_id', $organizationId)->get()
            : collect();

        $preview = PreviewDomain::current();
        $this->wildcard = $preview !== null && $preview->managed() && $preview->server_id === strtolower($serverId) ? $preview : null;

        foreach ($this->routedSites($serverId) as [$site, $role, $balancer]) {
            array_push($entries, ...$this->siteEntries($site, $serverId, $role, $balancer));
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        // A FrankenPHP edge has no rate_limit module (its binary is FrankenPHP's own build): its routes never carry
        // rate limits, whichever site they serve (a site that moved onto it, a load balancer of one).
        if ($this->servers->find($serverId)?->phpRuntime === 'frankenphp') {
            $entries = array_map(function (array $entry) {
                unset($entry['rate_limits']);

                return $entry;
            }, $entries);
        }

        // Falak's Caddy build (rate_limit and caddy-dns modules), with rate limits or DNS-01 certificates: the agent
        // installs it when the edge lacks a module the config uses. Stock Caddy has no DNS provider, and one DNS-01
        // domain made it refuse every route of the server (v0.25). Agents before edge.caddy_dns refuse a build sent
        // without rate limits, so they only get it with them.
        $rateLimited = collect($entries)->contains(fn (array $entry) => isset($entry['rate_limits']));
        $dns = $this->wildcard !== null || collect($entries)->contains(fn (array $entry) => isset($entry['tls']['dns']));
        $caddyBuild = $rateLimited || ($dns && (app(AgentDirectory::class)->forServer($serverId)?->supports(self::CADDY_DNS) ?? false))
            ? app(EdgeCaddyBuilds::class)->forArch($this->servers->find($serverId)?->arch)
            : null;

        // Behind Cloudflare the connection comes from Cloudflare: trust its ranges for the visitor's IP
        // (CF-Connecting-IP), so logs, IP allow / deny lists and rate limits see the visitor.
        $proxied = $this->zones->isNotEmpty();
        // Through a tunnel, cloudflared connects to Caddy from the server itself.
        $local = $proxied && CloudflareTunnel::query()->where('server_id', strtolower($serverId))->exists() ? ['127.0.0.1/32', '::1/128'] : [];

        return array_filter([
            'acme_email' => $this->acmeEmail ?: null,
            'acme_ca' => $this->acmeCa ?: null,
            'trusted_proxies' => $proxied ? [...CloudflareRanges::RANGES, ...$local] : null,
            // The preview edge: one DNS-01 certificate for every preview host it serves.
            'wildcard_certificates' => $this->wildcard !== null ? [[
                'subject' => '*.'.$this->wildcard->domain,
                'dns' => ['provider' => 'cloudflare', 'api_token' => $this->wildcard->dnsCredential->api_token],
            ]] : null,
            'caddy_build' => $caddyBuild,
        ])
            // Organizations that turned on request ID linking: requests without a valid X-Request-Id get one, passed
            // upstream and recorded in the access log (feature edge.request_id, stripped for older agents;
            // docs/plans/network/http.md). Off, the payload is what it always was.
            + ($organizationId !== null && app(RequestIdLinking::class)->enabled($organizationId) ? ['request_id' => true] : [])
            + ['sites' => $entries];
    }

    /**
     * Server ids whose compiled config depends on the site (its targets and its load balancer).
     *
     * @return list<string>
     */
    public function serversForSite(string $siteId): array
    {
        $site = $this->sites->find($siteId);
        $ids = $site ? $site->serverIds() : [];

        $lb = LoadBalancer::query()->where('site_id', $siteId)->value('server_id');

        if (is_string($lb)) {
            $ids[] = $lb;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{0: SiteData, 1: 'direct'|'backend'|'lb', 2: ?LoadBalancer}>
     */
    private function routedSites(string $serverId): array
    {
        $routed = [];
        $balancers = LoadBalancer::query()->get()->keyBy('site_id');

        foreach (LoadBalancer::query()->where('server_id', $serverId)->get() as $balancer) {
            $site = $this->sites->find($balancer->site_id);

            if ($site && $site->organizationId === $balancer->organization_id) {
                $routed[$site->id] = [$site, 'lb', $balancer];
            }
        }

        foreach ($this->sites->forServer($serverId) as $site) {
            if (! isset($routed[$site->id])) {
                $balancer = $balancers->get($site->id);
                $routed[$site->id] = [$site, $balancer ? 'backend' : 'direct', $balancer];
            }
        }

        ksort($routed);

        return array_values($routed);
    }

    /**
     * @param  'direct'|'backend'|'lb'  $role
     * @return list<array<string, mixed>>
     */
    private function siteEntries(SiteData $site, string $serverId, string $role, ?LoadBalancer $balancer): array
    {
        $base = $role === 'lb' ? $this->balancerHandler($site, $balancer) : $this->siteHandler($site, $serverId);

        if ($base === null) {
            return [];
        }

        // A compose site's own route serves its primary public service: rules for that service apply to it as well.
        $primary = ComposeServiceDomains::primaryService($site);
        $groups = $this->domainGroups($site, $serverId, $role);
        $rules = $this->routeRules($site, $serverId, $role, $primary);
        $routeId = self::routeId($site->id);
        $entries = $role === 'direct' ? $this->composeServiceEntries($site, $serverId, $routeId) : [];
        $n = 0;

        foreach ($groups as $group) {
            $entry = ['id' => $n === 0 ? $routeId : "{$routeId}-{$n}", 'domains' => $group['domains']];

            if ($group['redirect_domains'] !== []) {
                $entry['redirect_domains'] = $group['redirect_domains'];
            }

            $entry['tls'] = $group['tls'];
            $entries[] = $entry + $base + $rules;
            $n++;
        }

        return $entries;
    }

    /**
     * Rules of one route: redirects, auth, headers and IP lists ({@see rules()}), the per-site access log and the
     * function mounts. Backends behind a load balancer only see the balancer's requests; the balancer logs them with
     * the real client.
     *
     * @param  'direct'|'backend'|'lb'  $role
     * @param  ?string  $service  compose service the route serves (null: a site without services)
     * @return array<string, mixed>
     */
    private function routeRules(SiteData $site, string $serverId, string $role, ?string $service): array
    {
        $rules = $this->rules($site->id, $site->organizationId, $role, $service);

        if ($role !== 'backend') {
            // Per-site HTTP access log (shipped to Loki as the site's kind=access records).
            $rules['access_log'] = $site->slug;

            if (($mounts = $this->mounts($site, $serverId, $service)) !== []) {
                $rules['mounts'] = $mounts;
            }
        }

        return $rules;
    }

    /**
     * Paths of the site served by functions (edge.caddy.apply `mounts`, longest prefix first): the local function
     * gateway when the function runs on this server, else the function's own domain over HTTPS. A compose site's
     * mounts may be limited to one service's routes.
     *
     * @return list<array<string, mixed>>
     */
    private function mounts(SiteData $site, string $serverId, ?string $service = null): array
    {
        $out = [];
        $mounts = ComposeServiceDomains::ruleScope(Mount::query()->where('site_id', $site->id), $service)->get()
            // A service-specific mount wins over a site-wide one on the same path.
            ->sortBy(fn (Mount $m) => $m->compose_service === null ? 1 : 0)->unique('path_prefix')
            ->sortByDesc(fn (Mount $m) => strlen($m->path_prefix));

        foreach ($mounts as $mount) {
            $function = app(SiteDirectory::class)->find($mount->function_site_id);

            if ($function === null || ! $function->runtime->isFunction()) {
                continue;
            }

            $headers = ['X-Falak-Function' => $function->slug, 'X-Falak-Client-IP' => '{http.vars.client_ip}'];

            if ($function->target($serverId)?->status === TargetStatus::Ready) {
                $out[] = ['path_prefix' => $mount->path_prefix, 'strip_prefix' => $mount->strip_prefix, 'dial' => (string) config('edge.function_gateway', '127.0.0.1:7070'), 'request_headers' => $headers];

                continue;
            }

            // A concrete name served over TLS (a wildcard cannot be dialled, a TLS-off one has no HTTPS).
            $domain = Domain::query()->where('site_id', $function->id)
                ->where('name', 'not like', '*.%')
                ->where('tls_mode', '!=', TlsMode::Off->value)
                ->orderByDesc('is_primary')->orderBy('name')->value('name');

            if ($domain === null) {
                continue; // no way to reach it from this server
            }

            $out[] = [
                'path_prefix' => $mount->path_prefix,
                'strip_prefix' => $mount->strip_prefix,
                'dial' => "{$domain}:443",
                'tls_server_name' => $domain,
                'request_headers' => ['Host' => $domain],
            ];
        }

        return $out;
    }

    /**
     * Docker Compose: every public service after the first gets its own routes — its domains (edge_domains rows for
     * the service, grouped by TLS like a site's, www redirects included) and <service>-<slug>.<test domain> — proxied
     * to 127.0.0.1:<host port>, with the rules of that service. (The first service is the site's own route: site
     * domains + <slug> test domain → app port.) A domain still only kept in `public_services` (not imported yet) is
     * routed as before.
     *
     * @return list<array<string, mixed>>
     */
    private function composeServiceEntries(SiteData $site, string $serverId, string $routeId): array
    {
        $entries = [];
        $legacyTls = fn (string $host) => $this->acme($host);

        foreach (ComposeServiceDomains::publicServices($site) as $i => $public) {
            if ($public->hostPort === null) {
                continue;
            }

            $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($public->service)), '-') ?: 'svc';
            $handler = ['kind' => 'reverse_proxy', 'upstreams' => [['dial' => "127.0.0.1:{$public->hostPort}"]]];
            $legacy = $public->domain !== null && ! Domain::query()->where('name', $public->domain)->exists() ? $public->domain : null;

            if ($i === 0) {
                if ($legacy !== null) {
                    $entries[] = ['id' => "{$routeId}-svc-{$label}", 'domains' => [$legacy], 'tls' => $legacyTls($legacy)] + $handler + $this->routeRules($site, $serverId, 'direct', $public->service);
                }

                continue;
            }

            $rules = $this->routeRules($site, $serverId, 'direct', $public->service);
            $groups = $this->domainGroups($site, $serverId, 'direct', $public->service, $public->testDomain);

            if ($legacy !== null) {
                $tls = $legacyTls($legacy);
                $same = array_search($tls, array_column($groups, 'tls'), true);
                $same !== false
                    ? array_unshift($groups[$same]['domains'], $legacy)
                    : array_unshift($groups, ['domains' => [$legacy], 'redirect_domains' => [], 'tls' => $tls]);
            }

            foreach (array_values($groups) as $n => $group) {
                $entry = ['id' => $n === 0 ? "{$routeId}-svc-{$label}" : "{$routeId}-svc-{$label}-{$n}", 'domains' => $group['domains']];

                if ($group['redirect_domains'] !== []) {
                    $entry['redirect_domains'] = $group['redirect_domains'];
                }

                $entry['tls'] = $group['tls'];
                $entries[] = $entry + $handler + $rules;
            }
        }

        return $entries;
    }

    /**
     * Serving block for the site's runtime on one of its targets.
     *
     * @return array<string, mixed>|null
     */
    private function siteHandler(SiteData $site, string $serverId): ?array
    {
        $proxy = function (?string $dial) use ($site): ?array {
            if ($dial === null) {
                return null;
            }

            return array_filter([
                'kind' => 'reverse_proxy',
                'upstreams' => [['dial' => $dial]],
                // Compose services have container healthchecks (`up --wait`); Caddy's active check only accepts 2xx
                // and would take apps that redirect `/` to a login page out of rotation.
                'health_uri' => $site->runtime === SiteRuntime::Compose ? null : $this->path($site->healthCheckPath),
            ], fn ($v) => $v !== null);
        };

        $local = $site->appPort ? "127.0.0.1:{$site->appPort}" : null;

        if ($site->runtime->isPhp() && ($octane = $this->octaneHandler($site, $serverId)) !== null) {
            return $octane;
        }

        return match ($site->runtime) {
            SiteRuntime::FrankenPhp => ['kind' => 'frankenphp', 'root' => $site->documentRoot()],
            SiteRuntime::PhpFpm => $site->fpmSocket() ? ['kind' => 'php_fpm', 'root' => $site->documentRoot(), 'php_fpm_socket' => $site->fpmSocket()] : null,
            SiteRuntime::Static => ['kind' => 'static', 'root' => $site->documentRoot()],
            SiteRuntime::Node, SiteRuntime::Bun, SiteRuntime::Deno => $proxy($local),
            SiteRuntime::Docker, SiteRuntime::Compose => $proxy(
                Upstream::query()->where('site_id', $site->id)->where('server_id', $serverId)->value('upstream') ?? $local,
            ),
            // The server's function gateway starts the function's instances on demand; no active health check, which
            // would keep it from scaling to zero.
            SiteRuntime::Function => [
                'kind' => 'reverse_proxy',
                'upstreams' => [['dial' => (string) config('edge.function_gateway', '127.0.0.1:7070')]],
                // The client IP Caddy resolved (Cloudflare's trusted proxies included), for the gateway's IP allowlist.
                'request_headers' => ['X-Falak-Function' => $site->slug, 'X-Falak-Client-IP' => '{http.vars.client_ip}'],
            ],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function octaneHandler(SiteData $site, string $serverId): ?array
    {
        if (! $site->framework->isLaravel() || ! $site->laravel->servesOctane()) {
            return null;
        }

        $port = $this->octane->listeningPort($site->id, $serverId);

        if ($port === null || $port !== $site->laravel->octanePort) {
            return null;
        }

        return [
            'kind' => 'reverse_proxy',
            'root' => $site->documentRoot(),
            'upstreams' => [['dial' => "127.0.0.1:{$port}"]],
            // Deploys restart Octane (new release): the edge holds requests until it listens again instead of failing them.
            'try_duration_s' => max(1, (int) config('edge.octane_try_duration_seconds', 30)),
        ];
    }

    /**
     * Ids of the sites a compiled payload reverse-proxies to Octane (PHP site routes with a root are Octane routes).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function octaneSites(array $payload): array
    {
        $ids = [];

        foreach ((array) ($payload['sites'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? null) === 'reverse_proxy' && isset($entry['root'], $entry['try_duration_s'])) {
                $ids[] = substr((string) $entry['id'], 0, 26);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function balancerHandler(SiteData $site, ?LoadBalancer $balancer): ?array
    {
        if ($balancer === null) {
            return null;
        }

        $upstreams = [];

        foreach ($site->targets as $target) {
            $server = $this->servers->find($target->serverId);
            $ip = $server?->privateIpv4 ?: $server?->ipv4;

            if ($ip === null) {
                continue;
            }

            $dial = (str_contains($ip, ':') ? "[{$ip}]" : $ip).':'.$balancer->backend_port;

            // The schema has no weights: an upstream listed N times gets N shares of the rotation.
            for ($i = 0; $i < $balancer->weightFor($target->serverId); $i++) {
                $upstreams[] = ['dial' => $dial];
            }
        }

        if ($upstreams === []) {
            return null;
        }

        return array_filter([
            'kind' => 'reverse_proxy',
            'upstreams' => $upstreams,
            'lb_policy' => $balancer->policy->value,
            'health_uri' => $this->path($balancer->health_uri),
        ], fn ($v) => $v !== null);
    }

    /**
     * Domains of one route grouped by TLS configuration: the site's own (its primary compose service's), or those of
     * another public service of a compose site ($service, with its own test domain).
     *
     * @param  'direct'|'backend'|'lb'  $role
     * @return list<array{domains: list<string>, redirect_domains: list<string>, tls: array<string, mixed>}>
     */
    private function domainGroups(SiteData $site, string $serverId, string $role, ?string $service = null, ?string $serviceTestDomain = null): array
    {
        $domains = ComposeServiceDomains::scope(Domain::query()->with('dnsCredential')->where('site_id', $site->id), $site, $service)
            ->where('organization_id', $site->organizationId)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();
        $testDomain = $service === null ? $site->testDomain : $serviceTestDomain;

        $installed = CertificateInstall::query()
            ->where('server_id', $serverId)
            ->where('status', InstallStatus::Installed)
            ->pluck('certificate_id')
            ->all();

        $groups = [];
        $seen = [];

        $add = function (string $key, array $tls, string $host, ?string $redirect) use (&$groups, &$seen) {
            if (isset($seen[$host]) || ($redirect !== null && isset($seen[$redirect]))) {
                return;
            }

            $groups[$key] ??= ['domains' => [], 'redirect_domains' => [], 'tls' => $tls];
            $groups[$key]['domains'][] = $host;
            $seen[$host] = true;

            if ($redirect !== null) {
                $groups[$key]['redirect_domains'][] = $redirect;
                $seen[$redirect] = true;
            }
        };

        foreach ($domains as $domain) {
            $tls = $role === 'backend' ? ['mode' => 'off'] : $this->tls($domain, $installed);

            if ($tls === null) {
                continue;
            }

            $add(json_encode($tls, JSON_THROW_ON_ERROR), $tls, $domain->servedHost(), $domain->redirectHost());
        }

        if ($testDomain !== null) {
            $tls = ['mode' => $role === 'backend' ? 'off' : ($this->testDomainTls === 'internal' ? 'internal' : 'acme')];
            $add(json_encode($tls, JSON_THROW_ON_ERROR), $tls, strtolower($testDomain), null);
        }

        return array_values($groups);
    }

    /**
     * @param  list<string>  $installedCertificates  certificate ids installed on the server
     * @return array<string, mixed>|null null when the domain cannot be served on this server yet
     */
    private function tls(Domain $domain, array $installedCertificates): ?array
    {
        if ($domain->tls_mode === TlsMode::Auto && $this->wildcard?->covers($domain->name)) {
            return ['mode' => 'wildcard'];
        }

        return match ($domain->tls_mode) {
            TlsMode::Auto => $domain->isWildcard() ? null : $this->acme($domain->name),
            TlsMode::Internal => ['mode' => 'internal'],
            TlsMode::Off => ['mode' => 'off'],
            TlsMode::Custom => $domain->certificate_id !== null && in_array($domain->certificate_id, $installedCertificates, true)
                ? ['mode' => 'custom', 'cert_name' => $this->certName($domain->certificate_id)]
                : null,
            TlsMode::Dns => $domain->dnsCredential
                ? ['mode' => 'acme', 'dns' => ['provider' => $domain->dnsCredential->provider, 'api_token' => $domain->dnsCredential->api_token]]
                : null,
        };
    }

    private function certName(string $certificateId): string
    {
        return (string) Certificate::query()->whereKey($certificateId)->value('name');
    }

    /**
     * Redirects, headers, auth and site settings. For a compose service's route ($service): the site-wide rows plus
     * that service's (a service's header wins over the site's of the same name), the service's allow list instead of
     * the site's when it has one, and both deny lists. A tailnet-only site adds the tailnet to its allow list.
     *
     * @param  'direct'|'backend'|'lb'  $role
     * @return array<string, mixed>
     */
    private function rules(string $siteId, string $organizationId, string $role, ?string $service = null): array
    {
        $rules = [];
        $settings = SiteSetting::for($siteId);
        $serviceSettings = $service !== null ? ServiceSetting::for($siteId, $service) : null;
        // Behind a load balancer the LB enforces redirects, auth and IP rules (backends only see the LB's IP).
        $edge = $role !== 'backend';
        $scoped = fn ($query) => ComposeServiceDomains::ruleScope($query->where('site_id', $siteId), $service);

        $headers = $scoped(Header::query())->orderByRaw('compose_service is not null')->orderBy('name')->pluck('value', 'name')->all();
        ksort($headers);

        if ($headers !== []) {
            $rules['headers'] = $headers;
        }

        if ($edge) {
            $auth = $scoped(SecurityRule::query())->orderByRaw('path is not null')->orderBy('path')->orderBy('username')->get()
                ->map(fn (SecurityRule $rule) => array_filter([
                    'username' => $rule->username,
                    'password_hash' => $rule->password_hash,
                    'path' => $rule->path,
                ], fn ($v) => $v !== null))
                ->values()
                ->all();

            if ($auth !== []) {
                $rules['basic_auth'] = $auth;
            }

            $redirects = $scoped(Redirect::query())->orderBy('position')->orderBy('id')->get()
                ->map(fn (Redirect $redirect) => ['from' => $redirect->from, 'to' => $redirect->to, 'status' => $redirect->status])
                ->values()
                ->all();

            if ($redirects !== []) {
                $rules['redirects'] = $redirects;
            }

            $deny = array_values(array_unique([...$settings->deny_ips, ...($serviceSettings->deny_ips ?? [])]));
            $allow = array_values($serviceSettings !== null && $serviceSettings->allow_ips !== [] ? $serviceSettings->allow_ips : $settings->allow_ips);

            // Tailscale only (docs/plans/TAILSCALE.md): tailnet devices, the site's own entries, and the control plane
            // (its health and uptime checks come over the public path).
            if ($settings->tailnet_only) {
                $allow = array_values(array_unique([...Tailnet::RANGES, ...$allow, ...app(Tailnet::class)->controlPlaneAddresses($organizationId)]));
            }

            if ($deny !== []) {
                $rules['deny_ips'] = $deny;
            }

            if ($allow !== []) {
                $rules['allow_ips'] = $allow;
            }

            // Path rate limits (edge.rate_limit): only sites with rules get the field, so every other route renders
            // byte for byte as before.
            $limits = PathRateLimit::query()->where('site_id', $siteId)->orderBy('path')->get()
                ->map(fn (PathRateLimit $limit) => ['path' => $limit->path, 'events' => $limit->events, 'window_s' => $limit->window_s])
                ->values()
                ->all();

            if ($limits !== []) {
                $rules['rate_limits'] = $limits;
            }
        }

        if ($settings->max_body_bytes) {
            $rules['max_body_bytes'] = $settings->max_body_bytes;
        }

        if (! $settings->encode) {
            $rules['encode'] = false;
        }

        return $rules;
    }

    private function path(?string $path): ?string
    {
        return $path !== null && str_starts_with($path, '/') ? $path : null;
    }
}
