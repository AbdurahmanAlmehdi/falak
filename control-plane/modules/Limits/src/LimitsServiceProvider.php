<?php

namespace Falak\Limits;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Config\Contracts\Reconcilable;
use Falak\Fleet\Events\AgentServiceEventsReported;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Limits\Application\Listeners\RecordServiceEvents;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Limits\Contracts\ServiceHealth;
use Falak\Limits\Domain\Models\ServiceState;
use Falak\Limits\Events\ServiceOomKilled;
use Falak\Limits\Events\ServiceRestartLoop;
use Falak\Limits\Infrastructure\Config\LimitsReconciler;
use Falak\Limits\Infrastructure\DatabaseCapacity;
use Falak\Limits\Infrastructure\EloquentServiceHealth;
use Falak\Limits\Infrastructure\EnvironmentLimitDefaults;
use Falak\Limits\Infrastructure\FactsLimitValidator;
use Falak\Limits\Infrastructure\InMemoryCapacitySources;
use Falak\Servers\Events\ServerDeleted;
use Illuminate\Support\Facades\Event;

/**
 * Resource limits for every service: the shared {@see Contracts\ResourceLimits} value (stored by Sites and Processes
 * on their own rows), validation against servers, defaults per environment, servers' capacity views, and OOM kills /
 * restart loops reported by agents (ServiceOomKilled, ServiceRestartLoop).
 */
class LimitsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        CapacitySources::class => InMemoryCapacitySources::class,
        LimitValidator::class => FactsLimitValidator::class,
        LimitDefaults::class => EnvironmentLimitDefaults::class,
        ServiceHealth::class => EloquentServiceHealth::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/limits.php', 'limits');

        // falak.yaml: units.<u>.limits.
        $this->app->tag([LimitsReconciler::class], Reconcilable::TAG);
    }

    protected function bootModule(): void
    {
        $types = $this->app->make(AlertTypes::class);
        $types->register(ServiceOomKilled::ALERT_TYPE, 'Service killed for running out of memory', 'Limits', Severity::Critical);
        $types->register(ServiceRestartLoop::ALERT_TYPE, 'Service keeps restarting', 'Limits', Severity::Critical);

        // Database instances' limits (set by Databases) in servers' capacity views.
        $this->app->make(CapacitySources::class)->register(DatabaseCapacity::class);

        Event::listen(AgentServiceEventsReported::class, RecordServiceEvents::class);
        Event::listen(ServerDeleted::class, fn (ServerDeleted $event) => ServiceState::query()->where('server_id', $event->serverId)->delete());
        Event::listen(OrganizationDeleted::class, fn (OrganizationDeleted $event) => ServiceState::query()->where('organization_id', $event->organizationId)->delete());
    }
}
