<?php

namespace Falak\Telemetry;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Deployments\Events\ReleaseActivated;
use Falak\Fleet\Events\AgentEnrolled;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationCreated;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;
use Falak\Telemetry\Application\Console\ProvisionGrafanaCommand;
use Falak\Telemetry\Application\Http\EvaluateHttpTraffic;
use Falak\Telemetry\Application\Jobs\AnalyzeHttpTraffic;
use Falak\Telemetry\Application\Jobs\DispatchPendingTelemetry;
use Falak\Telemetry\Application\Jobs\RefreshGeoDatabases;
use Falak\Telemetry\Application\Listeners\ConfigureTelemetryOnEnrollment;
use Falak\Telemetry\Application\Listeners\ConfigureTelemetryOnProvisioned;
use Falak\Telemetry\Application\Listeners\ForgetDeletedSiteTraffic;
use Falak\Telemetry\Application\Listeners\ForgetOrganizationTelemetry;
use Falak\Telemetry\Application\Listeners\ProvisionGrafanaForOrganization;
use Falak\Telemetry\Application\Listeners\ReconfigureAfterAgentUpgrade;
use Falak\Telemetry\Application\Listeners\ReconfigureOnLogSourceChanges;
use Falak\Telemetry\Application\Listeners\ReconfigureOnReleaseActivated;
use Falak\Telemetry\Application\Listeners\ReconfigureOnSiteChanges;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Falak\Telemetry\Contracts\AccessLogs;
use Falak\Telemetry\Contracts\Annotations;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Contracts\LogsQuery;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Contracts\RequestIdLinking;
use Falak\Telemetry\Contracts\ServerSites;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Falak\Telemetry\Contracts\TracesQuery;
use Falak\Telemetry\Infrastructure\AgentTelemetryConfigurator;
use Falak\Telemetry\Infrastructure\DefaultTelemetryLinks;
use Falak\Telemetry\Infrastructure\Geo\GeoDatabases;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaAnnotations;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Falak\Telemetry\Infrastructure\LokiAccessLogCounts;
use Falak\Telemetry\Infrastructure\LokiAccessLogs;
use Falak\Telemetry\Infrastructure\LokiLogMetrics;
use Falak\Telemetry\Infrastructure\LokiLogsQuery;
use Falak\Telemetry\Infrastructure\Metrics\MimirBackend;
use Falak\Telemetry\Infrastructure\Metrics\VictoriaMetricsBackend;
use Falak\Telemetry\Infrastructure\NullServerSites;
use Falak\Telemetry\Infrastructure\SettingsRequestIdLinking;
use Falak\Telemetry\Infrastructure\TempoTracesQuery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class TelemetryServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        TelemetryLinks::class => DefaultTelemetryLinks::class,
        RequestIdLinking::class => SettingsRequestIdLinking::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/telemetry.php', 'telemetry');

        // Clients read config on resolution (cheap), so config changes (tests, settings) apply immediately.
        $this->app->bind(MetricsBackend::class, fn () => config('telemetry.metrics.backend') === 'mimir'
            ? MimirBackend::fromConfig()
            : VictoriaMetricsBackend::fromConfig());
        $this->app->bind(LogsQuery::class, fn () => LokiLogsQuery::fromConfig());
        $this->app->bind(LogMetrics::class, fn () => LokiLogMetrics::fromConfig());
        $this->app->bind(TracesQuery::class, fn () => TempoTracesQuery::fromConfig());
        $this->app->bind(GrafanaClient::class, fn () => GrafanaClient::fromConfig());
        $this->app->bind(Annotations::class, GrafanaAnnotations::class);
        $this->app->bind(TelemetryConfigurator::class, AgentTelemetryConfigurator::class);
        $this->app->bind(AccessLogs::class, LokiAccessLogs::class);
        $this->app->bind(AccessLogCounts::class, LokiAccessLogCounts::class);

        // Sites registers before Telemetry and binds its own ServerSites; only fill the gap.
        $this->app->singletonIf(ServerSites::class, NullServerSites::class);
        $this->app->singleton(GeoDatabases::class);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('telemetry.view', [Role::Admin, Role::Developer, Role::Viewer], 'View server metrics, logs and traces', 'telemetry');
        $registry->register('telemetry.manage', [Role::Admin], 'Configure telemetry endpoints and provision Grafana', 'telemetry');

        // HTTP analytics (docs/plans/network/http.md), the default rule pack's "HTTP" area.
        $types = $this->app->make(AlertTypes::class);
        $types->register(EvaluateHttpTraffic::ALERT_ERRORS, 'Spike of 5xx responses on a site', 'HTTP', Severity::Warning, 'Review logs');
        $types->register(EvaluateHttpTraffic::ALERT_SLOW, 'Slow endpoint (p95 above the site\'s threshold)', 'HTTP', Severity::Warning, 'Review the issue');
        $types->register(EvaluateHttpTraffic::ALERT_BANDWIDTH, 'Monthly bandwidth threshold reached', 'HTTP', Severity::Warning, 'Review traffic');
        $types->register(EvaluateHttpTraffic::ALERT_SCANNER, 'Surge of scanner and attack requests', 'HTTP', Severity::Warning, 'Review threats');

        Event::listen(AgentEnrolled::class, ConfigureTelemetryOnEnrollment::class);
        Event::listen(AgentVersionChanged::class, ReconfigureAfterAgentUpgrade::class);
        Event::listen(ReleaseActivated::class, ReconfigureOnReleaseActivated::class);
        Event::listen(ServerProvisioned::class, ConfigureTelemetryOnProvisioned::class);
        Event::listen(OrganizationCreated::class, ProvisionGrafanaForOrganization::class);
        Event::listen(OrganizationDeleted::class, ForgetOrganizationTelemetry::class);
        Event::listen([SiteCreated::class, SiteTargetsChanged::class, SiteDeleted::class], ReconfigureOnSiteChanges::class);
        Event::listen(SiteUpdated::class, ReconfigureOnLogSourceChanges::class);
        Event::listen(SiteDeleted::class, ForgetDeletedSiteTraffic::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new DispatchPendingTelemetry)->everyMinute()->name('telemetry:pending-configure')->withoutOverlapping();
            $schedule->job(new AnalyzeHttpTraffic('evaluate'))->everyFiveMinutes()->name('telemetry:http-evaluate')->withoutOverlapping();
            $schedule->job(new AnalyzeHttpTraffic('bandwidth'))->everyTenMinutes()->name('telemetry:http-bandwidth')->withoutOverlapping();
            $schedule->job(new RefreshGeoDatabases)->dailyAt('04:17')->name('telemetry:geoip')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionGrafanaCommand::class]);
        }
    }
}
