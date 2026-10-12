<?php

namespace Falak\Config\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Config sources and runs. Resources of other organizations are hidden (404), never merely forbidden.
 */
final class ConfigPolicy
{
    /** Validate, plan, export, read runs. */
    public const VIEW = 'config.view';

    /** Create runs (apply), cancel them, connect / enable / disable the source. */
    public const APPLY = 'config.apply';

    /** Approve runs with risky changes. */
    public const APPROVE = 'config.approve';

    /** `allow_destroy`: delete databases and volumes the file removed. */
    public const DESTROY = 'config.destroy';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::VIEW);
    }

    public function apply(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::APPLY);
    }

    public function approve(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::APPROVE);
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
