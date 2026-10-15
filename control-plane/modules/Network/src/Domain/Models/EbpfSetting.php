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
 * @property string $status pending | applied | failed
 * @property ?string $command_id
 * @property ?bool $applied_enabled
 * @property ?string $error
 * @property ?Carbon $applied_at
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
    protected $attributes = ['enabled' => false, 'status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'applied_enabled' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }
}
