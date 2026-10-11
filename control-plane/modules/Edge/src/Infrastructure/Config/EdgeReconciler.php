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
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use RuntimeException;

/**
 * falak.yaml's `domains`, `redirects` and `headers` of app units (phase B: they go live with the unit's release).
 * Identity: a domain's host name, a redirect's `from`, a header's name. Only the site's own routes (not a compose
 * service's) are managed.
 */
final class EdgeReconciler implements Reconcilable, ValidatesConfig
{
    public const DOMAINS = 'units.*(app).domains';

    public const REDIRECTS = 'units.*(app).redirects';

    public const HEADERS = 'units.*(app).headers';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ManagedResources $managed,
    ) {}

    public function sections(): array
    {
        return [self::DOMAINS, self::REDIRECTS, self::HEADERS];
    }

    public function phase(): ApplyPhase
    {
        return ApplyPhase::WithRelease;
    }

    public function current(ConfigScope $scope): array
    {
        $units = [];

        foreach ($this->appSites($scope) as $unit => $site) {
            $body = ['type' => 'app'];
            $domains = [];

            foreach ($this->domains($site->id) as $name => $domain) {
                $entry = $this->domainShape($domain);
                unset($entry['primary']);
                $domains[] = array_keys($entry) === ['name', 'www', 'tls'] && $entry['www'] === 'none' && $entry['tls'] === 'auto' ? $name : $entry;
            }

            if ($domains !== []) {
                $body['domains'] = $domains;
            }

            $redirects = array_values(array_map(fn (Redirect $r) => ['from' => $r->from, 'to' => $r->to, 'status' => (int) $r->status], $this->redirects($site->id)));

            if ($redirects !== []) {
                $body['redirects'] = $redirects;
            }

            $headers = array_map(fn (Header $h) => $h->value, $this->headers($site->id));

            if ($headers !== []) {
                $body['headers'] = $headers;
            }

            $units[$unit] = $body;
        }

        return $units === [] ? [] : ['units' => $units];
    }

    public function diff(ConfigScope $scope, array $desired, array $current): ChangeSet
    {
        $changes = [];
        $units = array_unique([...array_keys((array) ($desired['units'] ?? [])), ...array_keys((array) ($current['units'] ?? []))]);

        foreach ($units as $unit) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $base = '/units/'.Pointer::escape($unit);
            $want = $desired['units'][$unit] ?? [];
            $have = $current['units'][$unit] ?? [];

            // Domains
            $wantDomains = self::normalizeDomains((array) ($want['domains'] ?? []));
            $haveDomains = self::normalizeDomains((array) ($have['domains'] ?? []));
            $models = $siteId !== null ? $this->domains($siteId) : [];

            foreach ($wantDomains as $host => $entry) {
                $path = "{$base}/domains/".Pointer::escape($host);

                if (! isset($haveDomains[$host])) {
                    $changes[] = new Change(ChangeOp::Create, $path, null, $entry, self::DOMAINS, $unit);

                    continue;
                }

                foreach (Diff::leaves($entry) as $field => $value) {
                    $old = Diff::at($haveDomains[$host], $field);
                    $changes[] = new Change(Diff::same($value, $old) ? ChangeOp::Noop : ($old === null ? ChangeOp::Create : ChangeOp::Update), $path.$field, $old, $value, self::DOMAINS, $unit);
                }
            }

            foreach ($haveDomains as $host => $entry) {
                if (isset($wantDomains[$host]) || ! isset($models[$host]) || ! $this->managed->managed('domain', $models[$host]->id)->any()) {
                    continue;
                }

                $op = $this->managed->createdByFile('domain', $models[$host]->id) ? ChangeOp::Delete : ChangeOp::Detach;
                $changes[] = new Change($op, "{$base}/domains/".Pointer::escape($host), $entry, null, self::DOMAINS, $unit);
            }

            // Redirects
            $wantRedirects = self::keyedRedirects((array) ($want['redirects'] ?? []));
            $haveRedirects = self::keyedRedirects((array) ($have['redirects'] ?? []));
            $redirectModels = $siteId !== null ? $this->redirects($siteId) : [];

            foreach ($wantRedirects as $from => $redirect) {
                $path = "{$base}/redirects/".Pointer::escape($from);

                if (! isset($haveRedirects[$from])) {
                    $changes[] = new Change(ChangeOp::Create, $path, null, $redirect, self::REDIRECTS, $unit);

                    continue;
                }

                foreach (['to', 'status'] as $field) {
                    $old = $haveRedirects[$from][$field];
                    $changes[] = new Change(Diff::same($redirect[$field], $old) ? ChangeOp::Noop : ChangeOp::Update, "{$path}/{$field}", $old, $redirect[$field], self::REDIRECTS, $unit);
                }
            }

            foreach ($haveRedirects as $from => $redirect) {
                $model = $redirectModels[$from] ?? null;

                if (isset($wantRedirects[$from]) || $model === null || ! $this->managed->managed('redirect', $model->id)->any()) {
                    continue;
                }

                $op = $this->managed->createdByFile('redirect', $model->id) ? ChangeOp::Delete : ChangeOp::Detach;
                $changes[] = new Change($op, "{$base}/redirects/".Pointer::escape($from), $redirect, null, self::REDIRECTS, $unit);
            }

            // Headers
            $wantHeaders = (array) ($want['headers'] ?? []);
            $haveHeaders = (array) ($have['headers'] ?? []);
            $headerModels = $siteId !== null ? $this->headers($siteId) : [];

            foreach ($wantHeaders as $name => $value) {
                $name = (string) $name;
                $old = $haveHeaders[$name] ?? null;
                $op = $old === null ? ChangeOp::Create : ($old === $value ? ChangeOp::Noop : ChangeOp::Update);
                $changes[] = new Change($op, "{$base}/headers/".Pointer::escape($name), $old, $value, self::HEADERS, $unit);
            }

            foreach ($haveHeaders as $name => $value) {
                $model = $headerModels[(string) $name] ?? null;

                if (array_key_exists($name, $wantHeaders) || $model === null || ! $this->managed->managed('header', $model->id)->any()) {
                    continue;
                }

                $op = $this->managed->createdByFile('header', $model->id) ? ChangeOp::Delete : ChangeOp::Detach;
                $changes[] = new Change($op, "{$base}/headers/".Pointer::escape((string) $name), $value, null, self::HEADERS, $unit);
            }
        }

        return new ChangeSet($changes);
    }

    public function apply(ConfigScope $scope, ChangeSet $changes, ApplyContext $ctx): void
    {
        $groups = [];

        foreach ($changes->changes as $change) {
            $tokens = Pointer::tokens($change->path);

            if (count($tokens) < 4 || $tokens[0] !== 'units') {
                continue;
            }

            $groups[$tokens[1]][$tokens[2]][$tokens[3]][] = $change;
        }

        foreach ($groups as $unit => $sections) {
            $unit = (string) $unit;
            $siteId = $scope->refId($unit);
            $site = $siteId !== null ? $this->sites->find($siteId) : null;

            if ($site === null) {
                if ($this->onlyRemovals($sections)) {
                    continue; // the unit's site is gone: so are its routes
                }

                throw new RuntimeException("The unit \"{$unit}\" has no site to route to.");
            }

            $this->applyDomains($scope, $ctx, $unit, $site, $sections['domains'] ?? []);
            $this->applyRedirects($scope, $ctx, $unit, $site, $sections['redirects'] ?? []);
            $this->applyHeaders($scope, $ctx, $unit, $site, $sections['headers'] ?? []);
        }
    }

    public function validate(ConfigScope $scope, array $resolved): array
    {
        $issues = [];
        $owners = [];
        $envSites = array_values(array_map(fn (array $s) => $s['ref_id'], array_filter($scope->services, fn (array $s) => $s['kind'] === 'site')));

        foreach ((array) ($resolved['units'] ?? []) as $unit => $body) {
            if (! is_array($body) || ($body['type'] ?? null) !== 'app') {
                continue;
            }

            $base = '/units/'.Pointer::escape((string) $unit).'/domains';
            $primaries = 0;

            foreach ((array) ($body['domains'] ?? []) as $i => $entry) {
                $entry = is_string($entry) ? ['name' => $entry] : (array) $entry;
                $name = (string) ($entry['name'] ?? '');
                $at = "{$base}/{$i}";

                if (($entry['primary'] ?? false) === true && ++$primaries === 2) {
                    $issues[] = ConfigIssue::error('value.conflict', "{$at}/primary", 'Only one domain can be primary.');
                }

                if (isset($owners[$name]) && $owners[$name] !== $unit) {
                    $issues[] = ConfigIssue::error('value.conflict', $at, "\"{$name}\" is also a domain of the unit \"{$owners[$name]}\".");
                }

                $owners[$name] = (string) $unit;

                if (($entry['www'] ?? 'none') !== 'none' && (str_starts_with($name, '*.') || str_starts_with($name, 'www.'))) {
                    $issues[] = ConfigIssue::error('value.conflict', "{$at}/www", 'A www redirect needs an apex name (not a wildcard or a www. name).');
                }

                if (isset($entry['dns_credential']) && ! DnsCredential::query()->where('organization_id', $scope->organizationId)->where('name', $entry['dns_credential'])->exists()) {
                    $issues[] = ConfigIssue::error('name.dns_credential', "{$at}/dns_credential", "No DNS credential named \"{$entry['dns_credential']}\".");
                }

                if (isset($entry['certificate']) && ! Certificate::query()->where('organization_id', $scope->organizationId)->where('name', $entry['certificate'])->exists()) {
                    $issues[] = ConfigIssue::error('name.certificate', "{$at}/certificate", "No certificate named \"{$entry['certificate']}\".");
                }

                $taken = Domain::query()->where('name', $name)->whereNotIn('site_id', $envSites ?: [''])->exists();

                if ($taken) {
                    $issues[] = ConfigIssue::error('value.conflict', $at, "\"{$name}\" is already a domain of a site outside this environment.");
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, array<string, list<Change>>>  $sections
     */
    private function onlyRemovals(array $sections): bool
    {
        foreach ($sections as $items) {
            foreach ($items as $changes) {
                foreach ($changes as $change) {
                    if (! in_array($change->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<Change>>  $items  host => changes
     */
    private function applyDomains(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, array $items): void
    {
        $primary = null;

        foreach ($items as $host => $changes) {
            $host = (string) $host;
            $model = $this->domains($site->id)[$host] ?? null;
            $first = $changes[0];

            if (count(Pointer::tokens($first->path)) === 4 && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($first->op === ChangeOp::Delete) {
                        app(RemoveDomain::class)($model);
                    }

                    $this->managed->forget('domain', $model->id);
                }

                continue;
            }

            $entry = $first->op === ChangeOp::Create && count(Pointer::tokens($first->path)) === 4 ? (array) $first->after : $this->entryOf($changes);
            $tls = TlsMode::from((string) ($entry['tls'] ?? 'auto'));
            $www = WwwRedirect::from((string) ($entry['www'] ?? 'none'));
            $certificate = isset($entry['certificate']) ? Certificate::query()->where('organization_id', $site->organizationId)->where('name', $entry['certificate'])->value('id') : null;
            $credential = isset($entry['dns_credential']) ? DnsCredential::query()->where('organization_id', $site->organizationId)->where('name', $entry['dns_credential'])->value('id') : null;
            $created = $model === null;
            $changed = array_filter($changes, fn (Change $c) => $c->op !== ChangeOp::Noop) !== [];

            if ($created) {
                $model = app(AddDomain::class)($site, $host, $tls, $www, $certificate, $credential);
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

            $this->managed->record($scope, $ctx, $unit, 'domain', $model->id, ['/domains/'.Pointer::escape($host)], $created ? true : null);
        }

        if ($primary !== null && ! $primary->refresh()->is_primary) {
            app(MakePrimaryDomain::class)($primary);
        }
    }

    /**
     * @param  array<string, list<Change>>  $items  from => changes
     */
    private function applyRedirects(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, array $items): void
    {
        $order = [];

        foreach ($items as $from => $changes) {
            $from = (string) $from;
            $model = $this->redirects($site->id)[$from] ?? null;
            $first = $changes[0];
            $whole = count(Pointer::tokens($first->path)) === 4;

            if ($whole && in_array($first->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($first->op === ChangeOp::Delete) {
                        app(DeleteSiteRule::class)($model, $site->organizationId);
                    }

                    $this->managed->forget('redirect', $model->id);
                }

                continue;
            }

            $wanted = $whole ? (array) $first->after : ['from' => $from];

            foreach ($changes as $change) {
                $tokens = Pointer::tokens($change->path);

                if (count($tokens) === 5) {
                    $wanted[$tokens[4]] = $change->after;
                }
            }

            $created = $model === null;
            $createdByFile = $created || $this->managed->createdByFile('redirect', $model->id);

            if ($created || $model->to !== $wanted['to'] || (int) $model->status !== (int) $wanted['status']) {
                if ($model !== null) {
                    app(DeleteSiteRule::class)($model, $site->organizationId);
                    $this->managed->forget('redirect', $model->id);
                }

                $model = app(AddRedirect::class)($site, $from, (string) $wanted['to'], (int) $wanted['status']);
            }

            $order[] = $model;
            $this->managed->record($scope, $ctx, $unit, 'redirect', $model->id, ['/redirects/'.Pointer::escape($from)], $createdByFile);
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
    private function applyHeaders(ConfigScope $scope, ApplyContext $ctx, string $unit, SiteData $site, array $items): void
    {
        foreach ($items as $name => $changes) {
            $name = (string) $name;
            $model = $this->headers($site->id)[$name] ?? null;
            $change = $changes[0];

            if (in_array($change->op, [ChangeOp::Delete, ChangeOp::Detach], true)) {
                if ($model !== null) {
                    if ($change->op === ChangeOp::Delete) {
                        app(DeleteSiteRule::class)($model, $site->organizationId);
                    }

                    $this->managed->forget('header', $model->id);
                }

                continue;
            }

            $created = $model === null;

            if ($created || $model->value !== $change->after) {
                $model = app(SaveHeader::class)($site, $name, (string) $change->after);
            }

            $this->managed->record($scope, $ctx, $unit, 'header', $model->id, ['/headers/'.Pointer::escape($name)], $created ? true : null);
        }
    }

    /**
     * A domain entry rebuilt from its per-field changes.
     *
     * @param  list<Change>  $changes
     * @return array<string, mixed>
     */
    private function entryOf(array $changes): array
    {
        $entry = [];

        foreach ($changes as $change) {
            $tokens = array_slice(Pointer::tokens($change->path), 4);

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
     * The site's own domains (not a compose service's), primary first.
     *
     * @return array<string, Domain>
     */
    private function domains(string $siteId): array
    {
        return Domain::query()->where('site_id', $siteId)->whereNull('compose_service')->orderByDesc('is_primary')->orderBy('name')->get()->keyBy('name')->all();
    }

    /**
     * @return array<string, Redirect>
     */
    private function redirects(string $siteId): array
    {
        return Redirect::query()->where('site_id', $siteId)->whereNull('compose_service')->orderBy('position')->orderBy('id')->get()->keyBy('from')->all();
    }

    /**
     * @return array<string, Header>
     */
    private function headers(string $siteId): array
    {
        return Header::query()->where('site_id', $siteId)->whereNull('compose_service')->orderBy('name')->get()->keyBy('name')->all();
    }

    /**
     * @return array<string, SiteData>
     */
    private function appSites(ConfigScope $scope): array
    {
        $out = [];

        foreach ($scope->services as $unit => $service) {
            if ($service['kind'] !== 'site') {
                continue;
            }

            $site = $this->sites->find($service['ref_id']);

            if ($site !== null && ! in_array($site->runtime, [SiteRuntime::Compose, SiteRuntime::Function], true)) {
                $out[(string) $unit] = $site;
            }
        }

        return $out;
    }
}
