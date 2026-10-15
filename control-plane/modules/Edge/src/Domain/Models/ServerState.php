<?php

namespace Falak\Edge\Domain\Models;

use Falak\Edge\Domain\Enums\ApplyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Last edge.caddy.apply sent to a server.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property ?string $payload_sha256
 * @property ?string $command_id
 * @property ApplyStatus $status
 * @property ?string $config_sha256
 * @property ?int $routes
 * @property ?string $error
 * @property ?string $rate_limits_refused why the applied config left the rate limits out (edge.rate_limit)
 * @property ?Carbon $dispatched_at
 * @property ?Carbon $applied_at
 * @property ?list<string> $octane_sites site ids the last dispatched config proxies to Octane
 * @property ?list<string> $applied_octane_sites site ids the applied config proxies to Octane
 */
class ServerState extends Model
{
    protected $table = 'edge_server_states';

    protected $primaryKey = 'server_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplyStatus::class,
            'routes' => 'integer',
            'dispatched_at' => 'datetime',
            'applied_at' => 'datetime',
            'octane_sites' => 'array',
            'applied_octane_sites' => 'array',
        ];
    }
}
