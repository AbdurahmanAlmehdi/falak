<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-server protection: auto-ban settings and the convergence state of `net.protection.apply`.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property bool $auto_ban
 * @property int $failures
 * @property int $window_s
 * @property int $ban_s
 * @property int $revision
 * @property ?string $desired_hash
 * @property ?string $applied_hash
 * @property string $status pending | applying | applied | failed | unsupported
 * @property ?string $command_id
 * @property ?string $error
 * @property ?list<array{cidr: string, error: string}> $failed elements the agent refused
 * @property bool $set_counters
 * @property ?list<string> $released auto-bans lifted, until the agent confirmed
 * @property ?int $blocked_packets
 * @property ?Carbon $applied_at
 * @property ?Carbon $observed_at
 */
class ProtectionState extends Model
{
    protected $table = 'network_protection_states';

    protected $primaryKey = 'server_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['auto_ban' => false, 'failures' => 10, 'window_s' => 600, 'ban_s' => 3600, 'revision' => 0, 'status' => 'pending', 'set_counters' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_ban' => 'boolean',
            'failures' => 'integer',
            'window_s' => 'integer',
            'ban_s' => 'integer',
            'revision' => 'integer',
            'failed' => 'array',
            'released' => 'array',
            'set_counters' => 'boolean',
            'blocked_packets' => 'integer',
            'applied_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }
}
