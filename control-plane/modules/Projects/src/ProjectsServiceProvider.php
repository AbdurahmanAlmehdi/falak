<?php

namespace Falak\Projects;

use Falak\Config\Contracts\Reconcilable;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\DatabaseDeleted;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationCreated;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Kernel\Support\SharedProps;
use Falak\Projects\Application\Canvas\FalakNavigation;
use Falak\Projects\Application\Console\BackfillProjectsCommand;
use Falak\Projects\Application\Listeners\CreateDefaultProject;
use Falak\Projects\Application\Listeners\DeleteOrganizationProjects;
use Falak\Projects\Application\Listeners\PlaceCreatedServices;
use Falak\Projects\Contracts\ConfigEnvironments;
use Falak\Projects\Contracts\PreviewEnvironments;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Policies\ProjectPolicy;
use Falak\Projects\Infrastructure\ActionConfigEnvironments;
use Falak\Projects\Infrastructure\ActionPreviewEnvironments;
use Falak\Projects\Infrastructure\Config\ProjectsReconciler;
use Falak\Projects\Infrastructure\EloquentProjectDirectory;
use Falak\Projects\Infrastructure\ReferenceResolver;
use Falak\Sites\Events\ComposeServiceExtracted;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class ProjectsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ProjectDirectory::class => EloquentProjectDirectory::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        // Stateful per resolution run.
        VariableReferences::class => ReferenceResolver::class,
        // Uses the request-scoped audit log.
        PreviewEnvironments::class => ActionPreviewEnvironments::class,
        ConfigEnvironments::class => ActionConfigEnvironments::class,
    ];

    protected function bootModule(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        // falak.yaml's `environments` (Config, docs/plans/CONFIG_AS_CODE.md).
        $this->app->tag([ProjectsReconciler::class], Reconcilable::TAG);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(ProjectPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View projects, environments and their canvas', 'projects');
        $registry->register(ProjectPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, rename and delete projects and environments; arrange and create services on the canvas', 'projects');

        Event::listen(OrganizationCreated::class, CreateDefaultProject::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationProjects::class);
        Event::listen(SiteCreated::class, [PlaceCreatedServices::class, 'siteCreated']);
        Event::listen(SiteDeleted::class, [PlaceCreatedServices::class, 'siteDeleted']);
        Event::listen(DatabaseCreated::class, [PlaceCreatedServices::class, 'databaseCreated']);
        Event::listen(ComposeServiceExtracted::class, [PlaceCreatedServices::class, 'composeServiceExtracted']);
        Event::listen(DatabaseDeleted::class, [PlaceCreatedServices::class, 'databaseDeleted']);

        // app(), not $this->app: under the FrankenPHP worker the latter is the base app, not the request sandbox.
        $this->app->make(SharedProps::class)->register('falak', fn (Request $request) => app(FalakNavigation::class)->for($request));

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillProjectsCommand::class]);
        }
    }
}
