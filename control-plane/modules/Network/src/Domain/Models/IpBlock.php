<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A blocked address or CIDR (docs/plans/network/protection.md), on one server or on every server of the organization.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $server_id null = every server of the organization
 * @property string $cidr canonical (a single address without its length)
 * @property string $family ipv4 | ipv6
 * @property ?string $reason
 * @property string $source manual | auto
 * @property ?Carbon $expires_at null = permanent
 * @property ?string $created_by
 * @property ?array<string, mixed> $meta
 * @property Carbon $created_at
 */
class IpBlock extends Model
{
    use HasUlids;

    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    protected $table = 'network_ip_blocks';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'meta' => 'array'];
    }

    /**
     * @param  Builder<IpBlock>  $query
     * @return Builder<IpBlock>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Blocks that apply to a server: its own and the organization-wide ones.
     *
     * @param  Builder<IpBlock>  $query
     * @return Builder<IpBlock>
     */
    public function scopeForServer(Builder $query, string $organizationId, string $serverId): Builder
    {
        return $query->where('organization_id', $organizationId)->where(fn (Builder $q) => $q->whereNull('server_id')->orWhere('server_id', $serverId));
    }
}
