<?php

namespace Falak\Network\Domain\Models;

use Falak\Network\Domain\Enums\ApplyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-server convergence state of the nftables ruleset.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property int $revision
 * @property ?string $desired_hash
 * @property ?string $applied_hash
 * @property ApplyStatus $status
 * @property ?string $command_id
 * @property ?string $ruleset_sha256
 * @property ?string $error
 * @property ?Carbon $applied_at
 * @property ?Carbon $failed_at first failure of the current failing streak (null while applies succeed)
 * @property ?array<string, array{packets: int, bytes: int}> $counters rule id ("ssh" too) => its nftables counter (net.protection)
 * @property ?Carbon $counters_at
 */
class FirewallState extends Model
{
    protected $table = 'network_firewall_states';

    protected $primaryKey = 'server_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplyStatus::class,
            'revision' => 'integer',
            'applied_at' => 'datetime',
            'failed_at' => 'datetime',
            'counters' => 'array',
            'counters_at' => 'datetime',
        ];
    }
}
