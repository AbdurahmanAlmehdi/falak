<?php

namespace Falak\Builds\Infrastructure;

use Falak\Builds\Application\Actions\CancelBuild;
use Falak\Builds\Application\Actions\RequestBuild;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Application\Registry;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\ArtifactData;
use Falak\Builds\Contracts\Data\BuildData;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Contracts\Data\ComposeBuildData;
use Falak\Builds\Contracts\Data\ImageData;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\BuildLog;

final class EloquentBuildService implements BuildService
{
    public function __construct(
        private readonly RequestBuild $requestBuild,
        private readonly CancelBuild $cancelBuild,
        private readonly ArtifactStorage $storage,
        private readonly Registry $registry,
    ) {}

    public function request(BuildRequest $request): BuildData
    {
        return ($this->requestBuild)($request)->toData();
    }

    public function find(string $buildId): ?BuildData
    {
        return Build::query()->with('builder')->find($buildId)?->toData();
    }

    public function status(string $buildId): ?BuildStatus
    {
        return Build::query()->find($buildId)?->status;
    }

    public function artifactFor(string $buildId, int $ttlSeconds = 3600): ?ArtifactData
    {
        $build = Build::query()->find($buildId);

        if (! $build || $build->mode !== 'native' || ! $build->hasArtifact()) {
            return null;
        }

        return new ArtifactData(
            $this->storage->downloadUrl((string) $build->artifact_key, $ttlSeconds),
            (string) $build->artifact_sha256,
            (int) $build->artifact_size,
            (string) ($build->artifact_format ?? 'tar.gz'),
        );
    }

    public function imageFor(string $buildId): ?ImageData
    {
        $build = Build::query()->find($buildId);

        if (! $build || $build->mode !== 'docker' || ! $build->hasArtifact() || $build->image_ref === null) {
            return null;
        }

        return new ImageData((string) $build->pinnedImage(), $this->pullCredentials($build));
    }

    public function composeFor(string $buildId): ?ComposeBuildData
    {
        $build = Build::query()->find($buildId);

        if (! $build || $build->mode !== 'docker' || ! $build->hasArtifact() || ! is_array($build->compose)) {
            return null;
        }

        return new ComposeBuildData(
            (string) ($build->compose['file'] ?? 'compose.yaml'),
            (string) ($build->compose['content'] ?? ''),
            array_map('strval', (array) ($build->compose['images'] ?? [])),
            $this->pullCredentials($build),
            is_array($build->compose['assets'] ?? null) ? array_values($build->compose['assets']) : null,
            array_values(array_map('strval', (array) ($build->compose['missing'] ?? []))),
        );
    }

    public function registryAuth(string $organizationId, string $images): ?array
    {
        return $this->registry->pullCredentials($organizationId, $images);
    }

    /** @return ?array{server: string, username: string, password: string} pull for the build's own site */
    private function pullCredentials(Build $build): ?array
    {
        return $this->registry->credentials([$this->registry->name($build->site_slug)], ['pull'], (int) config('builds.registry.credential_ttl', 3600));
    }

    public function cancel(string $buildId): bool
    {
        $build = Build::query()->find($buildId);

        return $build !== null && ($this->cancelBuild)($build, 'Cancelled with its deployment.');
    }

    public function output(string $buildId, int $afterSeq = 0, int $limit = 1000): array
    {
        return BuildLog::query()->where('build_id', $buildId)->where('id', '>', $afterSeq)->orderBy('id')->limit($limit)->get()
            ->map(fn (BuildLog $log) => $log->toLine())->values()->all();
    }
}
