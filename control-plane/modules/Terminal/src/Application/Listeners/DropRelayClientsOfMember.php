<?php

namespace Falak\Terminal\Application\Listeners;

use Falak\Identity\Events\MemberRemoved;
use Falak\Identity\Events\MemberRoleChanged;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Infrastructure\RelayKicker;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A member left the organization or changed role: relay sockets are authorized only when they connect, so drop the
 * ones that may no longer be allowed. Their own sessions lose every client; shared sessions keep only their owner's.
 * Whoever still has access reconnects with a fresh ticket (the session page does it on its own).
 */
final class DropRelayClientsOfMember implements ShouldQueue
{
    public function __construct(private readonly RelayKicker $relay) {}

    public function handle(MemberRemoved|MemberRoleChanged $event): void
    {
        if (! (bool) config('terminal.relay.enabled', false)) {
            return;
        }

        TerminalSession::query()
            ->where('organization_id', $event->organizationId)
            ->whereIn('status', SessionStatus::live())
            ->where('relayed', true)
            ->where(fn ($query) => $query->where('user_id', $event->userId)->orWhere('shared', true))
            ->each(fn (TerminalSession $session) => $this->relay->kick($session, $session->user_id === $event->userId ? null : $session->user_id));
    }
}
