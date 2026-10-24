<?php

namespace Falak\Edge;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Config\Contracts\Reconcilable;
use Falak\Deployments\Events\DeploymentRolledBack;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Edge\Application\CertificateInstaller;
use Falak\Edge\Application\CloudflareRateLimits;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\CheckCertificateExpiry;
use Falak\Edge\Application\Jobs\PurgeCloudflareCache;
use Falak\Edge\Application\Jobs\RecheckDomainVerification;
use Falak\Edge\Application\Jobs\ReconcileCloudflareTunnels;
use Falak\Edge\Application\Jobs\ReconcilePreviewRecords;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Application\Listeners\ForgetDeletedOrganization;
use Falak\Edge\Application\Listeners\ForgetDeletedServer;
use Falak\Edge\Application\Listeners\HandleEdgeCommandOutcome;
use Falak\Edge\Application\Listeners\ReactToSiteChanges;
use Falak\Edge\Application\Listeners\ReapplyAfterAgentUpgrade;
use Falak\Edge\Application\Listeners\ReapplyOnRequestIdLinking;
use Falak\Edge\Application\PathMounts;
use Falak\Edge\Contracts\DnsCheck;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Edge\Contracts\TailnetSites;
use Falak\Edge\Events\CertificateInstallFailed;
use Falak\Edge\Events\CertificateIssued;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Events\DomainRemoved;
use Falak\Edge\Infrastructure\CloudflareOriginPolicy;
use Falak\Edge\Infrastructure\Config\EdgeReconciler;
use Falak\Edge\Infrastructure\Dns\DnsResolver;
use Falak\Edge\Infrastructure\Dns\DohResolver;
use Falak\Edge\Infrastructure\Dns\StreamTlsProbe;
use Falak\Edge\Infrastructure\Dns\SystemResolver;
use Falak\Edge\Infrastructure\Dns\TlsProbe;
use Falak\Edge\Infrastructure\EloquentDomainRecords;
use Falak\Edge\Infrastructure\EloquentEdgeRoutes;
use Falak\Edge\Infrastructure\EloquentPreviewDomains;
use Falak\Edge\Infrastructure\EloquentSiteDomains;
use Falak\Edge\Infrastructure\EloquentTailnetSites;
use Falak\Edge\Infrastructure\ResolverDnsCheck;
use Falak\Edge\Infrastructure\RouteCompiler;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Contracts\WebOriginPolicy;
use Falak\Processes\Contracts\OctaneRouting;
use Falak\Processes\Events\OctaneRoutingChanged;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Events\ComposeServiceExtracted;
use Falak\Sites\Events\ComposeServicesUnpublished;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;
use Falak\Telemetry\Events\RequestIdLinkingChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;

class EdgeServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     * Edge registers after Sites, so its SiteDomains binding replaces Sites' null implementation.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // Origin lock-down of servers behind Cloudflare (Network compiles it into the firewall).
        WebOriginPolicy::class => CloudflareOriginPolicy::class,
        SiteDomains::class => EloquentSiteDomains::class,
        DnsCheck::class => ResolverDnsCheck::class,
        DomainRecords::class => EloquentDomainRecords::class,
        TlsProbe::class => StreamTlsProbe::class,
        TailnetSites::class => EloquentTailnetSites::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        // Uses the request-scoped audit log.
        PreviewDomains::class => EloquentPreviewDomains::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/edge.php', 'edge');

        $this->app->tag([EdgeReconciler::class], Reconcilable::TAG);

        $this->app->bind(RouteCompiler::class, fn ($app) => new RouteCompiler(
            $app->make(SiteDirectory::class),
            $app->make(ServerDirectory::class),
            $app->make(OctaneRouting::class),
            config('edge.acme_email') ?: null,
            config('edge.acme_ca') ?: null,
            (string) config('edge.test_domain_tls', 'acme'),
        ));

        $this->app->bind(EdgeRoutes::class, fn ($app) => new EloquentEdgeRoutes(
            $app->make(RouteCompiler::class),
            $app->make(AgentGateway::class),
            $app->make(ServerDirectory::class),
            (int) config('edge.apply_delay_seconds', 2),
            (int) config('edge.apply_timeout_seconds', 120),
            (string) config('edge.test_domain_tls', 'acme'),
        ));

        $this->app->singleton(DnsResolver::class, fn ($app) => config('edge.dns.resolver') === 'system'
            ? new SystemResolver
            : new DohResolver($app->make(HttpFactory::class), (string) config('edge.dns.doh_url'), (int) config('edge.dns.timeout_seconds', 3)));

        $this->app->bind(CertificateInstaller::class, fn ($app) => new CertificateInstaller(
            $app->make(AgentGateway::class),
            $app->make(EdgeChanges::class),
            (int) config('edge.cert_install_timeout_seconds', 60),
        ));
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('edge.view', [Role::Admin, Role::Developer, Role::Viewer], 'View domains, certificates and routing rules', 'edge');
        $registry->register('edge.manage', [Role::Admin, Role::Developer], 'Manage domains, certificates, redirects, security rules and load balancers', 'edge');
        $registry->register('edge.dns.manage', [Role::Admin], 'Manage DNS provider credentials for DNS-01 certificates and generated domains', 'edge');

        $types = $this->app->make(AlertTypes::class);
        $types->register(CertificateInstallFailed::ALERT_TYPE, 'Certificate install failed', 'Edge', Severity::Critical, 'Inspect certificate');
        $types->register('edge.certificate_expiring', 'Certificate expires in 14, 7 or 1 days', 'Edge', Severity::Warning, 'Inspect certificate');
        $types->register(CertificateIssued::ALERT_TYPE, 'Certificate installed', 'Edge', Severity::Info);

        Event::listen(SiteCreated::class, [ReactToSiteChanges::class, 'created']);
        Event::listen(SiteUpdated::class, [ReactToSiteChanges::class, 'updated']);
        Event::listen(SiteTargetsChanged::class, [ReactToSiteChanges::class, 'targetsChanged']);
        Event::listen(SiteDeleted::class, [ReactToSiteChanges::class, 'deleted']);
        Event::listen(ComposeServiceExtracted::class, [ReactToSiteChanges::class, 'extracted']);
        Event::listen(ComposeServicesUnpublished::class, [ReactToSiteChanges::class, 'unpublished']);
        Event::listen(OctaneRoutingChanged::class, [ReactToSiteChanges::class, 'octaneRoutingChanged']);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(RequestIdLinkingChanged::class, ReapplyOnRequestIdLinking::class);
        // Cloudflare DNS follows the domains (records Falak created only).
        Event::listen(DomainAdded::class, fn (DomainAdded $event) => SyncCloudflareDns::domain($event->domainId));
        Event::listen(DomainRemoved::class, fn (DomainRemoved $event) => SyncCloudflareDns::forget($event->domainId));
        // Its Cloudflare rate limit rule goes with it.
        Event::listen(DomainRemoved::class, fn (DomainRemoved $event) => app(CloudflareRateLimits::class)->resyncFor($event->organizationId, $event->name));
        // A function's domain is how other servers reach its paths (edge mounts).
        Event::listen(DomainAdded::class, fn (DomainAdded $event) => app(PathMounts::class)->functionChanged($event->siteId));
        Event::listen(DomainRemoved::class, fn (DomainRemoved $event) => app(PathMounts::class)->functionChanged($event->siteId));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new ReconcileCloudflareTunnels)->everyFiveMinutes()->name('edge:cloudflare-tunnels')->withoutOverlapping();
            $schedule->job(new CheckCertificateExpiry)->hourlyAt(17)->name('edge:certificate-expiry')->withoutOverlapping();
            $schedule->job(new ReconcilePreviewRecords)->everyTenMinutes()->name('edge:preview-records')->withoutOverlapping();
            $schedule->job(new RecheckDomainVerification)->everyFiveMinutes()->name('edge:domain-verification')->withoutOverlapping();
        });
        // Visitors get the new release: purge the site's names at Cloudflare after deploys and rollbacks.
        Event::listen(DeploymentSucceeded::class, fn (DeploymentSucceeded $event) => PurgeCloudflareCache::dispatch($event->siteId));
        Event::listen(DeploymentRolledBack::class, fn (DeploymentRolledBack $event) => PurgeCloudflareCache::dispatch($event->siteId));
        Event::listen(AgentVersionChanged::class, ReapplyAfterAgentUpgrade::class);
        Event::listen(OrganizationDeleted::class, ForgetDeletedOrganization::class);
        Event::listen(CommandFinished::class, [HandleEdgeCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleEdgeCommandOutcome::class, 'handleFailed']);
    }
}
