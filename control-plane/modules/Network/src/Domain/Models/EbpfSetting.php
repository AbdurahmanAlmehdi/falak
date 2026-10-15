<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A server's eBPF setting (docs/plans/network/ebpf.md) and the delivery of `net.ebpf.configure`.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property bool $enabled
 * @property bool $full_dns_names
 * @property bool $reenable
 * @property string $status pending | applied | failed
 * @property ?string $command_id
 * @property ?string $pending_hash
 * @property ?string $applied_hash
 * @property ?string $error
 * @property ?Carbon $applied_at
 * @property ?Carbon $updated_at
 */
class EbpfSetting extends Model
{
    protected $table = 'network_ebpf_settings';

    protected $primaryKey = 'server_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => false, 'full_dns_names' => false, 'reenable' => false, 'status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'full_dns_names' => 'boolean',
            'reenable' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }
}
