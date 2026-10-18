<?php

namespace Falak\Recovery\Application;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Recovery\Contracts\OperatorOrganization;
use Falak\Recovery\Domain\Models\Dismissal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Who sees the control plane's disaster recovery, and the "set up disaster recovery" prompt: owners and admins
 * (recovery.control_plane) of the operator organization (FALAK_DR_ORGANIZATION, which install.sh records; unset, the
 * only organization of a single-organization install, else none). Members
 * of other organizations on a shared install never see it. The banner stays until DR is configured; a dismissal hides
 * it for recovery.dismiss_days, then it returns.
 */
final class ControlPlaneNotice implements OperatorOrganization
{
    public const PERMISSION = 'recovery.control_plane';

    public const BANNER = 'control-plane-dr';

    public function __construct(
        private readonly OrganizationDirectory $organizations,
        private readonly OrganizationAccess $access,
    ) {}

    public function id(): ?string
    {
        return $this->operatorOrganizationId();
    }

    public function operatorOrganizationId(): ?string
    {
        $configured = trim((string) config('recovery.operator_organization'));

        if ($configured !== '') {
            foreach ($this->organizations->all() as $organization) {
                if ($organization->id === strtolower($configured) || $organization->slug === $configured) {
                    return $organization->id;
                }
            }

            return null;
        }

        // Not recorded (install.sh writes it after creating the first admin): only an install with a single
        // organization has an obvious operator; with several, nobody is shown the control plane.
        $all = $this->organizations->all();

        return count($all) === 1 ? $all[0]->id : null;
    }

    public function canManage(?Authenticatable $user, ?string $organizationId): bool
    {
        return $user !== null && $organizationId !== null && $organizationId === $this->operatorOrganizationId()
            && $this->access->can($user, $organizationId, self::PERMISSION);
    }

    public function dismissedUntil(string $userId): ?Carbon
    {
        $until = Dismissal::query()->where('user_id', $userId)->where('key', self::BANNER)->value('dismissed_until');

        return $until !== null ? Carbon::parse($until) : null;
    }

    public function dismiss(string $userId): void
    {
        Dismissal::query()->updateOrCreate(['user_id' => $userId, 'key' => self::BANNER], ['dismissed_until' => now()->addDays(max(1, (int) config('recovery.dismiss_days', 30)))]);
    }

    /**
     * The shared `disasterRecovery` prop: null for everyone who doesn't operate the install.
     *
     * @return array{configured: bool, needs_setup: bool, banner: bool, settings_url: string}|null
     */
    public function forUser(?Authenticatable $user, ?string $organizationId, ControlPlaneStatus $status): ?array
    {
        if (! $this->canManage($user, $organizationId)) {
            return null;
        }

        $until = $this->dismissedUntil((string) $user?->getAuthIdentifier());
        $needs = $status->needsSetup();

        return [
            'configured' => $status->configured(),
            'needs_setup' => $needs,
            'banner' => $needs && ($until === null || $until->isPast()),
            'settings_url' => '/settings/disaster-recovery',
        ];
    }
}
