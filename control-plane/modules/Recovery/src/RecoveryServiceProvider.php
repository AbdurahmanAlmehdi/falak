<?php

namespace Falak\Recovery;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\MemberRemoved;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Kernel\Support\SharedProps;
use Falak\Recovery\Application\ControlPlaneNotice;
use Falak\Recovery\Application\ControlPlaneStatus;
use Falak\Recovery\Application\Jobs\AdvanceServerRecoveries;
use Falak\Recovery\Application\Jobs\AdvanceSiteMoves;
use Falak\Recovery\Application\Jobs\CheckControlPlaneRecovery;
use Falak\Recovery\Contracts\OperatorOrganization;
use Falak\Recovery\Domain\Models\Dismissal;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Falak\Recovery\Domain\Models\SiteMove;
use Falak\Recovery\Http\Controllers\ReadinessController;
use Falak\Recovery\Http\Controllers\ServerRecoveryController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Disaster recovery: the control plane's own (status from falak-ctl, the setup prompt and dr.* alerts), "This server
 * is gone" (moving a lost server's databases, volumes, services and domains to another one), "Move to another server"
 * (a live site, or all of a server's, moved with its databases and volumes) and DR readiness per project. It only uses other modules' contracts (DatabaseRecovery, VolumeRecovery, SiteRelocation, DomainRecords …).
 */
class RecoveryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/recovery.php', 'recovery');
        // Read fresh each time it is resolved: falak-ctl rewrites the file while workers run.
        $this->app->bind(ControlPlaneStatus::class, fn () => new ControlPlaneStatus);
        $this->app->bind(OperatorOrganization::class, ControlPlaneNotice::class);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(ControlPlaneNotice::PERMISSION, [Role::Admin], 'See the control plane\'s disaster recovery status and its setup prompt (operator organization only)', 'recovery');
        $registry->register(ServerRecoveryController::PERMISSION, [Role::Admin], 'Recover a lost server: move its databases, volumes, services and domains to another server', 'recovery');
        $registry->register(ReadinessController::PERMISSION, [Role::Admin, Role::Developer, Role::Viewer], 'View the disaster recovery readiness of projects', 'recovery');

        $types = $this->app->make(AlertTypes::class);
        $types->register(CheckControlPlaneRecovery::NOT_CONFIGURED, 'Control plane disaster recovery not configured (weekly)', 'Disaster recovery', Severity::Warning);
        $types->register(CheckControlPlaneRecovery::BACKUP_FAILED, 'Control plane backup failed', 'Disaster recovery', Severity::Critical);
        $types->register(CheckControlPlaneRecovery::BACKUP_MISSING, 'No recent control plane backup', 'Disaster recovery', Severity::Critical);
        $types->register(CheckControlPlaneRecovery::DRILL_FAILED, 'Control plane restore drill failed', 'Disaster recovery', Severity::Critical);

        $this->app->make(SharedProps::class)->register('disasterRecovery', fn (Request $request) => $this->app->make(ControlPlaneNotice::class)
            ->forUser($request->user(), $this->app->make(CurrentOrganization::class)->id(), $this->app->make(ControlPlaneStatus::class)));

        Event::listen(OrganizationDeleted::class, function (OrganizationDeleted $event) {
            ServerRecovery::query()->where('organization_id', $event->organizationId)->delete();
            SiteMove::query()->where('organization_id', $event->organizationId)->delete();
        });
        Event::listen(MemberRemoved::class, fn (MemberRemoved $event) => Dismissal::query()->where('user_id', $event->userId)->delete());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new CheckControlPlaneRecovery)->hourly()->name('recovery:control-plane')->withoutOverlapping();
            $schedule->job(new AdvanceServerRecoveries)->everyMinute()->name('recovery:servers')->withoutOverlapping();
            $schedule->job(new AdvanceSiteMoves)->everyMinute()->name('recovery:site-moves')->withoutOverlapping();
        });
    }
}
