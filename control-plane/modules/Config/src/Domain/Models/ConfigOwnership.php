<?php

namespace Falak\Config\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One field the file manages on one resource (`path` is a JSON Pointer relative to the unit).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property string $resource_type
 * @property string $resource_id
 * @property ?string $unit
 * @property string $path
 * @property bool $created_by_file
 * @property string $run_id
 */
class ConfigOwnership extends Model
{
    use HasUlids;

    protected $table = 'config_ownership';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_by_file' => 'boolean'];
    }
}
