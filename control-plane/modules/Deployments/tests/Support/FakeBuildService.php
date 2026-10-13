<?php

namespace Falak\Deployments\Tests\Support;

use DateTimeImmutable;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\ArtifactData;
use Falak\Builds\Contracts\Data\BuildData;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Contracts\Data\ComposeBuildData;
use Falak\Builds\Contracts\Data\ImageData;
use Falak\Builds\Events\BuildCancelled;
use Falak\Builds\Events\BuildFailed;
use Falak\Builds\Events\BuildSucceeded;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Str;

/**
 * In-memory BuildService: builds stay queued until the test succeeds / fails them (firing the real
 * Builds events), unless `$autoSucceed` is set.
 */
final class FakeBuildService implements BuildService
{
    /** @var array<string, array{request: BuildRequest, status: BuildStatus, commit: ?string, error: ?string, org: string}> */
    public array $builds = [];

    public bool $autoSucceed = false;

    /** When set, request() refuses every build with this message (as BuildConfiguration does for a leaking secret). */
    public ?string $refuse = null;

    /** Compose file a compose build "finds" in the repository, and the services it builds. */
    public string $composeContent = "services:\n  app:\n    build: .\n";

    /** @var ?list<array{path: string, content: string, mode: int}> repository files a newer builder ships */
    public ?array $composeAssets = null;

    /** @var array<string, string> */
    public array $composeImages = ['app' => 'registry.falak.local/falak/shop/app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    public string $sha256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function install(): self
    {
        $fake = new self;
        app()->instance(BuildService::class, $fake);

        return $fake;
    }

    public function request(BuildRequest $request): BuildData
    {
        if ($this->refuse !== null) {
            throw new \RuntimeException($this->refuse);
        }

        $id = strtolower((string) Str::ulid());
        $site = app(SiteDirectory::class)->find($request->siteId);
        $this->builds[$id] = [
            'request' => $request,
            'status' => $this->autoSucceed ? BuildStatus::Succeeded : BuildStatus::Queued,
            'commit' => $request->commit ?? str_repeat('c', 40),
            'error' => null,
            'org' => (string) $site?->organizationId,
        ];

        return $this->data($id);
    }

    public function find(string $buildId): ?BuildData
    {
        return isset($this->builds[$buildId]) ? $this->data($buildId) : null;
    }

    public function status(string $buildId): ?BuildStatus
    {
        return $this->builds[$buildId]['status'] ?? null;
    }

    public function artifactFor(string $buildId, int $ttlSeconds = 3600): ?ArtifactData
    {
        return ($this->builds[$buildId]['status'] ?? null) === BuildStatus::Succeeded
            ? new ArtifactData("https://falak.test/api/internal/artifacts/{$buildId}.tar.gz?signature=x", $this->sha256, 1234)
            : null;
    }

    public function imageFor(string $buildId): ?ImageData
    {
        return ($this->builds[$buildId]['status'] ?? null) === BuildStatus::Succeeded
            ? new ImageData("registry.falak.local/falak/app@sha256:{$this->sha256}", ['server' => 'registry.falak.local', 'username' => 'falak', 'password' => 'secret'])
            : null;
    }

    public function composeFor(string $buildId): ?ComposeBuildData
    {
        return ($this->builds[$buildId]['status'] ?? null) === BuildStatus::Succeeded
            ? new ComposeBuildData('compose.yaml', $this->composeContent, $this->composeImages, ['server' => 'registry.falak.local', 'username' => 'falak', 'password' => 'secret'], $this->composeAssets)
            : null;
    }

    public function cancel(string $buildId): bool
    {
        if (! isset($this->builds[$buildId]) || $this->builds[$buildId]['status']->isTerminal()) {
            return false;
        }

        $this->builds[$buildId]['status'] = BuildStatus::Cancelled;
        $b = $this->builds[$buildId];
        BuildCancelled::dispatch($buildId, $b['org'], $b['request']->siteId, $b['request']->deploymentId);

        return true;
    }

    public function output(string $buildId, int $afterSeq = 0, int $limit = 1000): array
    {
        return [];
    }

    public function last(): string
    {
        return (string) array_key_last($this->builds);
    }

    public function succeed(?string $buildId = null): void
    {
        $buildId ??= $this->last();
        $this->builds[$buildId]['status'] = BuildStatus::Succeeded;
        $b = $this->builds[$buildId];
        BuildSucceeded::dispatch($buildId, $b['org'], $b['request']->siteId, 'native', $b['commit'], $b['request']->deploymentId, 1000);
    }

    public function fail(?string $buildId = null, string $error = 'npm run build failed'): void
    {
        $buildId ??= $this->last();
        $this->builds[$buildId]['status'] = BuildStatus::Failed;
        $this->builds[$buildId]['error'] = $error;
        $b = $this->builds[$buildId];
        BuildFailed::dispatch($buildId, $b['org'], $b['request']->siteId, 'shop', 'failed', $error, $b['commit'], $b['request']->deploymentId);
    }

    private function data(string $id): BuildData
    {
        $b = $this->builds[$id];

        return new BuildData($id, $b['org'], $b['request']->siteId, 'native', $b['status'], $b['request']->branch, $b['commit'], $b['request']->deploymentId,
            false, $b['error'], 'local', new DateTimeImmutable, null, null,
            $b['status'] === BuildStatus::Succeeded ? "registry.falak.local/falak/app@sha256:{$this->sha256}" : null);
    }
}
