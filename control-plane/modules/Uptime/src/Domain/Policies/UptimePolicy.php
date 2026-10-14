<?php

namespace Falak\Uptime\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Uptime\Domain\Models\StatusPage;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Monitors: `uptime.view` to see, `uptime.manage` to change. Status pages: `uptime.publish` to change (they make names
 * public). Records of other organizations are reported as not found.
 */
final class UptimePolicy
{
    public const VIEW = 'uptime.view';

    public const MANAGE = 'uptime.manage';

    public const PUBLISH = 'uptime.publish';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::VIEW);
    }

    public function update(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, $model instanceof StatusPage ? self::PUBLISH : self::MANAGE);
    }

    public function delete(Authenticatable $user, Model $model): Response
    {
        return $this->update($user, $model);
    }

    private function check(Authenticatable $user, Model $model, string $permission): Response
    {
        $organizationId = (string) $model->getAttribute('organization_id');

        if (! $this->access->can($user, $organizationId, self::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $organizationId, $permission) ? Response::allow() : Response::deny();
    }
}
