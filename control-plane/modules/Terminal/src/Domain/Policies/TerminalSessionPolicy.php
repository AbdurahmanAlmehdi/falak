<?php

namespace Falak\Terminal\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may watch, type into, close, share and replay a terminal session.
 *
 * - owner: everything while they hold terminal.open (recordings: always)
 * - others: attach to shared sessions with terminal.attach; type with terminal.control;
 *   close any session with terminal.control; replay with terminal.recordings.view
 */
final class TerminalSessionPolicy
{
    public const PERMISSIONS = ['terminal.open', 'terminal.attach', 'terminal.control', 'terminal.recordings.view'];

    public function __construct(private readonly OrganizationAccess $access) {}

    /** Attach to the live session (page + presence channel). */
    public function view(Authenticatable $user, TerminalSession $session): Response
    {
        return $this->visible($user, $session) ?? ($this->canView($user, $session) ? Response::allow() : Response::deny());
    }

    /** Send keystrokes / resize. */
    public function type(Authenticatable $user, TerminalSession $session): Response
    {
        return $this->visible($user, $session) ?? ($this->canType($user, $session) ? Response::allow() : Response::deny('You can watch this session but not type into it.'));
    }

    public function close(Authenticatable $user, TerminalSession $session): Response
    {
        if ($denied = $this->visible($user, $session)) {
            return $denied;
        }

        $allowed = ($this->owns($user, $session) && $this->ownerMayOpen($user, $session)) || $this->can($user, $session, 'terminal.control');

        return $allowed ? Response::allow() : Response::deny();
    }

    public function share(Authenticatable $user, TerminalSession $session): Response
    {
        if ($denied = $this->visible($user, $session)) {
            return $denied;
        }

        return $this->owns($user, $session) && $this->ownerMayOpen($user, $session) ? Response::allow() : Response::deny('Only the session owner can share it.');
    }

    public function replay(Authenticatable $user, TerminalSession $session): Response
    {
        if ($denied = $this->visible($user, $session)) {
            return $denied;
        }

        return $this->owns($user, $session) || $this->can($user, $session, 'terminal.recordings.view') ? Response::allow() : Response::deny();
    }

    public function canView(Authenticatable $user, TerminalSession $session): bool
    {
        if ($this->owns($user, $session)) {
            return $this->ownerMayOpen($user, $session);
        }

        return $session->shared && $this->can($user, $session, 'terminal.attach');
    }

    public function canType(Authenticatable $user, TerminalSession $session): bool
    {
        if ($this->owns($user, $session)) {
            return $this->ownerMayOpen($user, $session);
        }

        return $this->canView($user, $session) && $this->can($user, $session, 'terminal.control');
    }

    /** 404 for users without any terminal permission in the session's organization (other orgs included). */
    private function visible(Authenticatable $user, TerminalSession $session): ?Response
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($this->can($user, $session, $permission)) {
                return null;
            }
        }

        return $this->owns($user, $session) && $this->access->isMember((string) $user->getAuthIdentifier(), $session->organization_id)
            ? null
            : Response::denyAsNotFound();
    }

    /** What lets the owner keep using a session: terminal.open, or sandboxes.use for a sandbox console. */
    private function ownerMayOpen(Authenticatable $user, TerminalSession $session): bool
    {
        return $this->can($user, $session, 'terminal.open')
            || ($session->targetType() === TerminalSession::TARGET_SANDBOX && $this->can($user, $session, SandboxDirectory::USE_PERMISSION));
    }

    private function owns(Authenticatable $user, TerminalSession $session): bool
    {
        return $session->isOwnedBy((string) $user->getAuthIdentifier());
    }

    private function can(Authenticatable $user, TerminalSession $session, string $permission): bool
    {
        return $this->access->can($user, $session->organization_id, $permission);
    }
}
