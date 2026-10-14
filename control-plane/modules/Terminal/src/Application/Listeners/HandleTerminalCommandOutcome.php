<?php

namespace Falak\Terminal\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Terminal\Application\SessionTransitions;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The terminal.open command ended: the shell exited, the agent closed it (idle / timeout / close request),
 * or it failed to start.
 */
final class HandleTerminalCommandOutcome implements ShouldQueue
{
    public const REASONS = ['exited', 'closed', 'idle', 'timeout'];

    public function __construct(
        private readonly SessionTransitions $transitions,
        private readonly AgentGateway $agents,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'terminal.open' || ! $session = $this->session($event->commandId, $event->organizationId)) {
            return;
        }

        $this->forgetRelayToken($session);
        $reason = $event->result['reason'] ?? 'exited';
        // The structured result is authoritative: idle/closed sessions have no shell exit code.
        $exitCode = $event->result !== null ? (isset($event->result['exit_code']) ? (int) $event->result['exit_code'] : null) : $event->exitCode;

        $this->transitions->close($session, in_array($reason, self::REASONS, true) ? $reason : 'exited', $exitCode);
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type !== 'terminal.open' || ! $session = $this->session($event->commandId, $event->organizationId)) {
            return;
        }

        $this->forgetRelayToken($session);
        $reason = $event->result['reason'] ?? null;

        // A shell exiting non-zero is a normal end of session, not a failure.
        if (in_array($reason, self::REASONS, true)) {
            $exitCode = isset($event->result['exit_code']) ? (int) $event->result['exit_code'] : $event->exitCode;
            $this->transitions->close($session, $reason, $exitCode);

            return;
        }

        if ($event->status === 'timed_out') {
            $this->transitions->close($session, 'timeout', $event->exitCode);

            return;
        }

        $this->transitions->close($session, 'failed', $event->exitCode, $event->error ?: "Command {$event->status}", failed: true);
    }

    /** The agent token was only good for this session; don't keep it in the command's stored payload. */
    private function forgetRelayToken(TerminalSession $session): void
    {
        if ($session->isRelayed() && $session->command_id !== null) {
            $this->agents->forgetSecrets($session->command_id, ['relay_token']);
        }
    }

    private function session(string $commandId, string $organizationId): ?TerminalSession
    {
        return TerminalSession::query()
            ->where('command_id', $commandId)
            ->where('organization_id', $organizationId)
            ->first();
    }
}
