<?php

namespace Falak\Network\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organization's Tailscale auth key (docs/plans/TAILSCALE.md): sent to a server only when it joins, never shown again.
 *
 * @property string $organization_id
 * @property string $auth_key
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class TailscaleSetting extends Model
{
    protected $table = 'network_tailscale_settings';

    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['auth_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['auth_key' => Sealed::class];
    }
}
