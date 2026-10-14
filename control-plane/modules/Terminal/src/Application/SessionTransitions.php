<?php

namespace Falak\Terminal\Application;

use Falak\Identity\Contracts\AuditLog;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Events\TerminalSessionClosed;
use Falak\Terminal\Events\TerminalSessionUpdated;
use Falak\Terminal\Infrastructure\RelayKicker;
use Illuminate\Support\Carbon;

/**
 * State changes of a session; every change is broadcast to live viewers, closing announces
 * TerminalSessionClosed exactly once.
 */
final class SessionTransitions
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly RelayKicker $relay,
    ) {}

    public function markOpen(TerminalSession $session, ?Carbon $startedAt = null): void
    {
        $dirty = false;

        if ($session->started_at === null && $startedAt !== null) {
            $session->started_at = $startedAt;
            $dirty = true;
        }

        if ($session->status === SessionStatus::Opening) {
            $session->status = SessionStatus::Open;
            $session->last_activity_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $session->save();

            if ($session->wasChanged('status')) {
                $this->broadcast($session);
            }
        }
    }

    /**
     * @return bool whether the session was live (and is now closed)
     */
    public function close(TerminalSession $session, string $reason, ?int $exitCode = null, ?string $error = null, bool $failed = false): bool
    {
        if (! $session->isLive()) {
            return false;
        }

        $session->forceFill([
            'status' => $failed ? SessionStatus::Failed : SessionStatus::Closed,
            'close_reason' => $reason,
            'exit_code' => $exitCode,
            'error' => $error !== null ? mb_substr($error, 0, 250) : null,
            'closed_at' => now(),
        ])->save();

        $this->broadcast($session);
        // Relayed clients go too (the agent's exit normally ends them; this covers an agent that can't tell the relay).
        $this->relay->kick($session);

        $this->audit->record('terminal.session_closed', 'terminal_session', $session->id, [
            'server_id' => $session->server_id,
            'reason' => $reason,
            'exit_code' => $exitCode,
        ], $session->organization_id);

        TerminalSessionClosed::dispatch($session->id, $session->organization_id, $session->server_id, $session->user_id, $reason);

        return true;
    }

    /**
     * @param  int|null  $onEpoch  channel generation to announce on (defaults to the current one)
     */
    public function broadcast(TerminalSession $session, ?int $onEpoch = null): void
    {
        TerminalSessionUpdated::dispatch(
            $session->id,
            $session->status->value,
            $session->close_reason,
            $session->exit_code,
            $session->error,
            $session->cols,
            $session->rows,
            $session->shared,
            $onEpoch ?? $session->channel_epoch,
            $session->channel_epoch,
        );
    }
}
