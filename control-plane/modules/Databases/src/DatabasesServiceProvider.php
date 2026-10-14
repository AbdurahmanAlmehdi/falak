<?php

namespace Falak\Databases;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Config\Contracts\Reconcilable;
use Falak\Databases\Application\Actions\RecordObservation;
use Falak\Databases\Application\Jobs\CheckBackupHealth;
use Falak\Databases\Application\Jobs\MaintainInstances;
use Falak\Databases\Application\Jobs\MaintainPitr;
use Falak\Databases\Application\Jobs\PruneObservations;
use Falak\Databases\Application\Jobs\RunDueBackups;
use Falak\Databases\Application\Jobs\RunDueDrills;
use Falak\Databases\Application\Listeners\ConvergeInstanceNetwork;
use Falak\Databases\Application\Listeners\DeleteOrganizationData;
use Falak\Databases\Application\Listeners\ForgetDeletedServer;
use Falak\Databases\Application\Listeners\HandleCommandOutcome;
use Falak\Databases\Application\Listeners\RecordInstanceHealth;
use Falak\Databases\Contracts\BackupPosture;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\DrillFinished;
use Falak\Databases\Events\PitrAlert;
use Falak\Databases\Events\RestoreFinished;
use Falak\Databases\Infrastructure\ActionDatabaseProvisioner;
use Falak\Databases\Infrastructure\AgentRequests\DatabaseObserved;
use Falak\Databases\Infrastructure\AgentRequests\PitrGapReported;
use Falak\Databases\Infrastructure\AgentRequests\PitrShipped;
use Falak\Databases\Infrastructure\AgentRequests\PitrUploadUrls;
use Falak\Databases\Infrastructure\Config\DatabasesReconciler;
use Falak\Databases\Infrastructure\EloquentBackupPosture;
use Falak\Databases\Infrastructure\EloquentDatabaseConnections;
use Falak\Databases\Infrastructure\EloquentDatabaseDirectory;
use Falak\Databases\Infrastructure\EloquentDatabaseRecovery;
use Falak\Databases\Infrastructure\ObjectStorageBackupStorage;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class DatabasesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        DatabaseDirectory::class => EloquentDatabaseDirectory::class,
        DatabaseConnections::class => EloquentDatabaseConnections::class,
        DatabaseProvisioner::class => ActionDatabaseProvisioner::class,
        DatabaseRecovery::class => EloquentDatabaseRecovery::class,
        BackupStorage::class => ObjectStorageBackupStorage::class,
        BackupPosture::class => EloquentBackupPosture::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/databases.php', 'databases');

        // falak.yaml: database and redis units.
        $this->app->tag([DatabasesReconciler::class], Reconcilable::TAG);
    }

    protected function bootModule(): void
    {
        foreach ([DatabaseInstance::class, Database::class, DatabaseUser::class, StorageProvider::class, BackupSchedule::class, Backup::class, Restore::class, Drill::class, PitrSegment::class] as $model) {
            Gate::policy($model, DatabasesPolicy::class);
        }

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(DatabasesPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View database servers, databases, users and backups', 'databases');
        $registry->register(DatabasesPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, change, upgrade and delete database servers, databases and users, manage backup schedules and run backups', 'databases');
        $registry->register(DatabasesPolicy::REVEAL, [Role::Admin, Role::Developer], 'Reveal database user passwords', 'databases');
        $registry->register(DatabasesPolicy::RESTORE, [Role::Admin], 'Restore backups (overwrites data)', 'databases');
        $registry->register(DatabasesPolicy::STORAGE, [Role::Admin], 'Manage backup storage providers and their credentials', 'databases');

        $types = $this->app->make(AlertTypes::class);
        $types->register(BackupFailed::ALERT_TYPE, 'Database backup failed', 'Databases', Severity::Critical, 'Review backups');
        $types->register('databases.backup_missed', 'No database backup within twice the schedule interval', 'Databases', Severity::Critical, 'Review backups');
        $types->register('databases.storage_unreachable', 'Backup storage unreachable', 'Databases', Severity::Critical, 'Check storage');
        $types->register('databases.connections_high', 'Database connections above 80% of the limit', 'Databases', Severity::Warning, 'Tune instance settings');
        $types->register(BackupSucceeded::ALERT_TYPE, 'Database backups succeed again', 'Databases', Severity::Info);
        $types->register(RestoreFinished::ALERT_FAILED, 'Database restore failed', 'Databases', Severity::Critical);
        $types->register(RestoreFinished::ALERT_SUCCEEDED, 'Database restore finished', 'Databases', Severity::Info);
        $types->register(DrillFinished::ALERT_FAILED, 'Database restore drill failed', 'Databases', Severity::Critical);
        $types->register(DrillFinished::ALERT_PASSED, 'Database restore drills pass again', 'Databases', Severity::Info);
        $types->register(PitrAlert::LAG, 'Point-in-time recovery lags (unshipped log older than 5 minutes)', 'Databases', Severity::Warning);
        $types->register(PitrAlert::GAP, 'Gap in a point-in-time recovery timeline', 'Databases', Severity::Critical);
        $types->register(PitrAlert::SPOOL_FULL, 'Point-in-time recovery spool above 20% of the volume', 'Databases', Severity::Critical);
        $types->register(PitrAlert::BASE_FAILED, 'Point-in-time recovery base backup failed', 'Databases', Severity::Critical);
        $types->register(PitrAlert::STOPPED, 'Point-in-time recovery stopped shipping', 'Databases', Severity::Critical);
        $types->register(PitrAlert::RECOVERED, 'Point-in-time recovery is fine again', 'Databases', Severity::Info);
        // Engine observability (falak-db observe, docs/plans/OBSERVABILITY.md phase 3).
        $types->register(RecordObservation::ALERT_SLOW, 'Slow queries spike on a database', 'Databases', Severity::Warning, 'Review slow queries');
        $types->register(RecordObservation::ALERT_LAG, 'Database replication lags', 'Databases', Severity::Warning, 'Check replication');
        $types->register(RecordObservation::ALERT_DEADLOCKS, 'Deadlocks on a database', 'Databases', Severity::Warning, 'Review database issues');
        $types->register(RecordObservation::ALERT_MEMORY, 'Database memory above 90% of its limit', 'Databases', Severity::Warning, 'Tune instance settings');

        // The agent's point-in-time recovery shipper (POST /agent/v1/requests/<type>).
        $requests = $this->app->make(AgentRequests::class);
        $requests->register('pitr.upload_urls', PitrUploadUrls::class);
        $requests->register('pitr.shipped', PitrShipped::class);
        $requests->register('pitr.gap', PitrGapReported::class);
        // What falak-db observe found, once a minute per server.
        $requests->register('db.observed', DatabaseObserved::class);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(AgentDatabasesReported::class, RecordInstanceHealth::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        // The private addresses a container is published on follow who uses it and how servers reach each other.
        Event::listen(ServiceLinked::class, [ConvergeInstanceNetwork::class, 'serviceLinked']);
        Event::listen(ServiceUnlinked::class, [ConvergeInstanceNetwork::class, 'serviceUnlinked']);
        Event::listen(SiteTargetsChanged::class, [ConvergeInstanceNetwork::class, 'siteTargetsChanged']);
        Event::listen(PrivateNetworkChanged::class, [ConvergeInstanceNetwork::class, 'privateNetworkChanged']);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationData::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueBackups)->everyMinute()->name('databases:backups')->withoutOverlapping();
            $schedule->job(new RunDueDrills)->everyTenMinutes()->name('databases:drills')->withoutOverlapping();
            $schedule->job(new MaintainInstances)->everyTenMinutes()->name('databases:maintenance')->withoutOverlapping();
            $schedule->job(new MaintainPitr)->everyMinute()->name('databases:pitr')->withoutOverlapping();
            $schedule->job(new MaintainPitr(prune: true))->hourly()->name('databases:pitr-prune')->withoutOverlapping();
            $schedule->job(new PruneObservations)->dailyAt('03:40')->name('databases:observations-prune')->withoutOverlapping();
            $schedule->job(new CheckBackupHealth)->everyFiveMinutes()->name('databases:backup-health')->withoutOverlapping()
                ->when(fn () => now()->minute % 30 !== 0);
            // Every 30 minutes the same check also probes the storage providers.
            $schedule->job(new CheckBackupHealth(probe: true))->everyThirtyMinutes()->name('databases:backup-health-probe')->withoutOverlapping();
        });
    }
}
