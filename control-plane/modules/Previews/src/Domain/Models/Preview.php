<?php

namespace Falak\Previews\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pull request's preview in one project.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $connection_id
 * @property string $provider
 * @property string $repository
 * @property int $number
 * @property string $title
 * @property ?string $url
 * @property ?string $author
 * @property string $head_branch
 * @property string $head_sha
 * @property ?Carbon $head_updated_at when Falak learned of the head
 * @property string $base_branch
 * @property bool $is_fork
 * @property ?string $source_repository
 * @property string $status
 * @property ?string $status_message
 * @property ?string $environment_id
 * @property ?array<string, string> $sites service name => site id
 * @property ?array<string, array{database_id: string, strategy: string, state: string, restore_id?: ?string, command_id?: ?string}> $databases
 * @property ?array<string, string> $urls service name => URL
 * @property ?array<string, string> $deployments site id => deploying | deployed | failed
 * @property ?string $deployed_sha
 * @property ?string $config_ref the commit (or branch) of the falak.yaml it was last built from
 * @property ?string $comment_id
 * @property ?string $basic_username
 * @property ?string $basic_password
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $closed_at
 * @property Carbon $created_at
 */
class Preview extends Model
{
    use HasUlids;

    /** A fork's pull request: a member approves it first. */
    public const WAITING_APPROVAL = 'waiting_approval';

    /** The project's limit of concurrent previews is reached. */
    public const QUEUED = 'queued';

    /** Environment, databases and routes being set up (databases restored / sanitized). */
    public const CREATING = 'creating';

    public const DEPLOYING = 'deploying';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const CLOSED = 'closed';

    /** Statuses holding resources (count against the limit). */
    public const RUNNING = [self::CREATING, self::DEPLOYING, self::READY, self::FAILED];

    protected $table = 'previews_previews';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['basic_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_fork' => 'boolean',
            'sites' => 'array',
            'databases' => 'array',
            'urls' => 'array',
            'deployments' => 'array',
            'basic_password' => Sealed::class,
            'approved_at' => 'datetime',
            'head_updated_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    public function label(): string
    {
        return "{$this->repository}#{$this->number}";
    }
}
