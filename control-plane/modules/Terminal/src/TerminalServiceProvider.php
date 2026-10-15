<?php

namespace Falak\Terminal;

use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Contracts\AgentUploads;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\MemberRemoved;
use Falak\Identity\Events\MemberRoleChanged;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Events\ServerDeleted;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Application\Listeners\CloseSessionsOfDeletedServer;
use Falak\Terminal\Application\Listeners\DropRelayClientsOfMember;
use Falak\Terminal\Application\Listeners\HandleTerminalCommandOutcome;
use Falak\Terminal\Application\Listeners\SettleNetCapture;
use Falak\Terminal\Application\Listeners\SettleNetToolRun;
use Falak\Terminal\Application\Listeners\StreamTerminalOutput;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Domain\Policies\TerminalSessionPolicy;
use Falak\Terminal\Http\Channels\TerminalSessionChannel;
use Falak\Terminal\Infrastructure\AgentRequests\NetCaptureProgress;
use Falak\Terminal\Infrastructure\AgentRequests\NetCaptureUpload;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class TerminalServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/terminal.php', 'terminal');
    }

    protected function bootModule(): void
    {
        Gate::policy(TerminalSession::class, TerminalSessionPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('terminal.open', [Role::Admin], 'Open web terminal sessions on servers', 'terminal');
        $registry->register('terminal.attach', [Role::Admin], "Watch other members' shared terminal sessions", 'terminal');
        $registry->register('terminal.control', [Role::Admin], 'Type into and close terminal sessions opened by others', 'terminal');
        $registry->register('terminal.recordings.view', [Role::Admin], "Replay other members' terminal recordings", 'terminal');
        // Network tools (docs/plans/network/tools.md): read-only probes from servers and containers, audited.
        // Admins only by default, like terminal.open: a probe from inside a site's or database's network.
        $registry->register('network.tools', [Role::Admin], 'Run network tools (ping, traceroute, DNS, HTTP, TCP) from servers and containers', 'network');

        // Packet capture (docs/plans/network/capture.md): pcaps can hold secrets, so Admin only, audited, kept 24 h.
        $registry->register('network.capture', [Role::Admin], 'Capture network traffic on servers and containers (pcap downloads may contain sensitive data)', 'network');
        RateLimiter::for('net-captures', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        $this->app->make(AgentRequests::class)->register('net.capture.progress', NetCaptureProgress::class);
        $this->app->make(AgentUploads::class)->register('net.capture', NetCaptureUpload::class);

        RateLimiter::for('net-tools', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('terminal-input', function (Request $request) {
            // Throttling runs before route-model binding, so the parameter is usually still the raw id.
            $session = $request->route('session');
            $sessionKey = $session instanceof TerminalSession ? $session->id : (is_string($session) ? $session : '');

            return Limit::perMinute(1200)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$sessionKey);
        });

        // Synchronous on purpose (keystroke echo latency) — see StreamTerminalOutput.
        Event::listen(CommandOutputReceived::class, StreamTerminalOutput::class);
        Event::listen(CommandFinished::class, [HandleTerminalCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleTerminalCommandOutcome::class, 'handleFailed']);
        Event::listen(CommandFinished::class, [SettleNetToolRun::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [SettleNetToolRun::class, 'handleFailed']);
        Event::listen(CommandFinished::class, [SettleNetCapture::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [SettleNetCapture::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, CloseSessionsOfDeletedServer::class);
        Event::listen(MemberRemoved::class, DropRelayClientsOfMember::class);
        Event::listen(MemberRoleChanged::class, DropRelayClientsOfMember::class);

        Broadcast::channel(TerminalSessionChannel::NAME, TerminalSessionChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new SweepTerminalSessions)->everyMinute()->name('terminal:sweep')->withoutOverlapping();
        });
    }
}
