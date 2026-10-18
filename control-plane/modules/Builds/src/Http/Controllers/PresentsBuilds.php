<?php

namespace Falak\Builds\Http\Controllers;

use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;

trait PresentsBuilds
{
    /**
     * @param  array<string, string>  $siteNames
     * @return array<string, mixed>
     */
    protected function buildResource(Build $build, array $siteNames = []): array
    {
        return [
            'id' => $build->id,
            'site_id' => $build->site_id,
            'site_name' => $siteNames[$build->site_id] ?? $build->site_slug,
            'deployment_id' => $build->deployment_id,
            'mode' => $build->mode,
            'status' => $build->status->value,
            'branch' => $build->branch,
            'commit' => $build->effectiveCommit(),
            'reused_build_id' => $build->reused_build_id,
            'builder' => $build->builder?->name,
            'progress' => $build->progress,
            'error' => $build->error,
            'exit_code' => $build->exit_code,
            'duration_ms' => $build->duration_ms,
            'attempts' => $build->attempts,
            'artifact' => $build->mode === 'native' && $build->artifact_sha256 ? [
                'sha256' => $build->artifact_sha256,
                'size_bytes' => $build->artifact_size,
                'format' => $build->artifact_format,
                'pruned' => $build->artifact_pruned_at !== null,
            ] : null,
            'image' => $build->pinnedImage(),
            'created_at' => $build->created_at->toIso8601String(),
            'started_at' => $build->started_at?->toIso8601String(),
            'finished_at' => $build->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function builderResource(Builder $builder): array
    {
        return [
            'id' => $builder->id,
            'name' => $builder->name,
            'kind' => $builder->kind,
            'server_id' => $builder->server_id,
            'pool' => $builder->pool,
            'caps' => $builder->caps,
            'shared' => $builder->organization_id === null,
            'modes' => $builder->modes,
            'enabled' => $builder->enabled,
            'online' => $builder->isOnline(),
            'reported_name' => $builder->reported_name,
            'last_ip' => $builder->last_ip,
            'last_seen_at' => $builder->last_seen_at?->toIso8601String(),
            'install_command_id' => $builder->install_command_id,
        ];
    }
}
