<?php

namespace Falak\Builds\Application\Actions;

use Falak\Builds\Application\BuildConfiguration;
use Falak\Builds\Application\BuildProgress;
use Falak\Builds\Application\LocalBuilder;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Queue a build for a site, or reuse an identical earlier build whose artifact is still retained. On-server builds
 * are pinned to the site's leader server: built by the builder there (installed on demand, or the control plane's
 * own builder on the control-plane machine); the artifact still goes through the artifact store / registry, so
 * every target (replicated sites) deploys it as usual.
 */
final class RequestBuild
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly BuildConfiguration $configuration,
        private readonly BuildProgress $progress,
        private readonly ServerDirectory $servers,
        private readonly InstallServerBuilder $install,
        private readonly LocalBuilder $local,
    ) {}

    public function __invoke(BuildRequest $request): Build
    {
        $site = $this->sites->find($request->siteId) ?? throw new InvalidArgumentException('The site does not exist.');
        $mode = BuildConfiguration::mode($site);

        if ($site->repository === null || $site->sourceConnectionId === null) {
            throw new InvalidArgumentException('The site has no repository to build.');
        }

        $commit = $request->commit !== null ? strtolower($request->commit) : null;
        $cacheKey = $this->configuration->cacheKey($site, $mode, $commit, $request->deploymentId !== null
            ? SecretAccessor::deployment($request->deploymentId)
            : SecretAccessor::system('Build requested'));
        $branch = $request->branch ?? $site->branch;

        if ($commit !== null && $request->allowReuse) {
            $previous = Build::query()
                ->where('site_id', $site->id)
                ->where('cache_key', $cacheKey)
                ->where('status', BuildStatus::Succeeded)
                ->whereNull('artifact_pruned_at')
                ->latest()
                ->first();

            if ($previous !== null && $previous->hasArtifact()) {
                $build = Build::query()->create([
                    ...$previous->only(['organization_id', 'site_id', 'site_slug', 'mode', 'repository', 'cache_key', 'resolved_commit', 'artifact_key',
                        'artifact_sha256', 'artifact_size', 'artifact_format', 'image_ref', 'image_digest', 'manifest', 'compose', 'builder_id']),
                    'id' => strtolower((string) Str::ulid()),
                    'deployment_id' => $request->deploymentId,
                    'status' => BuildStatus::Succeeded,
                    'branch' => $branch,
                    'commit' => $commit,
                    'reused_build_id' => $previous->reused_build_id ?? $previous->id,
                    'timeout_s' => $previous->timeout_s,
                    'exit_code' => 0,
                    'duration_ms' => 0,
                    'requested_by' => $request->requestedBy,
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);

                $this->progress->log($build, ["Reusing the artifact of build {$build->reused_build_id} (same commit and build configuration).\n"]);

                return $build;
            }
        }

        $pin = $site->buildMode === BuildMode::OnServer ? $this->pin($site, $mode) : null;

        return DB::transaction(fn () => Build::query()->create([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $site->organizationId,
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'deployment_id' => $request->deploymentId,
            'mode' => $mode,
            'builder_server_id' => $pin,
            'status' => BuildStatus::Queued,
            'repository' => $site->repository,
            'branch' => $branch,
            'commit' => $commit,
            'cache_key' => $cacheKey,
            'timeout_s' => max(60, min(3600 * 3, (int) config('builds.timeout', 1800))),
            'requested_by' => $request->requestedBy,
        ]));
    }

    /**
     * The server an on-server build runs on (the leader), with a builder there to claim it.
     */
    private function pin(SiteData $site, string $mode): string
    {
        $serverId = $site->leader()?->serverId ?? throw new InvalidArgumentException('The site has no leader server to build on.');
        $server = $this->servers->find($serverId) ?? throw new InvalidArgumentException('The site\'s leader server no longer exists.');

        if ($server->controlPlaneHost) {
            // The control-plane machine already runs a capped builder: pin to it rather than installing a second one.
            $token = (string) config('builds.local_builder.token');

            if ($token === '') {
                throw new InvalidArgumentException('On-server builds on the control-plane machine use its builder; set FALAK_LOCAL_BUILDER_TOKEN, or build natively.');
            }

            $local = $this->local->resolve($token);

            if (! $local->enabled) {
                throw new InvalidArgumentException("The control plane's builder is disabled.");
            }

            if (! $local->supports($mode)) {
                throw new InvalidArgumentException("The control plane's builder does not build {$mode} (FALAK_LOCAL_BUILDER_MODES).");
            }

            if ($local->server_id !== $serverId) {
                // One builder process per machine.
                Builder::query()->where('server_id', $serverId)->whereKeyNot($local->id)->delete();
                $local->forceFill(['server_id' => $serverId])->save();
            }

            return $serverId;
        }

        // Locked: two first builds at once must not install twice (each install rotates the token).
        $builder = Cache::lock("builds:server-builder:{$serverId}", 30)->block(10, fn () => Builder::query()->where('server_id', $serverId)->first()
            ?? ($this->install)($server, pool: false));

        if (! $builder->enabled) {
            throw new InvalidArgumentException("The builder on {$server->name} is disabled; enable it on the Builders page.");
        }

        return $serverId;
    }
}
