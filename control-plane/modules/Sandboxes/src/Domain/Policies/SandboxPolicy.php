<?php

namespace Falak\Sandboxes\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Sandboxes of other organizations are hidden (404), never merely forbidden. `use` covers creating sandboxes and seeing
 * them; exec, files, console, rename, extend and kill are for its creator (`use`), or someone with `manage` on anyone's
 * sandbox.
 */
final class SandboxPolicy
{
    public const VIEW = 'sandboxes.view';

    public const USE = 'sandboxes.use';

    public const MANAGE = 'sandboxes.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox): Response
    {
        return $this->check($user, $sandbox, self::VIEW);
    }

    public function use(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox): Response
    {
        return $this->check($user, $sandbox, self::USE);
    }

    /** Exec, files and consoles: what runs inside the sandbox, with its creator's data. */
    public function operate(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox): Response
    {
        return $this->ownOrManage($user, $sandbox, $sandbox instanceof SandboxSnapshot
            ? 'Only its creator (or someone with sandboxes.manage) can fork, rename or delete this snapshot.'
            : 'Only its creator (or someone with sandboxes.manage) can use this sandbox.');
    }

    /** Rename and extend. */
    public function update(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox): Response
    {
        return $this->ownOrManage($user, $sandbox, 'Only its creator (or someone with sandboxes.manage) can rename or extend this sandbox.');
    }

    public function kill(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox): Response
    {
        return $this->ownOrManage($user, $sandbox, 'Only its creator (or someone with sandboxes.manage) can kill this sandbox.');
    }

    private function ownOrManage(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox, string $denied): Response
    {
        $response = $this->check($user, $sandbox, self::USE);

        if ($response->denied() || $sandbox->created_by_id === (string) $user->getAuthIdentifier()) {
            return $response;
        }

        return $this->access->can($user, $sandbox->organization_id, self::MANAGE) ? Response::allow() : Response::deny($denied);
    }

    private function check(Authenticatable $user, Sandbox|SandboxSnapshot $sandbox, string $permission): Response
    {
        if (! $this->access->can($user, $sandbox->organization_id, self::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $sandbox->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
