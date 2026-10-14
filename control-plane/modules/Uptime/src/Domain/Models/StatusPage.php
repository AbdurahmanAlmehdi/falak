<?php

namespace Falak\Uptime\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A project's public status page (opt-in): /status/{slug}, showing only the names the user chose.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $slug
 * @property string $title
 * @property ?string $description
 * @property bool $enabled
 */
class StatusPage extends Model
{
    use HasUlids;

    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])$/';

    protected $table = 'uptime_status_pages';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'uptime_status_page_monitors', 'status_page_id', 'monitor_id')
            ->withPivot(['display_name', 'position'])
            ->orderByPivot('position');
    }

    public function cacheKey(): string
    {
        return "uptime:status-page:{$this->slug}";
    }
}
