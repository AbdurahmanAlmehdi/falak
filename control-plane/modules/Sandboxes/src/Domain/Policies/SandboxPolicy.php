<?php

namespace Falak\Sandboxes\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Sandboxes of other organizations are hidden (404), never merely forbidden. `use` covers create, exec, files, console
 * and killing your own; killing someone else's sandbox needs `manage`.
 */
final class SandboxPolicy
{
    public const VIEW = 'sandboxes.view';

    public const USE = 'sandboxes.use';

    public const MANAGE = 'sandboxes.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Sandbox $sandbox): Response
    {
        return $this->check($user, $sandbox, self::VIEW);
    }

    public function use(Authenticatable $user, Sandbox $sandbox): Response
    {
        return $this->check($user, $sandbox, self::USE);
    }

    public function kill(Authenticatable $user, Sandbox $sandbox): Response
    {
        $response = $this->check($user, $sandbox, self::USE);

        if ($response->denied() || $sandbox->created_by_id === (string) $user->getAuthIdentifier()) {
            return $response;
        }

        return $this->access->can($user, $sandbox->organization_id, self::MANAGE)
            ? Response::allow()
            : Response::deny('Only its creator (or someone with sandboxes.manage) can kill this sandbox.');
    }

    private function check(Authenticatable $user, Sandbox $sandbox, string $permission): Response
    {
        if (! $this->access->can($user, $sandbox->organization_id, self::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $sandbox->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
