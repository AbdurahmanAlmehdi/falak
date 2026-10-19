<?php

namespace Falak\Edge\Application\Listeners;

use Falak\Edge\Application\CertificateInstaller;
use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Application\PathMounts;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\Mount;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Edge\Domain\Models\ServiceSetting;
use Falak\Edge\Domain\Models\SiteSetting;
use Falak\Edge\Domain\Models\Upstream;
use Falak\Edge\Events\DomainRemoved;
use Falak\Processes\Events\OctaneRoutingChanged;
use Falak\Sites\Contracts\Data\ComposeConfig;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Events\ComposeServiceExtracted;
use Falak\Sites\Events\ComposeServicesUnpublished;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Re-applies the edge of every affected server when a site changes.
 */
final class ReactToSiteChanges implements ShouldQueue
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly EdgeRoutes $routes,
        private readonly CertificateInstaller $certificates,
        private readonly PathMounts $mounts,
        private readonly ComposeServiceDomains $composeDomains,
    ) {}

    public function created(SiteCreated $event): void
    {
        $this->composeDomains->import($event->siteId); // compose public services may come with domains
        $this->changes->siteChanged($event->siteId, $event->serverIds);
        SyncCloudflareDns::site($event->siteId);
    }

    public function updated(SiteUpdated $event): void
    {
        $this->composeDomains->import($event->siteId); // a domain chosen in Settings → Compose
        $this->changes->siteChanged($event->siteId, $event->serverIds);
        SyncCloudflareDns::site($event->siteId); // a no-op for names already in place
        $this->mounts->functionChanged($event->siteId);
    }

    /**
     * A public compose service now runs as its own Falak site: its domains and the rules scoped to it move to that site
     * (as the site's own route), so its URLs keep working. A service moved to a Falak database has no edge state.
     */
    public function extracted(ComposeServiceExtracted $event): void
    {
        if ($event->kind !== 'site') {
            return;
        }

        DB::transaction(fn () => $this->handOver($event->siteId, $event->service, $event->refId, $event->wasPrimary));

        // The stack's next first service gets its own rows back (and the read model follows).
        $this->composeDomains->import($event->siteId);

        $this->changes->siteChanged($event->siteId);
        $this->changes->siteChanged($event->refId);
        SyncCloudflareDns::site($event->siteId);
        SyncCloudflareDns::site($event->refId);
    }

    /**
     * A compose service became its own site ($to): it keeps what applied to its route. Its domains and its own rules
     * move; site-wide basic auth and headers are copied (they stay for the stack's other services; the service's own
     * basic auth replaces the site's, its own header wins by name); IP lists become the new site's (the service's allow
     * list, else the stack's; both deny lists); body limit and compression as the stack's. When it was the first
     * service, the stack's own domains were its domains and the stack's site-wide redirects and paths applied to it:
     * those domains move and those rules are copied.
     */
    private function handOver(string $stack, string $service, string $to, bool $wasPrimary): void
    {
        $own = fn (string $model) => $model::query()->where('site_id', $stack)->where('compose_service', $service);
        $siteWide = fn (string $model) => $model::query()->where('site_id', $stack)->whereNull('compose_service');
        // The stack's own rows are the service's unless Edge already handed them to the next first service.
        $ownRows = $wasPrimary && in_array(ComposeServiceDomains::recordedPrimary($stack), [null, $service], true);

        $hasPrimary = Domain::query()->where('site_id', $to)->where('is_primary', true)->exists();
        $domains = Domain::query()->where('site_id', $stack)
            ->where(fn ($q) => $ownRows ? $q->where('compose_service', $service)->orWhereNull('compose_service') : $q->where('compose_service', $service))
            ->orderByDesc('is_primary')->orderBy('created_at')->get();

        foreach ($domains as $domain) {
            $domain->forceFill(['site_id' => $to, 'compose_service' => null, 'is_primary' => $domain->is_primary && ! $hasPrimary])->save();
            $hasPrimary = $hasPrimary || $domain->is_primary;
        }

        $hadAuth = $own(SecurityRule::class)->exists();

        foreach ([Redirect::class, SecurityRule::class, Header::class, Mount::class] as $model) {
            $own($model)->update(['site_id' => $to, 'compose_service' => null]);
        }

        $copy = function (Model $row, array $taken, string $key) use ($to): void {
            if (! in_array($row->getAttribute($key), $taken, true)) {
                $row->replicate()->forceFill(['site_id' => $to, 'compose_service' => null])->save();
            }
        };

        if (! $hadAuth) {
            $siteWide(SecurityRule::class)->get()->each(fn (SecurityRule $rule) => $copy($rule, [], 'username'));
        }

        $headers = Header::query()->where('site_id', $to)->pluck('name')->all();
        $siteWide(Header::class)->get()->each(fn (Header $header) => $copy($header, $headers, 'name'));

        if ($wasPrimary) {
            $from = Redirect::query()->where('site_id', $to)->pluck('from')->all();
            $siteWide(Redirect::class)->orderBy('position')->get()->each(fn (Redirect $redirect) => $copy($redirect, $from, 'from'));
            $paths = Mount::query()->where('site_id', $to)->pluck('path_prefix')->all();
            $siteWide(Mount::class)->get()->each(fn (Mount $mount) => $copy($mount, $paths, 'path_prefix'));
        }

        $stackSettings = SiteSetting::for($stack);
        $serviceSettings = ServiceSetting::query()->where('site_id', $stack)->where('service', $service)->first();
        $settings = SiteSetting::for($to);
        $settings->forceFill([
            'allow_ips' => array_values($serviceSettings !== null && $serviceSettings->allow_ips !== [] ? $serviceSettings->allow_ips : $stackSettings->allow_ips),
            'deny_ips' => array_values(array_unique([...$settings->deny_ips, ...$stackSettings->deny_ips, ...($serviceSettings->deny_ips ?? [])])),
            'max_body_bytes' => $settings->max_body_bytes ?? $stackSettings->max_body_bytes,
            'encode' => $stackSettings->encode,
            'tailnet_only' => $stackSettings->tailnet_only,
        ])->save();
        $serviceSettings?->delete();
    }

    /**
     * Compose services that are no longer public lose their domains and the rules scoped to them. A service split into
     * its own site is skipped: {@see extracted()} moves its domains there (the two events may run in either order).
     */
    public function unpublished(ComposeServicesUnpublished $event): void
    {
        $compose = app(SiteDirectory::class)->find($event->siteId)?->compose;
        $services = array_values(array_filter($event->services, fn (string $service) => $compose?->mode($service) !== ComposeConfig::MODE_SITE));
        if ($services === []) {
            return;
        }

        foreach (Domain::query()->where('site_id', $event->siteId)->whereIn('compose_service', $services)->get() as $domain) {
            $domain->delete();
            DomainRemoved::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name);
        }
        foreach ([Redirect::class, SecurityRule::class, Header::class, Mount::class] as $model) {
            $model::query()->where('site_id', $event->siteId)->whereIn('compose_service', $services)->delete();
        }
        ServiceSetting::query()->where('site_id', $event->siteId)->whereIn('service', $services)->delete();

        $this->changes->siteChanged($event->siteId);
    }

    /** Octane became reachable (proxy to it) or is being switched off (serve directly again) on one server. */
    public function octaneRoutingChanged(OctaneRoutingChanged $event): void
    {
        $this->routes->schedule($event->serverId);
    }

    public function targetsChanged(SiteTargetsChanged $event): void
    {
        Upstream::query()->where('site_id', $event->siteId)->whereIn('server_id', $event->removed)->delete();
        SyncCloudflareDns::site($event->siteId); // one record per server the site runs on

        foreach (Certificate::query()->where('site_id', $event->siteId)->get() as $certificate) {
            $this->certificates->sync($certificate);
        }

        $this->changes->siteChanged($event->siteId, [...$event->serverIds, ...$event->removed]);
        // Sites with a path served by this function route to it locally or over HTTPS depending on its servers.
        $this->mounts->functionChanged($event->siteId);
    }

    public function deleted(SiteDeleted $event): void
    {
        SyncCloudflareDns::forgetSite($event->siteId);
        $this->mounts->siteDeleted($event->siteId);

        $balancer = LoadBalancer::query()->where('site_id', $event->siteId)->value('server_id');

        foreach (Certificate::query()->where('site_id', $event->siteId)->where('organization_id', $event->organizationId)->get() as $certificate) {
            $this->certificates->uninstallEverywhere($certificate);
            $certificate->delete();
        }

        foreach (Domain::query()->where('site_id', $event->siteId)->get() as $domain) {
            $domain->delete();
            DomainRemoved::dispatch($domain->id, $domain->site_id, $domain->organization_id, $domain->name);
        }

        foreach ([Redirect::class, SecurityRule::class, Header::class, SiteSetting::class, ServiceSetting::class, LoadBalancer::class, Upstream::class] as $model) {
            $model::query()->where('site_id', $event->siteId)->delete();
        }

        $this->routes->schedule(...array_values(array_filter([...$event->serverIds, $balancer])));
    }
}
