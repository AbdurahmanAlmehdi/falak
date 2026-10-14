<?php

namespace Falak\Insights;

use Falak\Fleet\Events\InsightsReceived;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Insights\Application\Jobs\DetectMissedHeartbeats;
use Falak\Insights\Application\Jobs\EvaluateThresholdsJob;
use Falak\Insights\Application\Jobs\PruneInsightsJob;
use Falak\Insights\Application\Listeners\DeleteOrganizationInsights;
use Falak\Insights\Application\Listeners\ExpectScheduledJobs;
use Falak\Insights\Application\Listeners\IngestReceivedInsights;
use Falak\Insights\Contracts\DatabaseIssues;
use Falak\Insights\Contracts\IssueDirectory;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Insights\Domain\Models\HeartbeatMonitor;
use Falak\Insights\Domain\Models\Issue;
use Falak\Insights\Domain\Models\Threshold;
use Falak\Insights\Domain\Policies\OrganizationScopedPolicy;
use Falak\Insights\Infrastructure\EloquentIssueDirectory;
use Falak\Insights\Infrastructure\IdSiteNameResolver;
use Falak\Insights\Infrastructure\TrackedDatabaseIssues;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Processes\Events\SchedulesApplied;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class InsightsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        IssueDirectory::class => EloquentIssueDirectory::class,
        DatabaseIssues::class => TrackedDatabaseIssues::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/insights.php', 'insights');

        // Default only: the Sites module (registered earlier) binds the real resolver, which must win.
        $this->app->singletonIf(SiteNameResolver::class, IdSiteNameResolver::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(Issue::class, OrganizationScopedPolicy::class);
        Gate::policy(Threshold::class, OrganizationScopedPolicy::class);
        Gate::policy(HeartbeatMonitor::class, OrganizationScopedPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('insights.view', [Role::Admin, Role::Developer, Role::Viewer], 'View application insights, issues and scheduled task health', 'insights');
        $registry->register('insights.manage', [Role::Admin, Role::Developer], 'Resolve, assign and comment on issues; configure thresholds and heartbeats', 'insights');

        Event::listen(InsightsReceived::class, IngestReceivedInsights::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationInsights::class);
        Event::listen(SchedulesApplied::class, ExpectScheduledJobs::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new EvaluateThresholdsJob)->everyMinute()->name('insights:thresholds')->withoutOverlapping();
            $schedule->job(new DetectMissedHeartbeats)->everyMinute()->name('insights:heartbeats')->withoutOverlapping();
            $schedule->job(new PruneInsightsJob)->dailyAt('03:15')->name('insights:prune')->withoutOverlapping();
        });
    }
}
