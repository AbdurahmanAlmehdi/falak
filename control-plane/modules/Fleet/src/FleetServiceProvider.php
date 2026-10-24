<?php

namespace Falak\Fleet;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Application\Actions\IssueInstallToken;
use Falak\Fleet\Application\Console\AgentsCommand;
use Falak\Fleet\Application\Console\CaInitCommand;
use Falak\Fleet\Application\Console\CaServerCertificateCommand;
use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Application\Listeners\TrackAgentUpgrades;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\AgentUploads;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Fleet\Contracts\ServerCertificates;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Fleet\Events\AgentUpgradeFailed;
use Falak\Fleet\Events\AgentUpgradeSucceeded;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Http\Channels\CommandChannel;
use Falak\Fleet\Http\Middleware\AuthenticateAgent;
use Falak\Fleet\Infrastructure\AgentBinaries;
use Falak\Fleet\Infrastructure\AgentRequestRegistry;
use Falak\Fleet\Infrastructure\AgentUploadRegistry;
use Falak\Fleet\Infrastructure\CaServerCertificates;
use Falak\Fleet\Infrastructure\EloquentAgentDirectory;
use Falak\Fleet\Infrastructure\EloquentAgentUpgrades;
use Falak\Fleet\Infrastructure\FleetAgentGateway;
use Falak\Fleet\Infrastructure\FleetEnrollment;
use Falak\Fleet\Infrastructure\InstallScript;
use Falak\Fleet\Infrastructure\PanelUrls;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Fleet\Infrastructure\Signals\CommandSignal;
use Falak\Fleet\Infrastructure\Signals\DatabaseCommandSignal;
use Falak\Fleet\Infrastructure\Signals\RedisCommandSignal;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class FleetServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AgentGateway::class => FleetAgentGateway::class,
        AgentDirectory::class => EloquentAgentDirectory::class,
        ServerCertificates::class => CaServerCertificates::class,
        AgentRequests::class => AgentRequestRegistry::class,
        AgentUploads::class => AgentUploadRegistry::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/fleet.php', 'fleet');

        // Request-scoped: depends on the (request-scoped) AuditLog.
        $this->app->scoped(Enrollment::class, FleetEnrollment::class);

        $this->app->singleton(ProtocolSchemas::class, fn () => new ProtocolSchemas((string) config('fleet.schemas_path')));

        $this->app->singleton(CertificateAuthorityService::class, fn ($app) => new CertificateAuthorityService(
            $app->make('cache.store'),
            (string) config('fleet.ca_path'),
            (int) config('fleet.ca_validity_years', 10),
            (int) config('fleet.cert_validity_days', 90),
        ));

        $this->app->bind(AgentUpgrades::class, EloquentAgentUpgrades::class);

        $this->app->singleton(PanelUrls::class, fn ($app) => new PanelUrls(
            $app->make('url'),
            config('fleet.panel_url'),
            config('fleet.api_url'),
            config('fleet.agent.download_url'),
        ));

        $this->app->singleton(AgentBinaries::class, fn () => new AgentBinaries((string) config('fleet.agent.binaries_path'), config('fleet.agent.version')));

        $this->app->bind(InstallScript::class, fn ($app) => new InstallScript(
            $app->make(PanelUrls::class),
            $app->make(AgentBinaries::class),
            (array) config('fleet.agent.checksums', []),
        ));

        $this->app->bind(IssueInstallToken::class, fn ($app) => new IssueInstallToken(
            $app->make(PanelUrls::class),
            $app->make(AuditLog::class),
            (int) config('fleet.install_token_ttl_minutes', 1440),
        ));

        $this->app->singleton(CommandSignal::class, fn ($app) => config('fleet.wake_driver') === 'redis'
            ? new RedisCommandSignal(
                $app->make('redis'),
                (string) config('fleet.wake_redis_connection', 'default'),
                // Idle long-polls release their database connection (reconnected lazily on the next check),
                // unless a transaction is open (tests, or a caller that wraps the poll).
                function () use ($app): void {
                    $db = $app->make('db');
                    if ($db->getConnections() !== [] && $db->connection()->transactionLevel() === 0) {
                        $db->disconnect();
                    }
                },
            )
            : new DatabaseCommandSignal((int) config('fleet.database_poll_interval_ms', 500)));
    }

    protected function bootModule(): void
    {
        Route::group([], $this->modulePath().'/routes/install.php');

        RateLimiter::for('fleet-enroll', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
        // Agent requests (POST /agent/v1/requests/{type}): per authenticated agent and type.
        RateLimiter::for('fleet-agent-requests', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_requests_per_minute', 120))
            ->by($request->attributes->get(AuthenticateAgent::AGENT)?->id.':'.$request->route('type')));
        // Agent uploads (POST /agent/v1/uploads/{type}/{id}): chunks per authenticated agent.
        RateLimiter::for('fleet-agent-uploads', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_uploads_per_minute', 300))
            ->by((string) $request->attributes->get(AuthenticateAgent::AGENT)?->id));
        // POST /agent/v1/heartbeat: normal cadence is every 15 s (4/min); 10 allows retries.
        RateLimiter::for('fleet-agent-heartbeat', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_heartbeat_per_minute', 10))
            ->by((string) $request->attributes->get(AuthenticateAgent::AGENT)?->id));
        // GET /agent/v1/commands: 30 s long-poll gives 2/min normally; 10 covers reconnects.
        RateLimiter::for('fleet-agent-commands', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_commands_per_minute', 10))
            ->by((string) $request->attributes->get(AuthenticateAgent::AGENT)?->id));
        // POST /agent/v1/commands/{command}/events: batched during deploys; 60/min per agent.
        RateLimiter::for('fleet-agent-events', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_events_per_minute', 60))
            ->by((string) $request->attributes->get(AuthenticateAgent::AGENT)?->id));
        // POST /agent/v1/insights: periodic cron sink; 20/min per agent.
        RateLimiter::for('fleet-agent-insights', fn (Request $request) => Limit::perMinute((int) config('fleet.agent_insights_per_minute', 20))
            ->by((string) $request->attributes->get(AuthenticateAgent::AGENT)?->id));

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('fleet.commands.view', [Role::Admin, Role::Developer, Role::Viewer], 'View agent command output', 'fleet');
        $registry->register('fleet.agents.manage', [Role::Admin], 'Issue install commands and revoke agents', 'fleet');

        $types = $this->app->make(AlertTypes::class);
        $types->register(AgentRevoked::ALERT_TYPE, 'Server agent revoked', 'Fleet', Severity::Warning);
        $types->register(AgentUpgradeFailed::ALERT_TYPE, 'Agent upgrade failed', 'Fleet', Severity::Warning, 'Update agent');
        $types->register(AgentUpgradeSucceeded::ALERT_TYPE, 'Agent upgraded after a failure', 'Fleet', Severity::Info);

        Event::listen(CommandFinished::class, [TrackAgentUpgrades::class, 'finished']);
        Event::listen(CommandFailed::class, [TrackAgentUpgrades::class, 'failed']);
        Event::listen(AgentFactsReported::class, [TrackAgentUpgrades::class, 'facts']);

        Broadcast::channel(CommandChannel::NAME, CommandChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new SweepFleet)->everyMinute()->name('fleet:sweep')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CaInitCommand::class, CaServerCertificateCommand::class, AgentsCommand::class]);
        }
    }
}
