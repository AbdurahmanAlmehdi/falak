<?php

namespace Falak\Config\Domain\Models;

use Falak\Servers\Contracts\ServerDirectory;
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
 * @property bool $separate_approver
 * @property ?list<string> $allowed_credentials
 * @property ?list<string> $allowed_servers server ids (shown and given as names: {@see allowedServerNames()})
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
        return ['enabled' => 'boolean', 'experimental' => 'boolean', 'separate_approver' => 'boolean', 'allowed_credentials' => 'array', 'allowed_servers' => 'array'];
    }

    public static function enabledFor(string $projectId): ?self
    {
        return self::query()->where('project_id', strtolower($projectId))->where('enabled', true)->first();
    }

    /**
     * The allowed servers' current names (a renamed server shows its new name; a deleted one is gone).
     *
     * @return list<string>
     */
    public function allowedServerNames(): array
    {
        $ids = array_flip(array_map('strval', (array) ($this->allowed_servers ?? [])));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (app(ServerDirectory::class)->forOrganization($this->organization_id) as $server) {
            if (isset($ids[$server->id])) {
                $names[] = $server->name;
            }
        }

        sort($names);

        return $names;
    }

    public function format(): string
    {
        return str_ends_with(strtolower($this->path), '.json') ? 'json' : 'yaml';
    }
}
