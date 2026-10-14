<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A server's latest net.observed report (docs/plans/network/connections.md).
 *
 * @property string $server_id
 * @property string $organization_id
 * @property ?array<string, mixed> $report
 * @property ?Carbon $observed_at
 * @property ?array<string, float> $baselines probe name => connect time baseline (ms, exponentially weighted)
 * @property ?string $config_hash what the last successful net.connections.configure carried
 * @property ?string $config_pending_hash what the command in flight carries
 * @property ?string $config_command_id that command
 */
final class NetworkObservation extends Model
{
    protected $table = 'network_observations';

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
            'report' => 'array',
            'baselines' => 'array',
            'observed_at' => 'datetime',
        ];
    }
}
