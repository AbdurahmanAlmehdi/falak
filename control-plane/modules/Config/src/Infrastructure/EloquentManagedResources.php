<?php

namespace Falak\Config\Infrastructure;

use Falak\Config\Application\FileLinks;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;

final class EloquentManagedResources implements ManagedResources
{
    public function __construct(private readonly FileLinks $links) {}

    public function record(ConfigScope $scope, ApplyContext $ctx, ?string $unit, string $resourceType, string $resourceId, array $paths, ?bool $createdByFile = null, bool $merge = false): void
    {
        $resourceId = strtolower($resourceId);
        $existing = ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', $resourceId)->get()->keyBy('path');
        $created = $createdByFile ?? (bool) $existing->first()?->created_by_file;

        foreach (array_values(array_unique($paths)) as $path) {
            ConfigOwnership::query()->updateOrCreate(
                ['resource_type' => $resourceType, 'resource_id' => $resourceId, 'path' => $path],
                [
                    'organization_id' => $scope->organizationId,
                    'project_id' => $scope->projectId,
                    'environment_id' => $scope->environmentId,
                    'unit' => $unit,
                    'created_by_file' => $created,
                    'run_id' => $ctx->runId,
                ],
            );
        }

        if (! $merge) {
            ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', $resourceId)->whereNotIn('path', $paths ?: [''])->delete();
        }

        if ($createdByFile !== null) {
            ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', $resourceId)->update(['created_by_file' => $createdByFile]);
        }

        // Managed again (the file lists it once more): no longer an unmanaged resource.
        ConfigDetached::query()->where('resource_type', $resourceType)->where('resource_id', $resourceId)->delete();
    }

    public function detach(ConfigScope $scope, ApplyContext $ctx, ?string $unit, string $resourceType, string $resourceId, string $label, ?string $deleteUrl = null, ?string $siteId = null, array $deleteBody = []): void
    {
        $resourceId = strtolower($resourceId);
        $this->forget($resourceType, $resourceId, $scope->environmentId);

        if ($scope->preview) {
            return; // a preview is torn down whole: nothing to list
        }

        ConfigDetached::query()->updateOrCreate(
            ['environment_id' => $scope->environmentId, 'resource_type' => $resourceType, 'resource_id' => $resourceId],
            [
                'organization_id' => $scope->organizationId,
                'project_id' => $scope->projectId,
                'unit' => $unit,
                'site_id' => $siteId !== null ? strtolower($siteId) : null,
                'label' => mb_substr($label, 0, 255),
                'reason' => ConfigDetached::REMOVED_FROM_FILE,
                'delete_url' => $deleteUrl,
                'delete_body' => $deleteBody === [] ? null : $deleteBody,
                'run_id' => $ctx->runId,
                'detached_at' => now(),
            ],
        );
    }

    public function forgetPaths(string $resourceType, string $resourceId, array $prefixes, array $keep = []): void
    {
        $rows = ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($resourceId))->get(['id', 'path']);

        foreach ($rows as $row) {
            if (in_array($row->path, $keep, true)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if ($row->path === $prefix || str_starts_with($row->path, $prefix.'/')) {
                    ConfigOwnership::query()->whereKey($row->id)->delete();
                    break;
                }
            }
        }
    }

    public function forget(string $resourceType, string $resourceId, ?string $environmentId = null): void
    {
        ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($resourceId))
            ->when($environmentId !== null, fn ($q) => $q->where('environment_id', strtolower((string) $environmentId)))->delete();
    }

    public function createdByFile(string $resourceType, string $resourceId, ?string $environmentId = null): bool
    {
        return ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($resourceId))->where('created_by_file', true)
            ->when($environmentId !== null, fn ($q) => $q->where('environment_id', strtolower((string) $environmentId)))->exists();
    }

    public function managedIn(string $environmentId, string $resourceType, string $resourceId): bool
    {
        return ConfigOwnership::query()->where('environment_id', strtolower($environmentId))->where('resource_type', $resourceType)
            ->where('resource_id', strtolower($resourceId))->exists();
    }

    public function move(string $resourceType, string $fromId, string $toId): void
    {
        if (strtolower($fromId) === strtolower($toId)) {
            return;
        }

        ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($toId))->delete();
        ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($fromId))->update(['resource_id' => strtolower($toId)]);
    }

    public function managed(string $resourceType, string $resourceId): ManagedFields
    {
        $rows = ConfigOwnership::query()->where('resource_type', $resourceType)->where('resource_id', strtolower($resourceId))->get();

        if ($rows->isEmpty()) {
            return ManagedFields::none();
        }

        $source = ConfigSource::enabledFor((string) $rows->first()->project_id);

        if ($source === null) {
            return ManagedFields::none(); // a disabled source makes every field editable again
        }

        $run = ConfigRun::query()->find($rows->sortByDesc('updated_at')->first()->run_id);
        $commit = $run?->commit;

        return new ManagedFields(
            paths: $rows->pluck('path')->sort()->values()->all(),
            commit: $commit,
            file: $source->path,
            url: $commit !== null ? $this->links->file($source, $commit) : null,
            unit: $rows->first()->unit,
        );
    }

    public function resources(string $environmentId, string $resourceType): array
    {
        return ConfigOwnership::query()->where('environment_id', strtolower($environmentId))->where('resource_type', $resourceType)
            ->get(['resource_id', 'unit'])->mapWithKeys(fn (ConfigOwnership $o) => [$o->resource_id => $o->unit])->all();
    }
}
