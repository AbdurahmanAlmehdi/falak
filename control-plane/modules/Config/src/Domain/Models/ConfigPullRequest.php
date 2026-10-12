<?php

namespace Falak\Config\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Falak's plan comment on one pull request of a project.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property int $number
 * @property ?string $comment_id
 * @property ?string $head_sha
 * @property ?string $state
 */
class ConfigPullRequest extends Model
{
    use HasUlids;

    protected $table = 'config_pull_requests';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }
}
