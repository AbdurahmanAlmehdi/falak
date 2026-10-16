<?php

namespace Falak\Sandboxes\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A disposable gVisor sandbox on one server (docs/plans/SANDBOXES.md). runsc container `falak-sbx-<id>`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $name
 * @property string $image
 * @property ?string $image_digest
 * @property string $status creating | running | failed | killed
 * @property ?string $error
 * @property float $cpus
 * @property int $memory_mb
 * @property int $disk_mb
 * @property string $network none | internet | allowlist
 * @property ?list<string> $allow_hosts
 * @property ?array<string, string> $env
 * @property ?array{url: string, ref?: string} $repo
 * @property int $ttl_seconds
 * @property Carbon $expires_at
 * @property ?Carbon $agent_expires_at the expiry the agent was last given
 * @property ?string $ip
 * @property string $created_by_type user | token
 * @property ?string $created_by_id
 * @property ?string $created_by_name
 * @property ?string $command_id
 * @property ?Carbon $started_at
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $killed_at
 * @property ?Carbon $kill_confirmed_at
 * @property ?string $kill_reason
 * @property Carbon $created_at
 */
class Sandbox extends Model
{
    use HasUlids;

    public const CREATING = 'creating';

    public const RUNNING = 'running';

    public const FAILED = 'failed';

    public const KILLED = 'killed';

    /** Statuses that hold a server's resources (and count against quotas). */
    public const LIVE = [self::CREATING, self::RUNNING];

    public const NETWORKS = ['none', 'internet', 'allowlist'];

    public const NAME = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    protected $table = 'sandboxes_sandboxes';

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
            'repo' => 'array',
            'ttl_seconds' => 'integer',
            'expires_at' => 'datetime',
            'agent_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'killed_at' => 'datetime',
            'kill_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SandboxExec, $this>
     */
    public function execs(): HasMany
    {
        return $this->hasMany(SandboxExec::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /**
     * The API resource (docs/plans/SANDBOXES.md "HTTP API"). env is never returned, only its keys.
     *
     * @return array<string, mixed>
     */
    public function present(?string $serverName): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'image' => $this->image,
            'server' => ['id' => $this->server_id, 'name' => $serverName],
            'cpus' => $this->cpus,
            'memory_mb' => $this->memory_mb,
            'disk_mb' => $this->disk_mb,
            'network' => $this->network,
            'allow_hosts' => $this->allow_hosts ?? [],
            'env_keys' => array_keys($this->env ?? []),
            'repo' => $this->repo,
            'ttl_seconds' => $this->ttl_seconds,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => ['type' => $this->created_by_type, 'name' => $this->created_by_name],
            'ip' => $this->ip,
            'kill_reason' => $this->kill_reason,
            'error' => $this->error,
        ];
    }
}
