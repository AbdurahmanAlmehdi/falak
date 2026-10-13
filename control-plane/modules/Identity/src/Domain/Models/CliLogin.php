<?php

namespace Falak\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One `falak login` in the browser (RFC 8628 device authorization): the CLI holds the device code, the user types
 * or follows the user code in the panel and approves; the CLI then collects a token once.
 *
 * @property string $id
 * @property string $device_code_hash
 * @property string $user_code_hash
 * @property string $client_name
 * @property ?string $ip_address
 * @property string $status pending|approved|denied|consumed
 * @property ?string $user_id
 * @property ?string $organization_id
 * @property ?Carbon $last_polled_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class CliLogin extends Model
{
    use HasUlids;

    protected $table = 'identity_cli_logins';

    /** @var list<string> */
    protected $fillable = ['device_code_hash', 'user_code_hash', 'client_name', 'ip_address', 'expires_at']; // status and owner change only through CliLogins' guarded updates

    /** @var list<string> */
    protected $hidden = ['device_code_hash', 'user_code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_polled_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
