<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A path rate limit of a site at the edge: at most $events requests per client IP to $path within $window_s seconds.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $path a Caddy path, optionally ending in * (a prefix)
 * @property int $events
 * @property int $window_s
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class PathRateLimit extends Model
{
    use HasUlids;

    /** A request path, optionally ending in "*". No placeholders, spaces or quotes (the agent checks the same). */
    public const PATH = '#^/[A-Za-z0-9._~!$&\'()+,;=:@%/-]{0,198}\*?$#';

    public const MAX_PER_SITE = 20;

    protected $table = 'edge_path_rate_limits';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['events' => 'integer', 'window_s' => 'integer'];
    }
}
