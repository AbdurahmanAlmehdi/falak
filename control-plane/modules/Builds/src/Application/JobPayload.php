<?php

namespace Falak\Builds\Application;

use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Domain\Models\Build;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\SourceControl\Contracts\SourceControlGateway;
use RuntimeException;

/**
 * The falak-builder job (agent/internal/builder/job.go `Job`) for an assigned build. Clone
 * credentials come from SourceControl at hand-out time and are never persisted.
 */
final class JobPayload
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SourceControlGateway $sourceControl,
        private readonly ArtifactStorage $storage,
        private readonly BuildConfiguration $configuration,
        private readonly Registry $registry,
        private readonly SecretVariables $secrets,
    ) {}

    public static function artifactKey(Build $build): string
    {
        return strtolower("{$build->organization_id}/{$build->site_id}/{$build->id}.tar.gz");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the site or its repository credentials are unavailable
     */
    public function for(Build $build): array
    {
        $site = $this->sites->find($build->site_id) ?? throw new RuntimeException('The site no longer exists.');

        if ($site->sourceConnectionId === null || $site->repository === null) {
            throw new RuntimeException('The site has no repository.');
        }

        $credentials = $this->sourceControl->checkoutCredentials($site->sourceConnectionId, $site->repository, $site->deployKeyId);

        $repo = array_filter([
            'url' => $credentials->url,
            'ref' => $build->branch,
            'commit' => $build->commit,
            'deploy_key' => $credentials->sshPrivateKey,
            'known_hosts' => $credentials->knownHosts,
            'token' => $credentials->usesSsh() ? null : $credentials->httpsPassword,
            'username' => $credentials->usesSsh() ? null : $credentials->httpsUsername,
        ], fn ($value) => $value !== null && $value !== '');

        $job = [
            'id' => $build->id,
            // Shared builders run each organization's build steps as its own uid, with its own caches.
            'organization_id' => $build->organization_id,
            'mode' => $build->mode,
            'repo' => $repo,
            'timeout_s' => $build->timeout_s,
        ];

        if ($site->rootDirectory !== null && $site->rootDirectory !== '') {
            // The app root inside the repository: native builds run and package there, Docker uses it as the context
            // (and resolves the Dockerfile and compose file from it); the release is that folder.
            $job['subdir'] = $site->rootDirectory;
        }

        if (($hint = BuildConfiguration::runtimeHint($site)) !== null && $build->mode === 'native') {
            $job['runtime'] = $hint;
        }

        $env = $this->configuration->environment($site, SecretAccessor::build($build->id, $build->deployment_id));

        if ($env !== []) {
            $job['env'] = (object) $env;
            // The builder masks these variables' values in the build log (env and build args carry the same ones).
            $mask = array_values(array_filter(
                array_unique([...$this->secrets->names($this->sites->environment($site->id)->variables ?? []), ...$this->secrets->names($env)]),
                fn (string $name) => array_key_exists($name, $env),
            ));

            if ($mask !== []) {
                sort($mask);
                $job['mask'] = $mask;
            }
        }

        if ($build->mode === 'docker' && $site->runtime === SiteRuntime::Compose) {
            // Every `build:` service of the repository's compose file, pushed as <repo>/<slug>/<service>:<build id>.
            // Several files / profiles need a builder that merges projects (older builders reject the fields).
            $files = $site->compose->files ?? [];
            $profiles = $site->compose->profiles ?? [];
            $job['compose'] = array_filter([
                'file' => count($files) > 1 || $profiles !== [] ? null : $site->compose?->file,
                'files' => count($files) > 1 || ($profiles !== [] && $files !== []) ? $files : null,
                'profiles' => $profiles === [] ? null : $profiles,
                'image_prefix' => $this->registry->repository($site->slug),
                'tag' => strtolower($build->id),
                'build_args' => $env === [] ? null : (object) $env,
                'registry' => $this->registry->auth(),
            ], fn ($value) => $value !== null && $value !== '');
        } elseif ($build->mode === 'docker') {
            $docker = array_filter([
                'image' => $this->registry->image($site->slug, $build->id),
                'dockerfile' => $site->dockerfile,
                'build_args' => $env === [] ? null : (object) $env,
                'registry' => $this->registry->auth(),
                'push' => true,
            ], fn ($value) => $value !== null && $value !== '');
            $job['docker'] = $docker;
        } else {
            $key = $build->artifact_key ?? self::artifactKey($build);
            $upload = $this->storage->uploadTarget($key, max((int) config('builds.artifacts.upload_ttl', 3600), $build->timeout_s + 600));
            $job['native'] = ['upload' => array_filter(['url' => $upload['url'], 'headers' => $upload['headers'] === [] ? null : (object) $upload['headers']])]
                + $this->configuration->commands($site);
        }

        return $job;
    }
}
