<?php

namespace Falak\Projects\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user-created frame around services on an environment's canvas. `x`/`y` is the anchor its members' positions are
 * relative to; the frame itself is sized from the members' bounding box by the canvas.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property string $name
 * @property ?string $icon
 * @property int $x
 * @property int $y
 * @property bool $collapsed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Environment $environment
 * @property-read Collection<int, Service> $services
 */
class Group extends Model
{
    use HasUlids;

    protected $table = 'projects_groups';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['x' => 'integer', 'y' => 'integer', 'collapsed' => 'boolean'];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return array{id: string, name: string, icon: ?string, position: array{x: int, y: int}, collapsed: bool}
     */
    public function toCanvas(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'icon' => $this->icon, 'position' => ['x' => $this->x, 'y' => $this->y], 'collapsed' => $this->collapsed];
    }
}
