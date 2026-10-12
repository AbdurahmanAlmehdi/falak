<?php

namespace Falak\Deployments\Domain\Models;

use Falak\Deployments\Domain\Enums\ReleaseStatus;
use Falak\Kernel\Security\Casts\SealedArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A release of a site: releases/<ID> on native servers (uppercase ULID there), or an image.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $deployment_id
 * @property ?string $build_id
 * @property ?string $commit
 * @property ?string $branch
 * @property ?string $commit_message
 * @property ?string $commit_author
 * @property ?string $image
 * @property ?array{yaml: string, env: array<string, string>, leader: array<string, list<string>>, source: string, version?: ?int, pinned?: bool, registry?: bool} $compose rendered compose release
 * @property ?array<string, string> $environment the site variables its `.env` was written with (references resolved)
 * @property ?array<string, mixed> $config the unit's slice of the resolved falak.yaml it was released with
 * @property ReleaseStatus $status
 * @property ?Carbon $activated_at
 * @property ?Carbon $auto_rolled_back_at its watch rolled it back: never an automatic rollback target
 * @property Carbon $created_at
 */
class Release extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'deployments_releases';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['compose', 'environment'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ReleaseStatus::class, 'activated_at' => 'datetime', 'auto_rolled_back_at' => 'datetime', 'compose' => SealedArray::class, 'environment' => SealedArray::class, 'config' => 'array'];
    }

    public static function current(string $siteId): ?self
    {
        return self::query()->where('site_id', $siteId)->where('status', ReleaseStatus::Active)->latest('activated_at')->first();
    }

    public function canRollBackTo(): bool
    {
        return $this->status === ReleaseStatus::Inactive;
    }

    /**
     * Shape of `api.Release` in the Go CLI.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'commit' => $this->commit,
            'branch' => $this->branch,
            'message' => $this->commit_message,
            'author' => $this->commit_author,
            'deployment_id' => $this->deployment_id,
            'build_id' => $this->build_id,
            'image' => $this->image,
            'status' => $this->status->value,
            'active' => $this->status === ReleaseStatus::Active,
            'can_rollback' => $this->canRollBackTo(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'auto_rolled_back_at' => $this->auto_rolled_back_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
