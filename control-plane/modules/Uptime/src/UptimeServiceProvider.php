<?php

namespace Falak\Uptime;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Events\DomainRemoved;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Sites\Events\SiteDeleted;
use Falak\Uptime\Application\Actions\DeleteMonitor;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Jobs\DispatchChecks;
use Falak\Uptime\Application\Jobs\PruneUptime;
use Falak\Uptime\Application\Jobs\SyncAllSiteMonitors;
use Falak\Uptime\Application\Probing\GuardedProber;
use Falak\Uptime\Application\Probing\HostResolver;
use Falak\Uptime\Application\Probing\Prober;
use Falak\Uptime\Application\Probing\Transport;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Models\StatusPage;
use Falak\Uptime\Domain\Policies\UptimePolicy;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\CertificateInspector;
use Falak\Uptime\Http\Controllers\StatusPageController;
use Falak\Uptime\Infrastructure\CurlTransport;
use Falak\Uptime\Infrastructure\SystemHostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Uptime checks from the control plane (docs/plans/network/uptime.md): monitors of every site domain and user-defined
 * HTTP / TCP / TLS checks, incidents, `uptime` Issues, `uptime.*` alerts and public status pages.
 */
class UptimeServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        HostResolver::class => SystemHostResolver::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/uptime.php', 'uptime');

        $this->app->singleton(AddressGuard::class, fn () => new AddressGuard(
            (bool) config('uptime.allow_private_targets', false),
            array_values((array) config('uptime.blocked_cidrs', [])),
        ));
        $this->app->singleton(Transport::class, fn () => new CurlTransport(config('uptime.ca_file') ?: null));
        $this->app->singleton(Prober::class, fn ($app) => new GuardedProber(
            $app->make(HostResolver::class),
            $app->make(Transport::class),
            $app->make(AddressGuard::class),
            new CertificateInspector,
            config('uptime.ca_file') ?: null,
        ));
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath().'/resources/views', 'uptime');

        Gate::policy(Monitor::class, UptimePolicy::class);
        Gate::policy(StatusPage::class, UptimePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(UptimePolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View uptime monitors, incidents and status pages', 'uptime');
        $registry->register(UptimePolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, change, pause and delete uptime monitors', 'uptime');
        $registry->register(UptimePolicy::PUBLISH, [Role::Admin], 'Publish public status pages', 'uptime');

        $types = $this->app->make(AlertTypes::class);
        $types->register(RecordCheck::ALERT_DOWN, 'Monitor down', 'Uptime', Severity::Critical, 'Open monitor');
        $types->register(RecordCheck::ALERT_TLS, 'Certificate chain, host name or expiry problem', 'Uptime', Severity::Warning, 'Open monitor');
        $types->register(RecordCheck::ALERT_SLOW, 'Slow responses', 'Uptime', Severity::Warning, 'Open monitor');
        $types->register(RecordCheck::ALERT_RECOVERED, 'Monitor recovered', 'Uptime', Severity::Info);

        // Public status pages: outside the web group (no session, no cookies, no CSRF), rate-limited per IP.
        RateLimiter::for('uptime-status', fn (Request $request) => Limit::perMinute(max(1, (int) config('uptime.status_page.rate_per_minute', 60)))->by((string) $request->ip()));
        Route::middleware('throttle:uptime-status')->get('status/{slug}', [StatusPageController::class, 'show'])->name('uptime.status-page');

        $sync = fn (DomainAdded|DomainRemoved $event) => SyncAllSiteMonitors::dispatch($event->organizationId)->afterCommit();
        Event::listen(DomainAdded::class, $sync);
        Event::listen(DomainRemoved::class, $sync);
        Event::listen(SiteDeleted::class, function (SiteDeleted $event) {
            // Automatic monitors go with the site; user monitors stay, no longer attached to it.
            Monitor::query()->where('organization_id', $event->organizationId)->where('site_id', $event->siteId)->where('source', MonitorSource::Auto)
                ->get()->each(fn (Monitor $monitor) => app(DeleteMonitor::class)($monitor));
            Monitor::query()->where('organization_id', $event->organizationId)->where('site_id', $event->siteId)->update(['site_id' => null]);
        });
        Event::listen(OrganizationDeleted::class, function (OrganizationDeleted $event) {
            $monitors = Monitor::query()->where('organization_id', $event->organizationId)->pluck('id');
            DB::table('uptime_checks')->whereIn('monitor_id', $monitors)->delete();
            DB::table('uptime_rollups')->whereIn('monitor_id', $monitors)->delete();
            DB::table('uptime_incidents')->where('organization_id', $event->organizationId)->delete();
            DB::table('uptime_status_page_monitors')->whereIn('monitor_id', $monitors)->delete();
            DB::table('uptime_status_pages')->where('organization_id', $event->organizationId)->delete();
            Monitor::query()->where('organization_id', $event->organizationId)->delete();
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new DispatchChecks)->everyTenSeconds()->name('uptime:dispatch')->withoutOverlapping();
            $schedule->job(new SyncAllSiteMonitors)->everyTenMinutes()->name('uptime:sync')->withoutOverlapping();
            $schedule->job(new PruneUptime)->dailyAt('03:40')->name('uptime:prune')->withoutOverlapping();
        });
    }
}
