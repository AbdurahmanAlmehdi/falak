<?php

namespace Falak\Builds\Domain\Models;

use DateTimeImmutable;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\BuildData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $site_slug
 * @property ?string $deployment_id
 * @property string $mode native|docker
 * @property BuildStatus $status
 * @property ?string $builder_id
 * @property ?string $builder_server_id on-server builds: only the builder running on this server may claim it
 * @property ?string $repository
 * @property ?string $branch
 * @property ?string $commit
 * @property ?string $resolved_commit
 * @property string $cache_key
 * @property ?string $reused_build_id
 * @property int $timeout_s
 * @property int $attempts
 * @property ?float $progress
 * @property ?string $artifact_key
 * @property ?string $artifact_sha256
 * @property ?int $artifact_size
 * @property ?string $artifact_format
 * @property ?Carbon $artifact_pruned_at
 * @property ?string $image_ref
 * @property ?string $image_digest
 * @property ?array<string, mixed> $manifest
 * @property ?array{file: string, content: string, images: array<string, string>} $compose
 * @property ?int $exit_code
 * @property ?string $error
 * @property ?int $duration_ms
 * @property ?string $requested_by
 * @property ?string $builder_name name the claiming falak-builder reported (--name)
 * @property ?string $builder_run_id run id of the claiming falak-builder process (null: builder without run ids)
 * @property ?Carbon $heartbeat_at last heartbeat or event from the builder
 * @property ?Carbon $assigned_at
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property ?Builder $builder
 */
class Build extends Model
{
    use HasUlids;

    protected $table = 'builds_builds';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BuildStatus::class,
            'timeout_s' => 'integer',
            'attempts' => 'integer',
            'progress' => 'float',
            'artifact_size' => 'integer',
            'artifact_pruned_at' => 'datetime',
            'manifest' => 'array',
            'compose' => 'array',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Builder, $this>
     */
    public function builder(): BelongsTo
    {
        return $this->belongsTo(Builder::class);
    }

    public function effectiveCommit(): ?string
    {
        return $this->resolved_commit ?? $this->commit;
    }

    /** Pinned image reference (repo@sha256:…) when the digest is known. */
    public function pinnedImage(): ?string
    {
        if ($this->image_ref === null) {
            return null;
        }

        if ($this->image_digest === null) {
            return $this->image_ref;
        }

        $repository = preg_replace('/:[^:\/]+$/', '', $this->image_ref);

        return $repository.'@'.$this->image_digest;
    }

    public function hasArtifact(): bool
    {
        return $this->status === BuildStatus::Succeeded && $this->artifact_pruned_at === null
            && ($this->mode === 'docker' ? ($this->image_ref !== null || $this->compose !== null) : $this->artifact_key !== null && $this->artifact_sha256 !== null);
    }

    public function toData(): BuildData
    {
        return new BuildData(
            id: $this->id,
            organizationId: $this->organization_id,
            siteId: $this->site_id,
            mode: $this->mode,
            status: $this->status,
            branch: $this->branch,
            commit: $this->effectiveCommit(),
            deploymentId: $this->deployment_id,
            reused: $this->reused_build_id !== null,
            error: $this->error,
            builderName: $this->builder?->name,
            createdAt: DateTimeImmutable::createFromInterface($this->created_at),
            startedAt: $this->started_at ? DateTimeImmutable::createFromInterface($this->started_at) : null,
            finishedAt: $this->finished_at ? DateTimeImmutable::createFromInterface($this->finished_at) : null,
            imageRef: $this->pinnedImage(),
        );
    }
}
