<?php

namespace Falak\Network;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Application\Actions\RecordNetworkObservation;
use Falak\Network\Application\ConfigureEbpf;
use Falak\Network\Application\Jobs\CheckEbpfAlerts;
use Falak\Network\Application\Jobs\ConvergeProtection;
use Falak\Network\Application\Listeners\ForgetDeletedOrganization;
use Falak\Network\Application\Listeners\ForgetDeletedServer;
use Falak\Network\Application\Listeners\HandleCommandOutcome;
use Falak\Network\Application\Listeners\HandleEbpfOutcome;
use Falak\Network\Application\Listeners\HandleProtectionOutcome;
use Falak\Network\Application\Listeners\HandleTailscaleOutcome;
use Falak\Network\Application\Listeners\ReconfigureConnections;
use Falak\Network\Application\Listeners\ReconvergeProtection;
use Falak\Network\Application\Listeners\SeedFirewallOnProvisioning;
use Falak\Network\Application\ProtectionGuards;
use Falak\Network\Application\Tailscale;
use Falak\Network\Contracts\BlockableAddresses;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Contracts\PrivateNetwork as PrivateNetworkContract;
use Falak\Network\Contracts\Tailnet;
use Falak\Network\Contracts\WebOriginPolicy;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Policies\FirewallRulePolicy;
use Falak\Network\Domain\Policies\PrivateNetworkPolicy;
use Falak\Network\Events\FirewallApplied;
use Falak\Network\Events\FirewallApplyFailed;
use Falak\Network\Events\IpBlockSuspended;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Network\Infrastructure\AgentRequests\NetworkObserved;
use Falak\Network\Infrastructure\AgentRequests\ProtectionObserved;
use Falak\Network\Infrastructure\EloquentPrivateNetwork;
use Falak\Network\Infrastructure\NoWebOriginPolicy;
use Falak\Network\Infrastructure\QueuedFirewalls;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Servers\Events\ServerUpdated;
use Illuminate\Console\Scheduling\Schedule;
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
        Tailnet::class => Tailscale::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/network.php', 'network');
        // Edge replaces this for servers behind Cloudflare (origin lock-down).
        $this->app->singletonIf(WebOriginPolicy::class, NoWebOriginPolicy::class);
        // Not a singleton: the guards memoize the organization's protected addresses for one request / job.
        $this->app->bind(BlockableAddresses::class, ProtectionGuards::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(PrivateNetwork::class, PrivateNetworkPolicy::class);
        Gate::policy(FirewallRule::class, FirewallRulePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('network.view', [Role::Admin, Role::Developer, Role::Viewer], 'View firewalls, private networks and load balancers', 'network');
        $registry->register('network.manage', [Role::Admin, Role::Developer], 'Manage firewall rules and private networks', 'network');
        $registry->register('network.protect', [Role::Admin], 'Block IP addresses and configure SSH auto-ban', 'network');
        $registry->register('network.ebpf', [Role::Admin], 'Turn the eBPF probe (TCP and DNS insights) on or off on servers', 'network');
        $registry->register('network.tailscale', [Role::Admin], 'Manage the Tailscale auth key and put servers on the tailnet', 'network');

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
        $types->register(IpBlockSuspended::ALERT_TYPE, 'Block lifted: it covers a protected address', 'Network', Severity::Warning);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerProvisioned::class, SeedFirewallOnProvisioning::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(PrivateNetworkChanged::class, [ReconfigureConnections::class, 'handleNetworkChanged']);
        Event::listen(AgentVersionChanged::class, [ReconfigureConnections::class, 'handleAgentVersion']);

        // Protection (docs/plans/network/protection.md).
        $this->app->make(AgentRequests::class)->register('net.protection.observed', ProtectionObserved::class);
        Event::listen(CommandFinished::class, [HandleProtectionOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleProtectionOutcome::class, 'handleFailed']);
        Event::listen(AgentVersionChanged::class, [HandleProtectionOutcome::class, 'handleUpgrade']);
        Event::listen(ServerProvisioned::class, [ReconvergeProtection::class, 'provisioned']);
        Event::listen(ServerUpdated::class, [ReconvergeProtection::class, 'updated']);
        Event::listen(PrivateNetworkChanged::class, [ReconvergeProtection::class, 'network']);
        Event::listen(AgentCameOnline::class, [ReconvergeProtection::class, 'online']);
        // eBPF (docs/plans/network/ebpf.md).
        $types->register(CheckEbpfAlerts::ALERT_DNS, 'DNS failures spike', 'Network', Severity::Warning, 'Review DNS');
        $types->register(CheckEbpfAlerts::ALERT_RETRANSMITS, 'TCP retransmit spike', 'Network', Severity::Warning, 'Review TCP health');
        Event::listen(CommandFinished::class, [HandleEbpfOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleEbpfOutcome::class, 'handleFailed']);
        Event::listen(AgentVersionChanged::class, [HandleEbpfOutcome::class, 'handleUpgrade']);
        Event::listen(AgentCameOnline::class, [HandleEbpfOutcome::class, 'handleOnline']);

        // Tailscale (docs/plans/TAILSCALE.md).
        Event::listen(CommandFinished::class, [HandleTailscaleOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleTailscaleOutcome::class, 'handleFailed']);
        Event::listen(OrganizationDeleted::class, ForgetDeletedOrganization::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => IpBlock::query()->where('expires_at', '<', now()->subDays((int) config('network.protection.retention_days', 7)))->delete())
                ->dailyAt('03:40')->name('network:prune-blocks')->withoutOverlapping();
            $schedule->job(new ConvergeProtection)->everyTenMinutes()->name('network:converge-protection')->withoutOverlapping();
            $schedule->job(new CheckEbpfAlerts)->everyMinute()->name('network:ebpf-alerts')->withoutOverlapping();
            $schedule->call(fn () => app(ConfigureEbpf::class)->converge())->everyTenMinutes()->name('network:converge-ebpf')->withoutOverlapping();
        });
    }
}
