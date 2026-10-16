<?php

namespace Falak\Sandboxes\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A snapshot of a sandbox (`runsc checkpoint`: memory, processes, written files) on its server, under
 * /var/lib/falak/sandboxes/snapshots/<id>. Sandboxes are made from it with sandbox.create from_snapshot (fork); a named
 * snapshot is a template. Snapshots never expire.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $sandbox_id
 * @property string $server_id
 * @property ?string $name
 * @property string $status creating | ready | failed | deleted
 * @property ?string $error
 * @property string $image
 * @property ?string $image_digest
 * @property float $cpus
 * @property int $memory_mb
 * @property int $disk_mb
 * @property string $network
 * @property ?list<string> $allow_hosts
 * @property ?array<string, string> $env
 * @property ?int $size_bytes
 * @property string $created_by_type
 * @property ?string $created_by_id
 * @property ?string $created_by_name
 * @property ?string $command_id
 * @property ?Carbon $deleted_at
 * @property ?Carbon $delete_confirmed_at
 * @property Carbon $created_at
 * @property-read ?Sandbox $sandbox
 */
class SandboxSnapshot extends Model
{
    use HasUlids;

    public const CREATING = 'creating';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const DELETED = 'deleted';

    /** Statuses that hold disk on the server (and count against the snapshot quotas). */
    public const KEPT = [self::CREATING, self::READY];

    protected $table = 'sandboxes_snapshots';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['env'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpus' => 'float',
            'memory_mb' => 'integer',
            'disk_mb' => 'integer',
            'allow_hosts' => 'array',
            'env' => SealedArray::class,
            'size_bytes' => 'integer',
            'deleted_at' => 'datetime',
            'delete_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sandbox, $this>
     */
    public function sandbox(): BelongsTo
    {
        return $this->belongsTo(Sandbox::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeKept(Builder $query): void
    {
        $query->whereIn('status', self::KEPT);
    }

    /** By id, else by name among those not deleted (names are unique there). */
    public static function lookup(string $organizationId, string $ref): ?self
    {
        $query = self::query()->where('organization_id', $organizationId);

        return (clone $query)->find(strtolower($ref)) ?? $query->where('status', '!=', self::DELETED)->where('name', $ref)->first();
    }

    /**
     * The API resource (docs/plans/SANDBOXES.md "HTTP API (new routes)").
     *
     * @return array<string, mixed>
     */
    public function present(?string $serverName): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'sandbox' => ['id' => $this->sandbox_id, 'name' => $this->sandbox?->name],
            'server' => ['id' => $this->server_id, 'name' => $serverName],
            'image' => $this->image,
            'cpus' => $this->cpus,
            'memory_mb' => $this->memory_mb,
            'disk_mb' => $this->disk_mb,
            'network' => $this->network,
            'env_keys' => array_keys($this->env ?? []),
            'size_bytes' => $this->size_bytes,
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => ['type' => $this->created_by_type, 'name' => $this->created_by_name],
        ];
    }
}
