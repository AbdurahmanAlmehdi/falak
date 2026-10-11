<?php

namespace Falak\Config\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A project's falak.yaml: where it lives (connection, repository, path) and whether the project follows it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $source_connection_id
 * @property string $repository
 * @property string $path
 * @property bool $enabled
 * @property bool $experimental
 * @property ?list<string> $allowed_credentials
 * @property ?string $created_by
 */
class ConfigSource extends Model
{
    use HasUlids;

    protected $table = 'config_sources';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'experimental' => 'boolean', 'allowed_credentials' => 'array'];
    }

    public static function enabledFor(string $projectId): ?self
    {
        return self::query()->where('project_id', strtolower($projectId))->where('enabled', true)->first();
    }

    public function format(): string
    {
        return str_ends_with(strtolower($this->path), '.json') ? 'json' : 'yaml';
    }
}
