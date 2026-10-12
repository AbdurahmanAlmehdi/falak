<?php

namespace Falak\Functions;

use Falak\Config\Contracts\Reconcilable;
use Falak\Deployments\Contracts\FunctionSources;
use Falak\Functions\Application\Listeners\ForgetDeletedFunction;
use Falak\Functions\Infrastructure\Config\FunctionSchedulesReconciler;
use Falak\Functions\Infrastructure\Config\FunctionsReconciler;
use Falak\Functions\Infrastructure\FunctionsCapacity;
use Falak\Functions\Infrastructure\FunctionScheduleSources;
use Falak\Functions\Infrastructure\StoredFunctionSources;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Processes\Contracts\ScheduleSources;
use Falak\Sites\Events\SiteDeleted;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Cloud Functions (docs/plans/FUNCTIONS.md): code written in Falak, versioned, deployed by Deployments to the servers'
 * function gateway, which starts instances on demand and scales them to zero.
 */
class FunctionsServiceProvider extends ModuleServiceProvider
{
    public const VIEW = 'functions.view';

    public const CREATE = 'functions.create';

    public const EDIT = 'functions.edit';

    public const DEPLOY = 'functions.deploy';

    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        FunctionSources::class => StoredFunctionSources::class,
        ScheduleSources::class => FunctionScheduleSources::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/functions.php', 'functions');
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(self::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View functions, their code and versions', 'functions');
        $registry->register(self::CREATE, [Role::Admin, Role::Developer], 'Create functions', 'functions');
        $registry->register(self::EDIT, [Role::Admin, Role::Developer], 'Edit function code (drafts)', 'functions');
        $registry->register(self::DEPLOY, [Role::Admin, Role::Developer], 'Deploy function versions, roll back and change scaling', 'functions');

        Event::listen(SiteDeleted::class, ForgetDeletedFunction::class);
        // falak.yaml's function units (Config, docs/plans/CONFIG_AS_CODE.md): settings and code (phase A), schedules (B).
        $this->app->tag([FunctionsReconciler::class, FunctionSchedulesReconciler::class], Reconcilable::TAG);
        $this->app->make(CapacitySources::class)->register(FunctionsCapacity::class);

        // Code is stored exactly as written: no trimmed lines or emptied files.
        $code = fn (Request $request) => $request->is('sites/*/function/draft', 'sites/*/function/deploy', 'api/v1/functions/*/deploy');
        TrimStrings::skipWhen($code);
        ConvertEmptyStringsToNull::skipWhen($code);
    }
}
