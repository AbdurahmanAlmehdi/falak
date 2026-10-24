<?php

namespace Falak\Sites\Domain\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Organization-wide site policy (Settings → Compose).
 *
 * @property string $organization_id
 * @property bool $allow_privileged_compose
 */
class OrganizationSettings extends Model
{
    protected $table = 'sites_organization_settings';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allow_privileged_compose' => 'boolean'];
    }

    /** A managed install never runs privileged compose files, whatever an organization saved before. */
    protected function allowPrivilegedCompose(): Attribute
    {
        return Attribute::get(fn (mixed $value) => (bool) $value && ! config('controlplane.managed'));
    }

    public static function for(string $organizationId): self
    {
        return self::query()->find($organizationId) ?? new self(['organization_id' => $organizationId, 'allow_privileged_compose' => false]);
    }
}
