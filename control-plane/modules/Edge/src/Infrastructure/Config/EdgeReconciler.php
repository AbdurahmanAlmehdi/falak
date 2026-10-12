<?php

namespace Falak\Edge\Infrastructure\Config;

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Diff;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;
use Falak\Config\Contracts\ValidatesConfig;
use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\AddRedirect;
use Falak\Edge\Application\Actions\DeleteSiteRule;
use Falak\Edge\Application\Actions\MakePrimaryDomain;
use Falak\Edge\Application\Actions\RemoveDomain;
use Falak\Edge\Application\Actions\SaveHeader;
use Falak\Edge\Application\Actions\UpdateDomain;
use Falak\Edge\Application\CloudflareEdgeControls;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Application\PathMounts;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\Mount;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * falak.yaml's `domains`, `redirects`, `headers` and `mounts` of units, and the `domains`, `redirects` and `headers` of
 * compose services (phase B: they go live with the unit's release). Identity: a domain's host name, a redirect's
 * `from`, a header's name, a mount's path. A unit's own routes are its site's (a compose unit's: its first public
 * service's, which `services.<first>` also names); another public service's are that service's rows
 * (`compose_service`). A function unit has domains only.
 */
final class EdgeReconciler implements Reconcilable, ValidatesConfig
{
    private ?ConfigScope $diffScope = null;

    public const DOMAINS = 'units.*(app|compose|function).domains';

    public const REDIRECTS = 'units.*(app|compose).redirects';

    public const HEADERS = 'units.*(app|compose).headers';

    public const MOUNTS = 'units.*(app|compose).mounts';

    public const SERVICE_DOMAINS = 'units.*(compose).services.*.domains';

    public const SERVICE_REDIRECTS = 'units.*(compose).services.*.redirects';

    public const SERVICE_HEADERS = 'units.*(compose).services.*.headers';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ManagedResources $managed,
        private readonly ProjectDirectory $projects,
        private readonly PathMounts $mounts,
    ) {}

    public function sections(): array
    {
        return [self::DOMAINS, self::REDIRECTS, self::HEADERS, self::MOUNTS, self::SERVICE_DOMAINS, self::SERVICE_REDIRECTS, self::SERVICE_HEADERS];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::WithRelease;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($this->unitSites($scope) as $unit => $site) {
            $type = self::typeOf($site);
            $body = ['type' => $type, ...$this->routeOf($site, null, $type === 'function')];

            if ($type !== 'function') {
                $mounts = [];

                foreach (Mount::query()->where('site_id', $site->id)->whereNull('compose_service')->orderBy('path_prefix')->get() as $mount) {
                    if (($function = $scope->unitOf($mount->function_site_id)) !== null) {
                        $mounts[] = ['path' => $mount->path_prefix, 'function' => $function, ...($mount->strip_prefix ? [] : ['strip_prefix' => false])];
                    }
                }

                if ($mounts !== []) {
                    $body['mounts'] = $mounts;
                }
            }

            foreach (self::services($site) as $service) {
                if (($route = $this->routeOf($site, $service)) !== []) {
                    $body['services'][$service] = $route;
                }
            }

            $units[$unit] = $body;
        }

        return $units === [] ? [] : ['units' => $units];
    }

    /**
     * One route's domains, redirects and headers in the file's shape (a function: domains only).
     *
     * @return array<string, mixed>
     */
    private function routeOf(SiteData $site, ?string $service, bool $domainsOnly = false): array
    {
        $body = [];
        $domains = [];

        foreach ($this->domains($site, $service) as $name => $domain) {
            $entry = $this->domainShape($domain);
            unset($entry['primary']);
            $domains[] = array_keys($entry) === ['name', 'www', 'tls'] && $entry['www'] === 'none' && $entry['tls'] === 'auto' ? $name : $entry;
        }

        if ($domains !== []) {
            $body['domains'] = $domains;
        }

        if ($domainsOnly) {
            return $body;
        }

        $redirects = array_values(array_map(fn (Redirect $r) => ['from' => $r->from, 'to' => $r->to, 'status' => (int) $r->status], $this->redirects($site, $service)));

        if ($redirects !== []) {
            $body['redirects'] = $redirects;
        }

        $headers = array_map(fn (Header $h) => $h->value, $this->headers($site, $service));

        if ($headers !== []) {
            $body['headers'] = $headers;
        }

        return $body;
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $this->diffScope = $scope; // read by removal() during this diff only
        $changes = [];
        $units = array_unique([...array_keys((array) ($desired['units'] ?? [])), ...array_keys((array) ($current['units'] ?? []))]);

        foreach ($units as $unit) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;
            $base = '/units/'.Pointer::escape($unit);
            $want = self::ownRoute($desired['units'][$unit] ?? [], $site);
            $have = $current['units'][$unit] ?? [];

            array_push($changes, ...$this->diffRoute($unit, $base, $site, null, $want, $have, [self::DOMAINS, self::REDIRECTS, self::HEADERS]));
            array_push($changes, ...$this->diffMounts($unit, $base, $site, (array) ($want['mounts'] ?? []), (array) ($have['mounts'] ?? [])));

            $services = array_unique([...array_keys((array) ($want['services'] ?? [])), ...array_keys((array) ($have['services'] ?? []))]);

            foreach ($services as $service) {
                $service = (string) $service;
                array_push($changes, ...$this->diffRoute($unit, "{$base}/services/".Pointer::escape($service), $site, $service,
                    (array) ($want['services'][$service] ?? []), (array) ($have['services'][$service] ?? []), [self::SERVICE_DOMAINS, self::SERVICE_REDIRECTS, self::SERVICE_HEADERS]));
            }
        }

        return new ChangeSet($changes);
    }

    /**
     * A compose unit's first public service is its own route: what the file sets under `services.<first>` is the
     * unit's (`domains`, `redirects`, `headers` at the unit's level).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function ownRoute(array $body, ?SiteData $site): array
    {
        $primary = $site !== null ? ComposeServiceDomains::primaryService($site) : self::firstPublic($body);

        if ($primary === null || ! isset($body['services'][$primary])) {
            return $body;
        }

        foreach (['domains', 'redirects', 'headers'] as $field) {
            if (isset($body['services'][$primary][$field])) {
                $body[$field] = $field === 'headers' ? [...(array) ($body[$field] ?? []), ...$body['services'][$primary][$field]] : [...(array) ($body[$field] ?? []), ...$body['services'][$primary][$field]];
            }
        }

        unset($body['services'][$primary]);

        if ($body['services'] === []) {
            unset($body['services']);
        }

        return $body;
    }

    /**
     * The first service of a new compose unit with a port (it becomes the first public service).
     *
     * @param  array<string, mixed>  $body
     */
    private static function firstPublic(array $body): ?string
    {
        foreach ((array) ($body['services'] ?? []) as $service => $settings) {
            if (isset($settings['port'])) {
                return (string) $service;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $want
     * @param  array<string, mixed>  $have
     * @param  array{0: string, 1: string, 2: string}  $sections  domains, redirects, headers
     * @return list<Change>
     */
    private function diffRoute(string $unit, string $base, ?SiteData $site, ?string $service, array $want, array $have, array $sections): array
    {
        $changes = [];
        [$domainsSection, $redirectsSection, $headersSection] = $sections;

        // Domains
        $wantDomains = self::normalizeDomains((array) ($want['domains'] ?? []));
        $haveDomains = self::normalizeDomains((array) ($have['domains'] ?? []));
        $models = $site !== null ? $this->domains($site, $service) : [];

        foreach ($wantDomains as $host => $entry) {
            $path = "{$base}/domains/".Pointer::escape($host);

            if (! isset($haveDomains[$host])) {
                $changes[] = new Change(ChangeOp::Create, $path, null, $entry, $domainsSection, $unit);

                continue;
            }

            foreach (Diff::leaves($entry) as $field => $value) {
                $old = Diff::at($haveDomains[$host], $field);
                $changes[] = new Change(Diff::same($value, $old) ? ChangeOp::Noop : ($old === null ? ChangeOp::Create : ChangeOp::Update), $path.$field, $old, $value, $domainsSection, $unit);
            }
        }

        foreach ($haveDomains as $host => $entry) {
            if (isset($wantDomains[$host]) || ! isset($models[$host]) || ! $this->managed->managed('domain', $models[$host]->id)->any()) {
                continue;
            }

            $op = $this->removal('domain', $models[$host]->id, $unit);
            $changes[] = new Change($op, "{$base}/domains/".Pointer::escape($host), $entry, null, $domainsSection, $unit);
        }

        // Redirects
        $wantRedirects = self::keyedRedirects((array) ($want['redirects'] ?? []));
        $haveRedirects = self::keyedRedirects((array) ($have['redirects'] ?? []));
        $redirectModels = $site !== null ? $this->redirects($site, $service) : [];

        foreach ($wantRedirects as $from => $redirect) {
            $path = "{$base}/redirects/".Pointer::escape($from);

            if (! isset($haveRedirects[$from])) {
                $changes[] = new Change(ChangeOp::Create, $path, null, $redirect, $redirectsSection, $unit);

                continue;
            }

            foreach (['to', 'status'] as $field) {
                $old = $haveRedirects[$from][$field];
                $changes[] = new Change(Diff::same($redirect[$field], $old) ? ChangeOp::Noop : ChangeOp::Update, "{$path}/{$field}", $old, $redirect[$field], $redirectsSection, $unit);
            }
        }

        foreach ($haveRedirects as $from => $redirect) {
            $model = $redirectModels[$from] ?? null;

            if (isset($wantRedirects[$from]) || $model === null || ! $this->managed->managed('redirect', $model->id)->any()) {
                continue;
            }

            $op = $this->removal('redirect', $model->id, $unit);
            $changes[] = new Change($op, "{$base}/redirects/".Pointer::escape($from), $redirect, null, $redirectsSection, $unit);
        }

        // Headers
        $wantHeaders = (array) ($want['headers'] ?? []);
        $haveHeaders = (array) ($have['headers'] ?? []);
        $headerModels = $site !== null ? $this->headers($site, $service) : [];

        foreach ($wantHeaders as $name => $value) {
            $name = (string) $name;
            $old = $haveHeaders[$name] ?? null;
            $op = $old === null ? ChangeOp::Create : ($old === $value ? ChangeOp::Noop : ChangeOp::Update);
            $changes[] = new Change($op, "{$base}/headers/".Pointer::escape($name), $old, $value, $headersSection, $unit);
        }

        foreach ($haveHeaders as $name => $value) {
            $model = $headerModels[(string) $name] ?? null;

            if (array_key_exists($name, $wantHeaders) || $model === null || ! $this->managed->managed('header', $model->id)->any()) {
                continue;
            }

            $op = $this->removal('header', $model->id, $unit);
            $changes[] = new Change($op, "{$base}/headers/".Pointer::escape((string) $name), $value, null, $headersSection, $unit);
        }

        return $changes;
    }

    /**
     * Mounts by path: `function` and `strip_prefix` (default true) per path.
     *
     * @param  list<mixed>  $want
     * @param  list<mixed>  $have
     * @return list<Change>
     */
    private function diffMounts(string $unit, string $base, ?SiteData $site, array $want, array $have): array
    {
        $changes = [];
        $wanted = self::keyedMounts($want);
        $current = self::keyedMounts($have);
        $models = $site !== null ? $this->mountModels($site->id) : [];

        foreach ($wanted as $path => $mount) {
            $at = "{$base}/mounts/".Pointer::escape($path);

            if (! isset($current[$path])) {
                $changes[] = new Change(ChangeOp::Create, $at, null, $mount, self::MOUNTS, $unit);

                continue;
            }

            foreach (['function', 'strip_prefix'] as $field) {
                $changes[] = new Change(Diff::same($mount[$field], $current[$path][$field]) ? ChangeOp::Noop : ChangeOp::Update, "{$at}/{$field}", $current[$path][$field], $mount[$field], self::MOUNTS, $unit);
            }
        }

        foreach ($current as $path => $mount) {
            $model = $models[$path] ?? null;

            if (isset($wanted[$path]) || $model === null || ! $this->managed->managed('mount', $model->id)->any()) {
                continue;
            }

            $op = $this->removal('mount', $model->id, $unit);
            $changes[] = new Change($op, "{$base}/mounts/".Pointer::escape($path), $mount, null, self::MOUNTS, $unit);
        }

        return $changes;
    }

    /**
     * @param  list<mixed>  $mounts
     * @return array<string, array{path: string, function: string, strip_prefix: bool}>
     */
    private static function keyedMounts(array $mounts): array
    {
        $out = [];

        foreach ($mounts as $mount) {
            $mount = (array) $mount;
            $out[(string) $mount['path']] = ['path' => (string) $mount['path'], 'function' => (string) $mount['function'], 'strip_prefix' => (bool) ($mount['strip_prefix'] ?? true)];
        }

        return $out;
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $groups = [];

        foreach ($changes->changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) < 4 || $tokens[0] !== 'units') {
                continue;
            }

            if ($tokens[2] === 'services') {
                if (count($tokens) >= 6) {
                    $groups[$tokens[1]][$tokens[3]][$tokens[4]][$tokens[5]][] = $change;
                }

                continue;
            }

            // '' is the unit's own route.
            $groups[$tokens[1]][''][$tokens[2]][$tokens[3]][] = $change;
        }

        foreach ($groups as $unit => $routes) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;

            if ($site === null) {
                if ($this->onlyRemovals($routes)) {
                    continue; // the unit's site is gone: so are its routes
                }

                throw new RuntimeException("The unit \"{$unit}\" has no site to route to.");
            }

            foreach ($routes as $route => $sections) {
                $service = $route === '' ? null : (string) $route;
                $base = '/'.($service === null ? '' : 'services/'.Pointer::escape($service).'/');
                $depth = $service === null ? 4 : 6;

                $this->applyDomains($scope, $ctx, $unit, $site, $service, $base, $depth, $sections['domains'] ?? []);
                $this->applyRedirects($scope, $ctx, $unit, $site, $service, $base, $depth, $sections['redirects'] ?? []);
                $this->applyHeaders($scope, $ctx, $unit, $site, $service, $base, $sections['headers'] ?? []);

                if ($service === null) {
                    $this->applyMounts($scope, $ctx, $unit, $site, $sections['mounts'] ?? []);
                }
            }
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];
        $owners = [];
        $envSites = array_values(array_map(fn (array $s) => $s['ref_id'], array_filter($scope->services, fn (array $s) => $s['kind'] === 'site')));
        $allowed = $this->allowedCredentials($scope);

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (! is_array($body) || ! in_array($body['type'] ?? null, ['app', 'compose', 'function'], true)) {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit);
            $lists = ["{$base}/domains" => (array) ($body['domains'] ?? [])];

            foreach ((array) ($body['services'] ?? []) as $service => $settings) {
                $lists["{$base}/services/".Pointer::escape((string) $service).'/domains'] = (array) ($settings['domains'] ?? []);
            }

            foreach ((array) ($body['mounts'] ?? []) as $i => $mount) {
                $function = (string) ($mount['function'] ?? '');

                if (($resolved['units'][$function]['type'] ?? null) !== 'function') {
                    $issues[] = ConfigIssue::error('name.unit', "{$base}/mounts/{$i}/function", "No function unit named \"{$function}\".");
                }
            }

            foreach ($lists as $listBase => $domains) {
                $primaries = 0;

                foreach ($domains as $i => $entry) {
                    $entry = is_string($entry) ? ['name' => $entry] : (array) $entry;
                    $name = (string) ($entry['name'] ?? '');
                    $at = "{$listBase}/{$i}";

                    if (($entry['primary'] ?? false) === true && ++$primaries === 2) {
                        $issues[] = ConfigIssue::error('value.conflict', "{$at}/primary", 'Only one domain can be primary.');
                    }

                    if (isset($owners[$name]) && $owners[$name] !== $listBase) {
                        $issues[] = ConfigIssue::error('value.conflict', $at, "\"{$name}\" is also a domain of ".str_replace('/', ' ', trim(substr($owners[$name], 7, -8), '/')).'.');
                    }

                    $owners[$name] = $listBase;

                    if (($entry['www'] ?? 'none') !== 'none' && (str_starts_with($name, '*.') || str_starts_with($name, 'www.'))) {
                        $issues[] = ConfigIssue::error('value.conflict', "{$at}/www", 'A www redirect needs an apex name (not a wildcard or a www. name).');
                    }

                    if (isset($entry['dns_credential']) && ! in_array((string) $entry['dns_credential'], $allowed['dns'], true)) {
                        $issues[] = ConfigIssue::error('name.dns_credential', "{$at}/dns_credential", DnsCredential::query()->where('organization_id', $scope->organizationId)->where('name', $entry['dns_credential'])->exists()
                            ? "The DNS credential \"{$entry['dns_credential']}\" isn't used by this project yet: an admin must allow it in the project's Config settings."
                            : "No DNS credential named \"{$entry['dns_credential']}\".");
                    }

                    if (isset($entry['certificate']) && ! in_array((string) $entry['certificate'], $allowed['certificates'], true)) {
                        $issues[] = ConfigIssue::error('name.certificate', "{$at}/certificate", Certificate::query()->where('organization_id', $scope->organizationId)->where('name', $entry['certificate'])->exists()
                            ? "The certificate \"{$entry['certificate']}\" isn't used by this project yet: an admin must allow it in the project's Config settings."
                            : "No certificate named \"{$entry['certificate']}\".");
                    }

                    $taken = Domain::query()->where('name', $name)->whereNotIn('site_id', $envSites ?: [''])->first(['organization_id']);

                    if ($taken !== null) {
                        // Another organization's domain: say nothing about it.
                        $issues[] = ConfigIssue::error('value.conflict', $at, $taken->organization_id === $scope->organizationId
                            ? "\"{$name}\" is already a domain of a site outside this environment."
                            : 'This domain is not available.');
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, array<string, array<string, list<Change>>>>  $routes
     */
    private function onlyRemovals(array $routes): bool
    {
        foreach ($routes as $sections) {
            foreach ($sections as $items) {
                foreach ($items as $changes) {
                    foreach ($changes as $change) {
                        if (! in_array($change->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<Change>>  $items  host => changes
     */
    private function applyDomains(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, ?string $service, string $base, int $depth, array $items): void
    {
        $primary = null;
        $allowed = null;

        foreach ($items as $host => $changes) {
            $host = (string) $host;
            $model = $this->domains($site, $service)[$host] ?? null;
            $first = $changes[0];

            if (count(Pointer::tokens($first->path)) === $depth && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($first->op === ChangeOp::Delete && $this->deletable($scope, 'domain', $model->id, $unit)) {
                        app(RemoveDomain::class)($model);
                        $this->managed->forget('domain', $model->id, $scope->environmentId);
                    } else {
                        $this->managed->detach($scope, $ctx, $unit, 'domain', $model->id, "Domain {$host} ({$unit})",
                            route('edge.domains.destroy', ['site' => $site->id, 'domain' => $model->id], false), $site->id);
                    }
                }

                continue;
            }

            $entry = $first->op === ChangeOp::Create && count(Pointer::tokens($first->path)) === $depth ? (array) $first->after : $this->entryOf($changes, $depth);
            $tls = TlsMode::from((string) ($entry['tls'] ?? 'auto'));
            $www = WwwRedirect::from((string) ($entry['www'] ?? 'none'));
            $allowed ??= $this->allowedCredentials($scope);

            if ((isset($entry['certificate']) && ! in_array((string) $entry['certificate'], $allowed['certificates'], true))
                || (isset($entry['dns_credential']) && ! in_array((string) $entry['dns_credential'], $allowed['dns'], true))) {
                throw new RuntimeException("The domain {$host} uses a certificate or DNS credential this project's file may not use.");
            }

            $certificate = isset($entry['certificate']) ? Certificate::query()->where('organization_id', $site->organizationId)->where('name', $entry['certificate'])->value('id') : null;
            $credential = isset($entry['dns_credential']) ? DnsCredential::query()->where('organization_id', $site->organizationId)->where('name', $entry['dns_credential'])->value('id') : null;
            $created = $model === null;
            $changed = array_filter($changes, fn (Change $c) => $c->op !== ChangeOp::Noop) !== [];

            if ($created) {
                $model = app(AddDomain::class)($site, $host, $tls, $www, $certificate, $credential, $service);
            } elseif ($changed && ($model->tls_mode !== $tls || $model->www_redirect !== $www || $model->certificate_id !== $certificate || $model->dns_credential_id !== $credential)) {
                app(UpdateDomain::class)($model, $tls, $www, $certificate, $credential);
            }

            if (array_key_exists('proxied', (array) ($entry['cloudflare'] ?? [])) && $model->cloudflare_proxied !== $entry['cloudflare']['proxied']) {
                $model->forceFill(['cloudflare_proxied' => (bool) $entry['cloudflare']['proxied']])->save();
                SyncCloudflareDns::domain($model->id);
            }

            if (isset($entry['cloudflare']['cache']) && ($model->cloudflare_cache ?? 'standard') !== $entry['cloudflare']['cache'] && CloudflareZone::forHost($site->organizationId, $model->name) !== null) {
                app(CloudflareEdgeControls::class)->setCacheMode($model, (string) $entry['cloudflare']['cache']);
            }

            if (($entry['primary'] ?? false) === true) {
                $primary = $model;
            }

            $this->managed->record($scope, $ctx, $unit, 'domain', $model->id, [$base.'domains/'.Pointer::escape($host)], $created ? true : null);
        }

        if ($primary !== null && ! $primary->refresh()->is_primary) {
            app(MakePrimaryDomain::class)($primary);
        }
    }

    /**
     * @param  array<string, list<Change>>  $items  from => changes
     */
    private function applyRedirects(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, ?string $service, string $base, int $depth, array $items): void
    {
        $order = [];

        foreach ($items as $from => $changes) {
            $from = (string) $from;
            $model = $this->redirects($site, $service)[$from] ?? null;
            $first = $changes[0];
            $whole = count(Pointer::tokens($first->path)) === $depth;

            if ($whole && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($first->op === ChangeOp::Delete && $this->deletable($scope, 'redirect', $model->id, $unit)) {
                        app(DeleteSiteRule::class)($model, $site->organizationId);
                        $this->managed->forget('redirect', $model->id, $scope->environmentId);
                    } else {
                        $this->managed->detach($scope, $ctx, $unit, 'redirect', $model->id, "Redirect {$from} ({$unit})",
                            route('edge.redirects.destroy', ['site' => $site->id, 'redirect' => $model->id], false), $site->id);
                    }
                }

                continue;
            }

            $wanted = $whole ? (array) $first->after : ['from' => $from];

            foreach ($changes as $change) {
                $tokens = Pointer::tokens($change->path);

                if (count($tokens) === $depth + 1) {
                    $wanted[$tokens[$depth]] = $change->after;
                }
            }

            $created = $model === null;
            $createdByFile = $created || $this->managed->createdByFile('redirect', $model->id, $scope->environmentId);

            if ($created || $model->to !== $wanted['to'] || (int) $model->status !== (int) $wanted['status']) {
                if ($model !== null) {
                    app(DeleteSiteRule::class)($model, $site->organizationId);
                    $this->managed->forget('redirect', $model->id, $scope->environmentId);
                }

                $model = app(AddRedirect::class)($site, $from, (string) $wanted['to'], (int) $wanted['status'], $service);
            }

            $order[] = $model;
            $this->managed->record($scope, $ctx, $unit, 'redirect', $model->id, [$base.'redirects/'.Pointer::escape($from)], $createdByFile);
        }

        // The file's order is the matching order: its redirects come first, in the file's order.
        $moved = false;

        foreach ($order as $position => $redirect) {
            if ((int) $redirect->position !== $position) {
                $redirect->forceFill(['position' => $position])->save();
                $moved = true;
            }
        }

        if ($moved) {
            app(EdgeChanges::class)->siteChanged($site->id);
        }
    }

    /**
     * @param  array<string, list<Change>>  $items  name => changes
     */
    private function applyHeaders(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, ?string $service, string $base, array $items): void
    {
        foreach ($items as $name => $changes) {
            $name = (string) $name;
            $model = $this->headers($site, $service)[$name] ?? null;
            $change = $changes[0];

            if (in_array($change->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($change->op === ChangeOp::Delete && $this->deletable($scope, 'header', $model->id, $unit)) {
                        app(DeleteSiteRule::class)($model, $site->organizationId);
                        $this->managed->forget('header', $model->id, $scope->environmentId);
                    } else {
                        $this->managed->detach($scope, $ctx, $unit, 'header', $model->id, "Header {$name} ({$unit})",
                            route('edge.headers.destroy', ['site' => $site->id, 'header' => $model->id], false), $site->id);
                    }
                }

                continue;
            }

            $created = $model === null;

            if ($created || $model->value !== $change->after) {
                $model = app(SaveHeader::class)($site, $name, (string) $change->after, $service);
            }

            $this->managed->record($scope, $ctx, $unit, 'header', $model->id, [$base.'headers/'.Pointer::escape($name)], $created ? true : null);
        }
    }

    /**
     * @param  array<string, list<Change>>  $items  path => changes
     */
    private function applyMounts(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, array $items): void
    {
        foreach ($items as $path => $changes) {
            $path = (string) $path;
            $model = $this->mountModels($site->id)[$path] ?? null;
            $first = $changes[0];
            $whole = count(Pointer::tokens($first->path)) === 4;

            if ($whole && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($first->op === ChangeOp::Delete && $this->deletable($scope, 'mount', $model->id, $unit)) {
                        $this->mounts->delete($model);
                        $this->managed->forget('mount', $model->id, $scope->environmentId);
                    } else {
                        $this->managed->detach($scope, $ctx, $unit, 'mount', $model->id, "Mount {$path} ({$unit})",
                            route('edge.mounts.destroy', ['site' => $site->id, 'mount' => $model->id], false), $site->id);
                    }
                }

                continue;
            }

            $wanted = $whole ? (array) $first->after : ['path' => $path, 'function' => $scope->unitOf((string) $model?->function_site_id), 'strip_prefix' => (bool) $model?->strip_prefix];

            foreach ($changes as $change) {
                $tokens = Pointer::tokens($change->path);

                if (count($tokens) === 5) {
                    $wanted[$tokens[4]] = $change->after;
                }
            }

            $functionId = $scope->refId((string) $wanted['function']);
            $function = $functionId !== null ? $this->sites->find($functionId) : null;

            if ($function === null || ! $function->runtime->isFunction()) {
                throw new RuntimeException("The mount {$path} of {$unit} names \"{$wanted['function']}\", which is not a function of this environment.");
            }

            $created = $model === null;
            $createdByFile = $created || $this->managed->createdByFile('mount', $model->id, $scope->environmentId);

            if ($created || $model->function_site_id !== $function->id || $model->strip_prefix !== (bool) $wanted['strip_prefix']) {
                if ($model !== null) {
                    $this->mounts->delete($model);
                    $this->managed->forget('mount', $model->id, $scope->environmentId);
                }

                $model = $this->mounts->create($function, $site->id, $path, (bool) $wanted['strip_prefix']);
            }

            $this->managed->record($scope, $ctx, $unit, 'mount', $model->id, ['/mounts/'.Pointer::escape($path)], $createdByFile);
        }
    }

    /**
     * A domain entry rebuilt from its per-field changes.
     *
     * @param  list<Change>  $changes
     * @return array<string, mixed>
     */
    private function entryOf(array $changes, int $depth): array
    {
        $entry = [];

        foreach ($changes as $change) {
            $tokens = array_slice(Pointer::tokens($change->path), $depth);

            if ($tokens === ['cloudflare', 'proxied'] || $tokens === ['cloudflare', 'cache']) {
                $entry['cloudflare'][$tokens[1]] = $change->after;
            } elseif (count($tokens) === 1) {
                $entry[$tokens[0]] = $change->after;
            }
        }

        return $entry;
    }

    /**
     * Domains keyed by host with the fields the file manages (primary, www and tls always; credentials with their tls
     * mode; Cloudflare options when given).
     *
     * @param  list<mixed>  $domains
     * @return array<string, array<string, mixed>>
     */
    public static function normalizeDomains(array $domains): array
    {
        $out = [];
        $explicit = false;

        foreach ($domains as $entry) {
            $entry = is_string($entry) ? ['name' => $entry] : (array) $entry;
            $explicit = $explicit || ($entry['primary'] ?? false) === true;
        }

        foreach (array_values($domains) as $i => $entry) {
            $entry = is_string($entry) ? ['name' => $entry] : (array) $entry;
            $tls = (string) ($entry['tls'] ?? 'auto');
            $normalized = [
                'primary' => $explicit ? ($entry['primary'] ?? false) === true : $i === 0,
                'www' => (string) ($entry['www'] ?? 'none'),
                'tls' => $tls,
            ];

            if ($tls === 'dns') {
                $normalized['dns_credential'] = $entry['dns_credential'] ?? null;
            }

            if ($tls === 'custom') {
                $normalized['certificate'] = $entry['certificate'] ?? null;
            }

            if (isset($entry['cloudflare']) && is_array($entry['cloudflare']) && $entry['cloudflare'] !== []) {
                $normalized['cloudflare'] = $entry['cloudflare'];
            }

            $out[(string) $entry['name']] = $normalized;
        }

        return $out;
    }

    /**
     * Today's domain in the file's shape (credentials by name; Cloudflare options only when set).
     *
     * @return array<string, mixed>
     */
    private function domainShape(Domain $domain): array
    {
        $entry = ['name' => $domain->name, 'primary' => $domain->is_primary, 'www' => $domain->www_redirect->value, 'tls' => $domain->tls_mode->value];

        if ($domain->tls_mode === TlsMode::Dns && $domain->dns_credential_id !== null) {
            $entry['dns_credential'] = DnsCredential::query()->whereKey($domain->dns_credential_id)->value('name');
        }

        if ($domain->tls_mode === TlsMode::Custom && $domain->certificate_id !== null) {
            $entry['certificate'] = Certificate::query()->whereKey($domain->certificate_id)->value('name');
        }

        $cloudflare = array_filter(['proxied' => $domain->cloudflare_proxied, 'cache' => $domain->cloudflare_cache], fn ($v) => $v !== null);

        if ($cloudflare !== []) {
            $entry['cloudflare'] = $cloudflare;
        }

        return $entry;
    }

    /**
     * @param  list<mixed>  $redirects
     * @return array<string, array{from: string, to: string, status: int}>
     */
    private static function keyedRedirects(array $redirects): array
    {
        $out = [];

        foreach ($redirects as $redirect) {
            $redirect = (array) $redirect;
            $out[(string) $redirect['from']] = ['from' => (string) $redirect['from'], 'to' => (string) $redirect['to'], 'status' => (int) ($redirect['status'] ?? 301)];
        }

        return $out;
    }

    /**
     * A route's domains (the site's own, or a compose service's), primary first.
     *
     * @return array<string, Domain>
     */
    private function domains(SiteData $site, ?string $service = null): array
    {
        return ComposeServiceDomains::scope(Domain::query()->where('site_id', $site->id), $site, $service)->orderByDesc('is_primary')->orderBy('name')->get()->keyBy('name')->all();
    }

    /**
     * @return array<string, Redirect>
     */
    private function redirects(SiteData $site, ?string $service = null): array
    {
        return self::route(Redirect::query()->where('site_id', $site->id), $service)->orderBy('position')->orderBy('id')->get()->keyBy('from')->all();
    }

    /**
     * @return array<string, Header>
     */
    private function headers(SiteData $site, ?string $service = null): array
    {
        return self::route(Header::query()->where('site_id', $site->id), $service)->orderBy('name')->get()->keyBy('name')->all();
    }

    /**
     * Rules of exactly one route: the site's own (compose_service null) or one service's.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function route(Builder $query, ?string $service): Builder
    {
        return $service === null ? $query->whereNull('compose_service') : $query->where('compose_service', $service);
    }

    /**
     * The site's own mounts (on every route) by path.
     *
     * @return array<string, Mount>
     */
    private function mountModels(string $siteId): array
    {
        return Mount::query()->where('site_id', $siteId)->whereNull('compose_service')->get()->keyBy('path_prefix')->all();
    }

    /**
     * Sites of the environment's units.
     *
     * @return array<string, SiteData>
     */
    private function unitSites(ConfigScope $scope): array
    {
        $out = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] === 'site' && ($site = $this->sites->find($service['ref_id'])) !== null) {
                $out[(string) $unit] = $site;
            }
        }

        return $out;
    }

    private static function typeOf(SiteData $site): string
    {
        return match ($site->runtime) {
            SiteRuntime::Compose => 'compose',
            SiteRuntime::Function => 'function',
            default => 'app',
        };
    }

    /**
     * A compose site's public services other than the first (whose routes are the site's own).
     *
     * @return list<string>
     */
    private static function services(SiteData $site): array
    {
        return array_values(array_map(fn ($public) => $public->service, array_slice(ComposeServiceDomains::publicServices($site), 1)));
    }

    /**
     * Certificates and DNS credentials (by name) the project's file may use: those the project's domains (any
     * environment) already use, certificates uploaded for one of its sites, and the names an admin allowed on the
     * project's config source ($scope->credentials). Never the rest of the organization's.
     *
     * @return array{certificates: list<string>, dns: list<string>}
     */
    private function allowedCredentials(ConfigScope $scope): array
    {
        $siteIds = array_values(array_map(fn (array $s) => $s['ref_id'], array_filter($scope->services, fn (array $s) => $s['kind'] === 'site')));

        foreach ($this->projects->environments($scope->projectId) as $environment) {
            foreach ($this->projects->servicesIn($environment->id) as $service) {
                if ($service->kind === ServiceKind::Site) {
                    $siteIds[] = $service->refId;
                }
            }
        }

        $domains = Domain::query()->whereIn('site_id', $siteIds ?: [''])->get(['certificate_id', 'dns_credential_id']);
        $certificates = Certificate::query()->where('organization_id', $scope->organizationId)
            ->where(fn ($q) => $q->whereIn('id', $domains->pluck('certificate_id')->filter()->all() ?: [''])
                ->orWhereIn('site_id', $siteIds ?: [''])
                ->orWhereIn('name', $scope->credentials ?: ['']))
            ->pluck('name')->all();
        $dns = DnsCredential::query()->where('organization_id', $scope->organizationId)
            ->where(fn ($q) => $q->whereIn('id', $domains->pluck('dns_credential_id')->filter()->all() ?: [''])
                ->orWhereIn('name', $scope->credentials ?: ['']))
            ->pluck('name')->all();

        return ['certificates' => array_values(array_map('strval', $certificates)), 'dns' => array_values(array_map('strval', $dns))];
    }

    /**
     * **[decision]** Items of a unit (domains, redirects, headers, mounts) the environment's file created are deleted
     * when the file drops them while their unit is still the file's; otherwise (adopted, or the whole unit left the
     * file and its site was detached) they are detached and listed as unmanaged.
     */
    private function removal(string $type, string $id, string $unit): ChangeOp
    {
        return $this->diffScope !== null && $this->deletable($this->diffScope, $type, $id, $unit) ? ChangeOp::Delete : ChangeOp::Detach;
    }

    private function deletable(ConfigScope $scope, string $type, string $id, string $unit): bool
    {
        $siteId = $scope->refId($unit);

        return $this->managed->createdByFile($type, $id, $scope->environmentId)
            && $siteId !== null && $this->managed->managedIn($scope->environmentId, 'site', $siteId);
    }
}
