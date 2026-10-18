<?php

namespace Falak\Builds\Application;

use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use RuntimeException;

/**
 * What a site's build depends on: mode, build-time environment and the cache key that identifies
 * equivalent builds (same site + commit + configuration ⇒ the artifact can be reused).
 */
final class BuildConfiguration
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly VariableReferences $references,
        private readonly Secrets $secrets,
    ) {}

    /** The builder mode; on-server builds run the same native / docker build on a builder on the site's server. */
    public static function mode(SiteData $site): string
    {
        return match ($site->buildMode) {
            BuildMode::Native => 'native',
            BuildMode::Docker => 'docker',
            BuildMode::OnServer => $site->runtime->usesDocker() ? 'docker' : 'native',
        };
    }

    /**
     * Build-time environment: site variables with a public front-end prefix (VITE_…, NEXT_PUBLIC_…) plus the
     * ones exposed to the deploy script (the user's opt-in for other build-time settings, e.g. Astro's SITE_URL),
     * with `${{ service.KEY }}` references resolved (unresolvable ones stay literal; the deploy fails on them).
     * Only those variables are resolved, and secrets they read are logged as read by $accessor.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when a public front-end variable would carry a sensitive secret into the build
     */
    public function environment(SiteData $site, ?SecretAccessor $accessor = null): array
    {
        $environment = $this->sites->environment($site->id);
        $variables = $environment->variables ?? [];
        $exposed = array_flip($environment->exposedToDeployScript ?? []);

        $public = array_filter($variables, fn ($value, $key) => isset($exposed[$key]) || self::isPublic((string) $key), ARRAY_FILTER_USE_BOTH);

        if ($public === []) {
            return [];
        }

        // Only the build's variables; self-references still see the full set.
        $resolved = $this->secrets->accessedAs(
            $accessor ?? SecretAccessor::system('Build configuration'),
            fn () => $this->references->resolveForSite($site->id, $variables, array_map('strval', array_keys($public))),
        );

        // Front-end variables end up in the shipped bundle: a write-only secret must never get there.
        $leaking = array_values(array_filter($resolved->sensitiveKeys, fn (string $key) => self::isPublic($key)));

        if ($leaking !== []) {
            throw new RuntimeException(implode(', ', $leaking).' would put a sensitive secret into the public front-end build ('
                .implode(', ', (array) config('builds.env_prefixes', [])).' variables are shipped to browsers). Reference a non-sensitive secret, or rename the variable.');
        }

        return array_intersect_key($resolved->variables, $public);
    }

    /** A variable with a public front-end prefix (builds.env_prefixes): its value is shipped to browsers. */
    public static function isPublic(string $key): bool
    {
        foreach ((array) config('builds.env_prefixes', []) as $prefix) {
            if (str_starts_with($key, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Native build command overrides from the site's variables (as Railway's RAILPACK_*_CMD): FALAK_INSTALL_COMMAND
     * replaces the detected dependency install, FALAK_BUILD_COMMAND the build step (both run with `sh -c`).
     *
     * @return array{install_command?: string, build_command?: string}
     */
    public function commands(SiteData $site): array
    {
        $variables = $this->sites->environment($site->id)->variables ?? [];

        return array_filter([
            'install_command' => trim((string) ($variables['FALAK_INSTALL_COMMAND'] ?? '')),
            'build_command' => trim((string) ($variables['FALAK_BUILD_COMMAND'] ?? '')),
        ], fn (string $command) => $command !== '');
    }

    public function cacheKey(SiteData $site, string $mode, ?string $commit, ?SecretAccessor $accessor = null): string
    {
        $env = $this->environment($site, $accessor);
        ksort($env);
        $commands = $mode === 'native' ? $this->commands($site) : [];

        return hash('sha256', (string) json_encode([
            'v' => 1,
            'site' => $site->id,
            'mode' => $mode,
            'commit' => $commit !== null ? strtolower($commit) : null,
            'repository' => $site->repository,
            'runtime' => $site->runtime->value,
            'php' => $site->phpVersion,
            'node' => $site->nodeVersion,
            'dockerfile' => $site->dockerfile,
            'compose_file' => $site->compose?->file,
            // Only multi-file / profile projects add keys, so existing builds keep their fingerprint.
            ...(count($site->compose->files ?? []) > 1 || ($site->compose->profiles ?? []) !== [] ? ['compose_files' => $site->compose->files ?? [], 'compose_profiles' => $site->compose->profiles ?? []] : []),
            // The job builds from the root directory (subdir): another folder is another artifact. Unset adds no key.
            ...($site->rootDirectory !== null && $site->rootDirectory !== '' ? ['root_directory' => $site->rootDirectory] : []),
            'env' => hash('sha256', (string) json_encode($env)),
        ] + ($commands === [] ? [] : ['commands' => $commands])));
    }

    /** Runtime hint for falak-builder (php|node|bun|deno|static). */
    public static function runtimeHint(SiteData $site): ?string
    {
        return match (true) {
            $site->runtime->isPhp() => 'php',
            $site->runtime->value === 'node' => 'node',
            $site->runtime->value === 'bun' => 'bun',
            $site->runtime->value === 'deno' => 'deno',
            $site->runtime->value === 'static' => 'static',
            default => null,
        };
    }
}
