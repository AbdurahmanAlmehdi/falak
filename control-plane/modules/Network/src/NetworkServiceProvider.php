<?php

namespace Falak\Network;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Application\Actions\RecordNetworkObservation;
use Falak\Network\Application\Listeners\ForgetDeletedServer;
use Falak\Network\Application\Listeners\HandleCommandOutcome;
use Falak\Network\Application\Listeners\ReconfigureConnections;
use Falak\Network\Application\Listeners\SeedFirewallOnProvisioning;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Contracts\PrivateNetwork as PrivateNetworkContract;
use Falak\Network\Contracts\WebOriginPolicy;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Policies\FirewallRulePolicy;
use Falak\Network\Domain\Policies\PrivateNetworkPolicy;
use Falak\Network\Events\FirewallApplied;
use Falak\Network\Events\FirewallApplyFailed;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Network\Infrastructure\AgentRequests\NetworkObserved;
use Falak\Network\Infrastructure\EloquentPrivateNetwork;
use Falak\Network\Infrastructure\NoWebOriginPolicy;
use Falak\Network\Infrastructure\QueuedFirewalls;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class NetworkServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        PrivateNetworkContract::class => EloquentPrivateNetwork::class,
        Firewalls::class => QueuedFirewalls::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/network.php', 'network');
        // Edge replaces this for servers behind Cloudflare (origin lock-down).
        $this->app->singletonIf(WebOriginPolicy::class, NoWebOriginPolicy::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(PrivateNetwork::class, PrivateNetworkPolicy::class);
        Gate::policy(FirewallRule::class, FirewallRulePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('network.view', [Role::Admin, Role::Developer, Role::Viewer], 'View firewalls, private networks and load balancers', 'network');
        $registry->register('network.manage', [Role::Admin, Role::Developer], 'Manage firewall rules and private networks', 'network');

        $types = $this->app->make(AlertTypes::class);
        $types->register(FirewallApplyFailed::ALERT_TYPE, 'Firewall apply failed', 'Network', Severity::Critical);
        $types->register(FirewallApplied::ALERT_TYPE, 'Firewall applied again', 'Network', Severity::Info);
        // Connections (docs/plans/network/connections.md).
        $types->register(RecordNetworkObservation::ALERT_CONNTRACK, 'Connection tracking table near full', 'Network', Severity::Warning, 'Review connections');
        $types->register(RecordNetworkObservation::ALERT_EXPOSED, 'Port reachable from the internet', 'Network', Severity::Warning, 'Review open ports');
        $types->register(RecordNetworkObservation::ALERT_WIREGUARD, 'WireGuard peer not connected', 'Network', Severity::Warning, 'Check the private network');
        $types->register(RecordNetworkObservation::ALERT_LATENCY, 'Latency spike or unreachable peer', 'Network', Severity::Warning, 'Review latency');

        // The agent's network collector, once a minute per server.
        $this->app->make(AgentRequests::class)->register('net.observed', NetworkObserved::class);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerProvisioned::class, SeedFirewallOnProvisioning::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(PrivateNetworkChanged::class, [ReconfigureConnections::class, 'handleNetworkChanged']);
        Event::listen(AgentVersionChanged::class, [ReconfigureConnections::class, 'handleAgentVersion']);
    }
}
