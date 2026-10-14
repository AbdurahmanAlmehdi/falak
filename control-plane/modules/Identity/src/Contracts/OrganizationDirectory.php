<?php

namespace Falak\Identity\Contracts;

use DateTimeInterface;
use Falak\Identity\Contracts\Data\OrganizationData;
use Falak\Identity\Contracts\Data\UserData;

/**
 * Read-only lookups of organizations and users for other modules.
 */
interface OrganizationDirectory
{
    public function find(string $organizationId): ?OrganizationData;

    /**
     * Every organization, oldest first (maintenance tasks such as data backfills).
     *
     * @return list<OrganizationData>
     */
    public function all(): array;

    public function findUser(string $userId): ?UserData;

    /**
     * @return list<UserData>
     */
    public function members(string $organizationId): array;

    /**
     * IP addresses the organization's members used the panel from since $since (their sessions and audited actions),
     * most recent first: Network never blocks them (docs/plans/network/protection.md).
     *
     * @return list<string>
     */
    public function recentMemberAddresses(string $organizationId, DateTimeInterface $since, int $limit = 500): array;
}
