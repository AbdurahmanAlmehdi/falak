<?php

namespace Falak\Config\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The last drift check of an environment: today's state against its last applied run.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property string $status in_sync | drifted | unknown
 * @property ?string $baseline_run_id
 * @property ?string $signature
 * @property ?list<array<string, mixed>> $changes masked
 * @property ?Carbon $since
 * @property ?Carbon $checked_at
 * @property ?string $alerted_signature
 */
class ConfigDrift extends Model
{
    use HasUlids;

    public const IN_SYNC = 'in_sync';

    public const DRIFTED = 'drifted';

    public const UNKNOWN = 'unknown';

    protected $table = 'config_drift';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array', 'since' => 'datetime', 'checked_at' => 'datetime'];
    }
}
