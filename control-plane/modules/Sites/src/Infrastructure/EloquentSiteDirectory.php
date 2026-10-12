<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Contracts\Data\EnvironmentData;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SiteTargetData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Database\Eloquent\Builder;

final class EloquentSiteDirectory implements SiteDirectory
{
    /** @var ?array<string, ?SiteData> find() answers while remembering() runs */
    private ?array $memo = null;

    public function find(string $siteId): ?SiteData
    {
        if ($this->memo !== null) {
            return array_key_exists($siteId, $this->memo) ? $this->memo[$siteId] : ($this->memo[$siteId] = $this->query()->find($siteId)?->toData());
        }

        return $this->query()->find($siteId)?->toData();
    }

    public function findMany(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->query()->whereIn('id', array_values(array_unique($siteIds)))->get()
            ->mapWithKeys(fn (Site $site) => [$site->id => $site->toData()])->all();
    }

    public function remembering(callable $callback): mixed
    {
        if ($this->memo !== null) {
            return $callback(); // nested: the outer call owns the memo
        }

        $this->memo = [];

        try {
            return $callback();
        } finally {
            $this->memo = null;
        }
    }

    public function forOrganization(string $organizationId): array
    {
        return $this->list($this->query()->where('organization_id', $organizationId));
    }

    public function forServer(string $serverId): array
    {
        return $this->list($this->query()->whereIn('id', SiteTarget::query()->select('site_id')->where('server_id', $serverId)));
    }

    public function forRepository(string $connectionId, string $repository, ?string $branch = null): array
    {
        return $this->list($this->query()
            ->where('source_connection_id', $connectionId)
            ->whereRaw('lower(repository) = ?', [strtolower($repository)])
            ->when($branch !== null, fn (Builder $q) => $q->where('branch', $branch)));
    }

    public function targets(string $siteId): array
    {
        return $this->query()->find($siteId)?->targets->map(fn (SiteTarget $target) => $target->toData())->values()->all() ?? [];
    }

    public function leader(string $siteId): ?SiteTargetData
    {
        return $this->query()->find($siteId)?->leaderTarget()?->toData();
    }

    public function environment(string $siteId, ?int $version = null): ?EnvironmentData
    {
        return EnvironmentVersion::query()
            ->where('site_id', $siteId)
            ->when($version !== null, fn (Builder $q) => $q->where('version', $version), fn (Builder $q) => $q->orderByDesc('version'))
            ->first()
            ?->toData();
    }

    public function deployVariables(string $siteId, string $serverId, array $context = []): array
    {
        $site = $this->query()->find($siteId);

        if (! $site) {
            return [];
        }

        $exposed = $this->environment($siteId)?->deployScriptVariables() ?? [];
        $context = array_filter($context, fn ($value, $key) => is_string($key) && str_starts_with($key, 'FALAK_'), ARRAY_FILTER_USE_BOTH);

        return array_merge($exposed, SiteVariables::for($site, $serverId), SiteVariables::normalizeIds(array_map('strval', $context)));
    }

    /**
     * @return Builder<Site>
     */
    private function query(): Builder
    {
        return Site::query()->with('targets');
    }

    /**
     * @param  Builder<Site>  $query
     * @return list<SiteData>
     */
    private function list(Builder $query): array
    {
        return $query->orderBy('name')->get()->map(fn (Site $site) => $site->toData())->values()->all();
    }
}
