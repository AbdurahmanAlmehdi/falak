<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A server on the organization's tailnet and the delivery of `net.tailscale.apply` (docs/plans/TAILSCALE.md).
 *
 * @property string $server_id
 * @property string $organization_id
 * @property string $status installing | active | error (leaving deletes the row)
 * @property string $hostname
 * @property ?string $ipv4
 * @property ?string $ipv6
 * @property bool $routes_approved
 * @property ?string $version
 * @property ?string $error
 * @property ?string $command_id
 */
class TailscaleNode extends Model
{
    public const INSTALLING = 'installing';

    public const ACTIVE = 'active';

    public const ERROR = 'error';

    protected $table = 'network_tailscale_nodes';

    protected $primaryKey = 'server_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::INSTALLING, 'routes_approved' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['routes_approved' => 'boolean'];
    }
}
