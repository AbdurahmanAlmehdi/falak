<?php

namespace Falak\Previews;

use Falak\Config\Contracts\Reconcilable;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\RestoreFinished;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Previews\Application\Jobs\CleanupIdlePreviews;
use Falak\Previews\Application\Listeners\HandlePreviewEvents;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Previews\Domain\Policies\PreviewPolicy;
use Falak\Previews\Infrastructure\Config\PreviewsReconciler;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestCommented;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class PreviewsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/previews.php', 'previews');
    }

    protected function bootModule(): void
    {
        Gate::policy(Preview::class, PreviewPolicy::class);
        Gate::policy(PreviewSettings::class, PreviewPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(PreviewPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View pull request previews, their URLs and access credentials', 'previews');
        $registry->register(PreviewPolicy::MANAGE, [Role::Admin, Role::Developer], 'Configure previews, approve previews of forks, redeploy and delete previews', 'previews');

        // falak.yaml's `previews` (Config, docs/plans/CONFIG_AS_CODE.md).
        $this->app->tag([PreviewsReconciler::class], Reconcilable::TAG);

        Event::listen(PullRequestOpened::class, [HandlePreviewEvents::class, 'opened']);
        Event::listen(PullRequestUpdated::class, [HandlePreviewEvents::class, 'updated']);
        Event::listen(PullRequestClosed::class, [HandlePreviewEvents::class, 'closed']);
        Event::listen(PullRequestCommented::class, [HandlePreviewEvents::class, 'commented']);
        Event::listen(DatabaseCreated::class, [HandlePreviewEvents::class, 'databaseCreated']);
        Event::listen(RestoreFinished::class, [HandlePreviewEvents::class, 'restoreFinished']);
        Event::listen(CommandFinished::class, [HandlePreviewEvents::class, 'commandFinished']);
        Event::listen(CommandFailed::class, [HandlePreviewEvents::class, 'commandFailed']);
        Event::listen(DeploymentSucceeded::class, [HandlePreviewEvents::class, 'deploymentSucceeded']);
        Event::listen(DeploymentFailed::class, [HandlePreviewEvents::class, 'deploymentFailed']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new CleanupIdlePreviews)->hourly()->name('previews:cleanup')->withoutOverlapping();
        });
    }
}
