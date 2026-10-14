<?php

namespace Falak\Terminal\Application\Jobs;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Terminal\Application\Actions\CloseSession;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Control-plane fallback for session limits (the agent enforces them too, but may be offline):
 * idle sessions, sessions past the maximum duration and sessions the agent never opened.
 *
 * Relayed sessions are not closed for idleness while their terminal.open command still runs: keystrokes through the
 * relay never reach the control plane (last_activity_at), and the agent's own idle clock sees them.
 */
final class SweepTerminalSessions implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const GRACE_SECONDS = 60;

    public const OPEN_DEADLINE_SECONDS = 120;

    public function handle(CloseSession $close, AgentGateway $agents): void
    {
        $maxDuration = (int) config('terminal.max_duration', 3600);

        TerminalSession::query()
            ->whereIn('status', SessionStatus::live())
            ->orderBy('created_at')
            ->each(function (TerminalSession $session) use ($close, $agents, $maxDuration) {
                if ($session->status === SessionStatus::Opening && $session->created_at->lt(now()->subSeconds(self::OPEN_DEADLINE_SECONDS))) {
                    $close($session, 'failed', failed: true, error: 'The agent did not open the session in time.');

                    return;
                }

                if ($session->created_at->lt(now()->subSeconds($maxDuration + self::GRACE_SECONDS))) {
                    $close($session, 'timeout');

                    return;
                }

                $lastActivity = $session->last_activity_at ?? $session->created_at;

                if ($session->idle_timeout_s > 0 && $lastActivity->lt(now()->subSeconds($session->idle_timeout_s + self::GRACE_SECONDS))
                    && ! ($session->isRelayed() && $this->stillRunning($agents, $session))) {
                    $close($session, 'idle');
                }
            });
    }

    private function stillRunning(AgentGateway $agents, TerminalSession $session): bool
    {
        if ($session->command_id === null) {
            return false;
        }

        try {
            return ! $agents->status($session->command_id)->status->isTerminal();
        } catch (Throwable) {
            return false;
        }
    }
}
