<?php

namespace Falak\ControlPlane\Application;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Recovery\Contracts\OperatorOrganization;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who operates the install: owners and admins (controlplane.update) of the operator organization (Recovery's
 * OperatorOrganization). Everyone else never sees the control plane's updates or its machine.
 */
final class Operators
{
    public const PERMISSION = 'controlplane.update';

    public function __construct(
        private readonly OperatorOrganization $operator,
        private readonly OrganizationAccess $access,
    ) {}

    public function isOperatorOrganization(?string $organizationId): bool
    {
        return $organizationId !== null && $organizationId === $this->operator->id();
    }

    public function canManage(?Authenticatable $user, ?string $organizationId): bool
    {
        return $user !== null && $this->isOperatorOrganization($organizationId)
            && $this->access->can($user, (string) $organizationId, self::PERMISSION);
    }
}
