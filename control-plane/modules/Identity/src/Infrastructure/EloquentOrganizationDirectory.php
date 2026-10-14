<?php

namespace Falak\Identity\Infrastructure;

use DateTimeInterface;
use Falak\Identity\Contracts\Data\OrganizationData;
use Falak\Identity\Contracts\Data\UserData;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EloquentOrganizationDirectory implements OrganizationDirectory
{
    public function find(string $organizationId): ?OrganizationData
    {
        return Organization::query()->find($organizationId)?->toData();
    }

    public function all(): array
    {
        return Organization::query()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Organization $organization) => $organization->toData())
            ->values()
            ->all();
    }

    public function findUser(string $userId): ?UserData
    {
        $user = User::query()->find($userId);

        return $user ? new UserData($user->id, $user->name, $user->email) : null;
    }

    public function members(string $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization) {
            return [];
        }

        return $organization->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => new UserData($user->id, $user->name, $user->email))
            ->values()
            ->all();
    }

    public function recentMemberAddresses(string $organizationId, DateTimeInterface $since, int $limit = 500): array
    {
        $members = DB::table('identity_memberships')->where('organization_id', $organizationId)->pluck('user_id')->all();
        $out = [];

        if ($members !== [] && Schema::hasTable('sessions')) {
            $rows = DB::table('sessions')->whereIn('user_id', $members)->where('last_activity', '>=', $since->getTimestamp())
                ->whereNotNull('ip_address')->orderByDesc('last_activity')->limit($limit)->pluck('ip_address');
            array_push($out, ...$rows->all());
        }

        $rows = DB::table('identity_audit_log')->where('organization_id', $organizationId)->where('actor_type', 'user')
            ->where('created_at', '>=', $since)->whereNotNull('ip_address')
            ->selectRaw('ip_address, max(created_at) as last_at')->groupBy('ip_address')->orderByDesc('last_at')->limit($limit)->pluck('ip_address');
        array_push($out, ...$rows->all());

        return array_slice(array_values(array_unique(array_map('strval', $out))), 0, $limit);
    }
}
