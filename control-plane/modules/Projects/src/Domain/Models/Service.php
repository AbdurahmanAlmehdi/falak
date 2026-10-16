<?php

namespace Falak\Projects\Domain\Models;

use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ServiceKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site / database placed on an environment's canvas. `ref_id` is the owning module's opaque ULID.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $environment_id
 * @property ServiceKind $kind
 * @property string $ref_id
 * @property string $name
 * @property int $x
 * @property int $y
 * @property ?string $group_id
 * @property ?array{children?: array<string, array{x: int, y: int}>, collapsed?: bool} $layout
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Environment $environment
 * @property-read ?Group $group
 */
class Service extends Model
{
    use HasUlids;

    protected $table = 'projects_services';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => ServiceKind::class, 'x' => 'integer', 'y' => 'integer', 'layout' => 'array'];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** See {@see ServiceData::handle()}. */
    public static function handle(string $name): string
    {
        return ServiceData::handle($name);
    }

    public function toData(): ServiceData
    {
        return new ServiceData($this->id, $this->organization_id, $this->project_id, $this->environment_id, $this->kind, $this->ref_id, $this->name, $this->x, $this->y);
    }
}
