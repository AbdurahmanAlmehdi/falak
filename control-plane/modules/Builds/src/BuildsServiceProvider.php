<?php

namespace Falak\Builds;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Builds\Application\Actions\InstallServerBuilder;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Application\Console\RegistryIdleCommand;
use Falak\Builds\Application\Console\RegistryPruneCommand;
use Falak\Builds\Application\Jobs\ExpireBuilds;
use Falak\Builds\Application\Jobs\PruneArtifacts;
use Falak\Builds\Application\Jobs\PruneRegistry;
use Falak\Builds\Application\Listeners\ManageServerBuilders;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Policies\BuildPolicy;
use Falak\Builds\Http\Channels\BuildChannel;
use Falak\Builds\Infrastructure\Artifacts\LocalArtifactStorage;
use Falak\Builds\Infrastructure\Artifacts\S3ArtifactStorage;
use Falak\Builds\Infrastructure\Artifacts\SigV4Presigner;
use Falak\Builds\Infrastructure\EloquentBuildService;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Events\SiteDeleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class BuildsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        BuildService::class => EloquentBuildService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/builds.php', 'builds');

        $this->app->singleton(ArtifactStorage::class, function ($app) {
            $config = (array) config('builds.artifacts');

            if (($config['driver'] ?? 'local') === 's3') {
                $s3 = (array) $config['s3'];

                return new S3ArtifactStorage(
                    new SigV4Presigner((string) $s3['key'], (string) $s3['secret'], (string) ($s3['region'] ?: 'us-east-1')),
                    $app->make(HttpFactory::class),
                    (string) ($s3['endpoint'] ?: 'https://s3.'.($s3['region'] ?: 'us-east-1').'.amazonaws.com'),
                    (string) $s3['bucket'],
                    (string) $s3['prefix'],
                    (bool) $s3['path_style'],
                );
            }

            return new LocalArtifactStorage((string) $config['local']['root'], (string) $config['local']['url']);
        });
    }

    protected function bootModule(): void
    {
        Gate::policy(Build::class, BuildPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(BuildPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View builds, build logs and builders', 'builds');
        $registry->register(BuildPolicy::MANAGE, [Role::Admin, Role::Developer], 'Cancel builds and manage builders', 'builds');

        $this->app->make(AlertTypes::class)->register('builds.failed', 'Build failed', 'Builds', Severity::Warning);

        Event::listen(ServerProvisioned::class, [ManageServerBuilders::class, 'provisioned']);
        Event::listen(AgentFactsReported::class, [ManageServerBuilders::class, 'facts']);
        Event::listen(ServerDeleted::class, [ManageServerBuilders::class, 'serverDeleted']);
        Event::listen(OrganizationDeleted::class, [ManageServerBuilders::class, 'organizationDeleted']);
        Event::listen(SiteDeleted::class, [ManageServerBuilders::class, 'siteDeleted']);

        Broadcast::channel(BuildChannel::NAME, BuildChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Server builders installed before the build caps get them (once; a no-op afterwards).
            $schedule->call(fn () => $this->app->make(InstallServerBuilder::class)->refreshPending())->everyTenMinutes()->name('builds:refresh-builder-caps')->withoutOverlapping();
            $schedule->job(new ExpireBuilds)->everyMinute()->name('builds:expire')->withoutOverlapping();
            $schedule->job(new PruneArtifacts)->dailyAt('03:30')->name('builds:prune-artifacts')->withoutOverlapping();
            // After the artifacts prune: images of builds it pruned go too, unless a release may still run them.
            $schedule->job(new PruneRegistry)->dailyAt('03:45')->name('builds:prune-registry')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RegistryPruneCommand::class, RegistryIdleCommand::class]);
        }
    }
}
