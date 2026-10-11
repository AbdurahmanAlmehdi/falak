<?php

namespace Falak\Config\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A resource a run detached: no longer in the file (or its environment was released) but kept. Listed in the dashboard
 * ("Unmanaged resources") until it is deleted, dismissed or the file manages it again.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property ?string $unit
 * @property string $resource_type
 * @property string $resource_id
 * @property ?string $site_id
 * @property string $label
 * @property string $reason
 * @property ?string $delete_url
 * @property ?array<string, mixed> $delete_body
 * @property ?string $run_id
 * @property Carbon $detached_at
 */
class ConfigDetached extends Model
{
    use HasUlids;

    public const REMOVED_FROM_FILE = 'removed_from_file';

    public const ENVIRONMENT_RELEASED = 'environment_released';

    protected $table = 'config_detached';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['detached_at' => 'datetime', 'delete_body' => 'array'];
    }
}
