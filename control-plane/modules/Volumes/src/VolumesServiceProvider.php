<?php

namespace Falak\Volumes;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Config\Contracts\Reconcilable;
use Falak\Databases\Events\StorageProviderDeleted;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Events\SiteDeleted;
use Falak\Volumes\Application\Jobs\CheckVolumeBackups;
use Falak\Volumes\Application\Jobs\PruneDownloads;
use Falak\Volumes\Application\Jobs\RefreshVolumeUsage;
use Falak\Volumes\Application\Jobs\RunDueVolumeBackups;
use Falak\Volumes\Application\Jobs\RunDueVolumeDrills;
use Falak\Volumes\Application\Listeners\ForgetDeletedResources;
use Falak\Volumes\Application\Listeners\HandleCommandOutcome;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeMounts;
use Falak\Volumes\Contracts\VolumeRecovery;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Policies\VolumePolicy;
use Falak\Volumes\Events\VolumeAlmostFull;
use Falak\Volumes\Events\VolumeBackupFinished;
use Falak\Volumes\Events\VolumeDrillFinished;
use Falak\Volumes\Infrastructure\ActionServiceVolumes;
use Falak\Volumes\Infrastructure\Config\VolumesReconciler;
use Falak\Volumes\Infrastructure\EloquentVolumeMounts;
use Falak\Volumes\Infrastructure\EloquentVolumeRecovery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class VolumesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        VolumeMounts::class => EloquentVolumeMounts::class,
        ServiceVolumes::class => ActionServiceVolumes::class,
        VolumeRecovery::class => EloquentVolumeRecovery::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/volumes.php', 'volumes');

        // falak.yaml: units.<app>.volumes.
        $this->app->tag([VolumesReconciler::class], Reconcilable::TAG);
    }

    protected function bootModule(): void
    {
        foreach ([Volume::class, Attachment::class, Operation::class, BackupSchedule::class, VolumeBackup::class] as $model) {
            Gate::policy($model, VolumePolicy::class);
        }

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(VolumePolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View volumes, their usage, attachments and backups', 'volumes');
        $registry->register(VolumePolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, attach, resize, back up, restore, clone, move and delete volumes', 'volumes');
        $registry->register(VolumePolicy::BROWSE, [Role::Admin], 'Browse and download the files of volumes (audited)', 'volumes');

        $types = $this->app->make(AlertTypes::class);
        $types->register(VolumeAlmostFull::ALERT_TYPE, 'Volume almost full', 'Volumes', Severity::Warning, 'Grow volume');
        $types->register(VolumeBackupFinished::ALERT_FAILED, 'Volume backup failed', 'Volumes', Severity::Critical, 'Review backups');
        $types->register(VolumeBackupFinished::ALERT_SUCCEEDED, 'Volume backups succeed again', 'Volumes', Severity::Info);
        $types->register('volumes.backup_missed', 'No volume backup within twice the schedule interval', 'Volumes', Severity::Critical, 'Review backups');
        $types->register(VolumeDrillFinished::ALERT_FAILED, 'Volume restore drill failed', 'Volumes', Severity::Critical);
        $types->register(VolumeDrillFinished::ALERT_PASSED, 'Volume restore drills pass again', 'Volumes', Severity::Info);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(SiteDeleted::class, [ForgetDeletedResources::class, 'siteDeleted']);
        Event::listen(ServerDeleted::class, [ForgetDeletedResources::class, 'serverDeleted']);
        Event::listen(StorageProviderDeleted::class, [ForgetDeletedResources::class, 'storageProviderDeleted']);
        Event::listen(OrganizationDeleted::class, [ForgetDeletedResources::class, 'organizationDeleted']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueVolumeBackups)->everyMinute()->name('volumes:backups')->withoutOverlapping();
            $schedule->job(new RunDueVolumeDrills)->everyTenMinutes()->name('volumes:drills')->withoutOverlapping();
            $schedule->job(new RefreshVolumeUsage)->cron('*/'.max(1, min(59, (int) config('volumes.usage_refresh_minutes', 15))).' * * * *')->name('volumes:usage')->withoutOverlapping();
            $schedule->job(new PruneDownloads)->hourly()->name('volumes:prune-downloads')->withoutOverlapping();
            $schedule->job(new CheckVolumeBackups)->everyFiveMinutes()->name('volumes:backup-health')->withoutOverlapping();
        });
    }
}
