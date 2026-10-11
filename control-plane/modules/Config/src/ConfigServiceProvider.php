<?php

namespace Falak\Config;

use Falak\Config\Application\Listeners\FollowReleases;
use Falak\Config\Application\Listeners\PlanPullRequest;
use Falak\Config\Application\Listeners\RunOnPush;
use Falak\Config\Application\Planning\Reconcilers;
use Falak\Config\Application\Planning\RiskRules;
use Falak\Config\Application\Validation\SchemaValidator;
use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Domain\Policies\ConfigPolicy;
use Falak\Config\Infrastructure\EloquentConfigSources;
use Falak\Config\Infrastructure\EloquentManagedResources;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\ReleaseActivated;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Config as code (`falak.yaml`, docs/plans/CONFIG_AS_CODE.md): the schema (contracts/config/v1.schema.json), the
 * reader, the planner, the run history and the ownership of managed fields. Other modules take part by implementing
 * {@see Contracts\Reconcilable} for the sections of the file they own (tagged {@see Contracts\Reconcilable::TAG}).
 */
class ConfigServiceProvider extends ModuleServiceProvider
{
    /** Path of the v1 JSON Schema, relative to the repository root. */
    public const SCHEMA = 'contracts/config/v1.schema.json';

    /** The schema's $id. */
    public const SCHEMA_ID = 'https://falak.sh/schema/v1.json';

    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ConfigSources::class => EloquentConfigSources::class,
        ManagedResources::class => EloquentManagedResources::class,
        Reconcilers::class => Reconcilers::class,
        RiskRules::class => RiskRules::class,
        SchemaValidator::class => SchemaValidator::class,
    ];

    /** contracts/config (the schema and the risky-change rules). */
    public static function contractsPath(): string
    {
        $path = function_exists('config') && app()->bound('config') ? config('config_as_code.contracts_path') : null;

        return rtrim(is_string($path) && $path !== '' ? $path : dirname(__DIR__, 4).'/contracts/config', '/');
    }

    public static function schemaPath(): string
    {
        return self::contractsPath().'/v1.schema.json';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/config_as_code.php', 'config_as_code');
    }

    protected function bootModule(): void
    {
        Gate::policy(ConfigSource::class, ConfigPolicy::class);
        Gate::policy(ConfigRun::class, ConfigPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(ConfigPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View config as code: validate and plan falak.yaml, export it, read config runs', 'config');
        $registry->register(ConfigPolicy::APPLY, [Role::Admin, Role::Developer], 'Apply falak.yaml (config runs from the CLI or the dashboard), cancel runs, connect the config source', 'config');
        $registry->register(ConfigPolicy::APPROVE, [Role::Admin], 'Approve config runs with risky changes', 'config');
        $registry->register(ConfigPolicy::DESTROY, [Role::Admin], 'Apply config runs that delete databases and volumes removed from the file (--allow-destroy)', 'config');

        Event::listen(PushReceived::class, RunOnPush::class);
        Event::listen(PullRequestOpened::class, [PlanPullRequest::class, 'opened']);
        Event::listen(PullRequestUpdated::class, [PlanPullRequest::class, 'updated']);
        Event::listen(ReleaseActivated::class, [FollowReleases::class, 'activated']);
        Event::listen(DeploymentFailed::class, [FollowReleases::class, 'failed']);
    }
}
