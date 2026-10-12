<?php

namespace Falak\Processes;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Config\Contracts\Reconcilable;
use Falak\Edge\Events\EdgeApplied;
use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Processes\Application\Jobs\PollProcessStatus;
use Falak\Processes\Application\Listeners\ConvergeOnSiteChanges;
use Falak\Processes\Application\Listeners\DeleteOrganizationProcesses;
use Falak\Processes\Application\Listeners\ForgetDeletedServer;
use Falak\Processes\Application\Listeners\HandleCommandOutcome;
use Falak\Processes\Application\Listeners\ResendLostProcessSecrets;
use Falak\Processes\Application\Listeners\StopDrainedOctane;
use Falak\Processes\Contracts\OctaneRouting;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Processes\Contracts\ProcessOwners;
use Falak\Processes\Contracts\ScheduleDirectory;
use Falak\Processes\Contracts\ScheduleSources;
use Falak\Processes\Events\ProgramCrashLooping;
use Falak\Processes\Events\ProgramRecovered;
use Falak\Processes\Infrastructure\AgentProcessControl;
use Falak\Processes\Infrastructure\Config\ProcessesReconciler;
use Falak\Processes\Infrastructure\EloquentOctaneRouting;
use Falak\Processes\Infrastructure\EloquentProcessOwners;
use Falak\Processes\Infrastructure\NoScheduleSources;
use Falak\Processes\Infrastructure\ProcessesCapacity;
use Falak\Processes\Infrastructure\StateScheduleDirectory;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetReady;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class ProcessesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ScheduleDirectory::class => StateScheduleDirectory::class,
        ScheduleSources::class => NoScheduleSources::class,
        OctaneRouting::class => EloquentOctaneRouting::class,
        ProcessOwners::class => EloquentProcessOwners::class,
        // One instance per process: diff() keeps the env values apply() needs (plans only carry env keys).
        ProcessesReconciler::class => ProcessesReconciler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/processes.php', 'processes');

        // Not a singleton: tests swap the AgentGateway after boot.
        $this->app->bind(ProcessControl::class, AgentProcessControl::class);

        $this->app->tag([ProcessesReconciler::class], Reconcilable::TAG);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('processes.view', [Role::Admin, Role::Developer, Role::Viewer], 'View queue workers, daemons, scheduled jobs and their status', 'processes');
        $registry->register('processes.manage', [Role::Admin, Role::Developer], 'Manage queue workers, daemons and scheduled jobs; restart processes', 'processes');

        $types = $this->app->make(AlertTypes::class);
        $types->register(ProgramCrashLooping::ALERT_TYPE, 'Process keeps crashing', 'Processes', Severity::Critical);
        $types->register(ProgramRecovered::ALERT_TYPE, 'Process running again', 'Processes', Severity::Info);

        // Workers' and daemons' limits in servers' capacity views.
        $this->app->make(CapacitySources::class)->register(ProcessesCapacity::class);

        Event::listen(SiteCreated::class, [ConvergeOnSiteChanges::class, 'created']);
        Event::listen(SiteUpdated::class, [ConvergeOnSiteChanges::class, 'updated']);
        Event::listen(SiteTargetsChanged::class, [ConvergeOnSiteChanges::class, 'targetsChanged']);
        Event::listen(SiteTargetReady::class, [ConvergeOnSiteChanges::class, 'targetReady']);
        Event::listen(SiteDeleted::class, [ConvergeOnSiteChanges::class, 'deleted']);
        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(AgentSecretsMissing::class, ResendLostProcessSecrets::class);
        Event::listen(EdgeApplied::class, StopDrainedOctane::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationProcesses::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $minutes = (int) config('processes.status_poll_minutes', 5);

            if ($minutes > 0) {
                $schedule->job(new PollProcessStatus)->cron($minutes === 1 ? '* * * * *' : "*/{$minutes} * * * *")->name('processes:status')->withoutOverlapping();
            }
        });
    }
}
