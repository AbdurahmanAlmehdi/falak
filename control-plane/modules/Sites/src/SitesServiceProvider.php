<?php

namespace Falak\Sites;

use Falak\Config\Contracts\Reconcilable;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Projects\Events\ServiceLinked;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Application\Listeners\ApplyLimitDefaults;
use Falak\Sites\Application\Listeners\DeleteOrganizationSites;
use Falak\Sites\Application\Listeners\DetachSourceConnection;
use Falak\Sites\Application\Listeners\HandleCommandOutcome;
use Falak\Sites\Application\Listeners\ReapplyFpmPools;
use Falak\Sites\Application\Listeners\RecordComposeStatus;
use Falak\Sites\Application\Listeners\RemoveServerTargets;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Contracts\SiteDeploySettings;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteEnvironments;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Contracts\SiteHeaders;
use Falak\Sites\Contracts\SiteLimits;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Policies\SitePolicy;
use Falak\Sites\Infrastructure\ActionSiteFactory;
use Falak\Sites\Infrastructure\ActionSiteLimits;
use Falak\Sites\Infrastructure\ActionSiteRelocation;
use Falak\Sites\Infrastructure\Compose\EloquentComposeServiceExtraction;
use Falak\Sites\Infrastructure\Compose\EloquentComposeSites;
use Falak\Sites\Infrastructure\Compose\YamlComposeInspector;
use Falak\Sites\Infrastructure\Config\SitesReconciler;
use Falak\Sites\Infrastructure\EloquentServerSites;
use Falak\Sites\Infrastructure\EloquentSiteDeploySettings;
use Falak\Sites\Infrastructure\EloquentSiteDirectory;
use Falak\Sites\Infrastructure\EloquentSiteEnvironments;
use Falak\Sites\Infrastructure\EloquentSiteHeaders;
use Falak\Sites\Infrastructure\EloquentSiteNameResolver;
use Falak\Sites\Infrastructure\NullSiteDomains;
use Falak\Sites\Infrastructure\PatternSecretVariables;
use Falak\Sites\Infrastructure\SitesCapacity;
use Falak\SourceControl\Events\ConnectionDeleted;
use Falak\Telemetry\Contracts\ServerSites;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class SitesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        SiteDirectory::class => EloquentSiteDirectory::class,
        SiteHeaders::class => EloquentSiteHeaders::class,
        SiteDeploySettings::class => EloquentSiteDeploySettings::class,
        SecretVariables::class => PatternSecretVariables::class,
        SiteEnvironments::class => EloquentSiteEnvironments::class,
        SiteFactory::class => ActionSiteFactory::class,
        SiteLimits::class => ActionSiteLimits::class,
        SiteRelocation::class => ActionSiteRelocation::class,
        ComposeInspector::class => YamlComposeInspector::class,
        ComposeSites::class => EloquentComposeSites::class,
        ComposeServiceExtraction::class => EloquentComposeServiceExtraction::class,
        // Insights and Telemetry register later and only fill these when unbound.
        SiteNameResolver::class => EloquentSiteNameResolver::class,
        ServerSites::class => EloquentServerSites::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/sites.php', 'sites');

        // falak.yaml: the unit fields, env and deploy hooks of app units.
        $this->app->tag([SitesReconciler::class], Reconcilable::TAG);

        // Edge owns domains and rebinds this (it boots after Sites).
        if (! $this->app->bound(SiteDomains::class)) {
            $this->app->singleton(SiteDomains::class, NullSiteDomains::class);
        }
    }

    protected function bootModule(): void
    {
        Gate::policy(Site::class, SitePolicy::class);
        // New sites placed outside production start with that environment's default limits.
        Event::listen(ServiceLinked::class, ApplyLimitDefaults::class);
        // Sites' and compose services' limits in servers' capacity views.
        $this->app->make(CapacitySources::class)->register(SitesCapacity::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('sites.view', [Role::Admin, Role::Developer, Role::Viewer], 'View sites, their settings and command history', 'sites');
        $registry->register('sites.create', [Role::Admin, Role::Developer], 'Create sites', 'sites');
        $registry->register('sites.manage', [Role::Admin, Role::Developer], 'Change site settings, servers, deploy scripts and Laravel toggles', 'sites');
        $registry->register('sites.delete', [Role::Admin], 'Delete sites', 'sites');
        $registry->register('sites.env.view', [Role::Admin, Role::Developer], 'Reveal site environment variables', 'sites');
        $registry->register('sites.env.manage', [Role::Admin, Role::Developer], 'Edit site environment variables', 'sites');
        $registry->register('sites.compose.policy', [Role::Admin], 'Allow privileged Docker Compose files (host namespaces, capabilities, host mounts)', 'sites');
        $registry->register('sites.commands.run', [Role::Admin, Role::Developer], 'Run commands in a site on its servers', 'sites');

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(CommandFinished::class, [RecordComposeStatus::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [RecordComposeStatus::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, RemoveServerTargets::class);
        Event::listen(AgentVersionChanged::class, ReapplyFpmPools::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationSites::class);
        Event::listen(ConnectionDeleted::class, DetachSourceConnection::class);
    }
}
