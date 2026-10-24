<?php

namespace Falak\Builds\Application;

use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Deployments\Contracts\RetainedImages;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deletes images of the built-in registry that nothing needs any more (docs/INSTALL.md → Registry storage).
 *
 * Tags are build ids (`<ns>/<site>:<build>`, `<ns>/<site>/<service>:<build>`). A tag stays when its build still has
 * its artifact (the newest FALAK_ARTIFACTS_KEEP per site, see PruneArtifacts), is not finished, is younger than a day,
 * or a release may still run it (Deployments' RetainedImages: pending, live and rollback releases); a deleted site's
 * builds go after the grace period. Manifests are deleted by digest, and never a digest a kept tag points at: a
 * repository with a tag whose digest can't be read is left alone, and a digest is re-read right before its delete.
 * Space comes back with the registry's garbage collection (`falak-ctl registry gc`, weekly).
 */
final class RegistryPruner
{
    private const MANIFEST_TYPES = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        .'application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';

    public function __construct(
        private readonly Registry $registry,
        private readonly RetainedImages $retained,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @return array{deleted: list<string>, kept: int, skipped: ?string}
     */
    public function prune(bool $dryRun = false): array
    {
        if ($this->registry->auth() === null) {
            return ['deleted' => [], 'kept' => 0, 'skipped' => 'The built-in registry has no credentials (FALAK_REGISTRY_USERNAME / FALAK_REGISTRY_PASSWORD).'];
        }

        $namespace = trim((string) config('builds.registry.namespace', 'falak'), '/');
        [$inUseTags, $inUseDigests] = $this->inUse();
        $deleted = [];
        $kept = 0;

        foreach ($this->repositories() as $repository) {
            if ($namespace !== '' && ! str_starts_with($repository, $namespace.'/')) {
                continue;
            }

            $tags = $this->tags($repository);
            $scan = $this->scan($repository, $tags, $inUseTags, $inUseDigests);

            if ($scan === null) {
                // A tag whose digest can't be read might share it with a tag to drop: nothing of this repository goes.
                Log::warning('registry prune: skipping a repository with unreadable manifests', ['repository' => $repository]);
                $kept += count($tags);

                continue;
            }

            [$keepDigests, $dropDigests] = $scan;
            $kept += count($tags) - array_sum(array_map('count', $dropDigests));

            if (! $dryRun) {
                // Tags pushed since the scan may point at a digest to drop (a reused image): they are read too.
                $new = array_values(array_diff($this->tags($repository), $tags));
                $late = $this->scan($repository, $new, $inUseTags, $inUseDigests);

                if ($late === null) {
                    Log::warning('registry prune: skipping a repository with unreadable manifests', ['repository' => $repository]);

                    continue;
                }

                $keepDigests += $late[0];
                $kept += count($new);
            }

            // A digest a kept tag points at stays, whatever other tags point at it too.
            foreach (array_diff_key($dropDigests, $keepDigests) as $digest => $dropTags) {
                $name = "{$repository}@{$digest} (".implode(', ', $dropTags).')';

                if (! $dryRun) {
                    // Re-read right before deleting: a tag moved since the scan keeps the digest.
                    foreach ($dropTags as $tag) {
                        if ($this->digest($repository, $tag) !== $digest) {
                            Log::info('registry prune: tag changed since the scan, keeping the digest', ['repository' => $repository, 'tag' => $tag, 'digest' => $digest]);

                            continue 2;
                        }
                    }

                    $response = $this->http($repository)->delete($this->url("/v2/{$repository}/manifests/{$digest}"));

                    if ($response->status() === 405) {
                        return ['deleted' => $deleted, 'kept' => $kept, 'skipped' => 'The registry does not allow deletes (REGISTRY_STORAGE_DELETE_ENABLED).'];
                    }

                    if (! $response->successful() && $response->status() !== 404) {
                        throw new RuntimeException("Deleting {$name} failed: HTTP {$response->status()}.");
                    }
                }

                $deleted[] = $name;
            }
        }

        Log::info($dryRun ? 'registry prune (dry run)' : 'registry prune', ['deleted' => count($deleted), 'kept' => $kept, 'manifests' => $deleted]);

        return ['deleted' => $deleted, 'kept' => $kept, 'skipped' => null];
    }

    /**
     * Digests of a repository's tags: the ones a kept tag points at, and the others with their tags. Null when a
     * digest can't be read.
     *
     * @param  list<string>  $tags
     * @param  array<string, list<string>>  $inUseTags
     * @param  array<string, list<string>>  $inUseDigests
     * @return ?array{0: array<string, true>, 1: array<string, list<string>>}
     */
    private function scan(string $repository, array $tags, array $inUseTags, array $inUseDigests): ?array
    {
        $keepDigests = [];
        $dropDigests = [];

        foreach ($tags as $tag) {
            $digest = $this->digest($repository, $tag);

            if ($digest === null) {
                return null;
            }

            $keep = in_array($tag, $inUseTags[$repository] ?? [], true)
                || in_array($digest, $inUseDigests[$repository] ?? [], true)
                || $this->buildNeeded($tag);

            if ($keep) {
                $keepDigests[$digest] = true;
            } else {
                $dropDigests[$digest][] = $tag;
            }
        }

        return [$keepDigests, $dropDigests];
    }

    /** Whether the build behind a tag still needs its image. Tags that aren't build ids are never touched. */
    private function buildNeeded(string $tag): bool
    {
        if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $tag) !== 1) {
            return true;
        }

        $build = Build::query()->find($tag);

        if ($build === null) {
            // No record (deleted organization, pruned rows): the image goes once it is past the grace period.
            return self::ulidTime($tag) > now()->subDays($this->graceDays())->getTimestamp();
        }

        if (in_array($build->status, BuildStatus::active(), true) || $build->created_at->greaterThan(now()->subDay())) {
            return true;
        }

        if ($build->status !== BuildStatus::Succeeded || $build->artifact_pruned_at !== null) {
            return false;
        }

        // The site's newest builds keep their artifact; a deleted site's go after the grace period.
        return $this->sites->find($build->site_id) !== null || $build->created_at->greaterThan(now()->subDays($this->graceDays()));
    }

    /**
     * Repositories and their tags / digests that releases may still run, without the registry host.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function inUse(): array
    {
        $tags = [];
        $digests = [];

        foreach ($this->retained->images() as $image) {
            $image = strtolower(trim($image));

            if (str_contains($image, '@')) {
                [$image, $digest] = explode('@', $image, 2);
            }

            $tag = null;
            $slash = strrpos($image, '/');
            $colon = strrpos($image, ':');

            if ($colon !== false && ($slash === false || $colon > $slash)) {
                $tag = substr($image, $colon + 1);
                $image = substr($image, 0, $colon);
            }

            $parts = explode('/', $image);

            if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
                array_shift($parts);
            }

            $repository = implode('/', $parts);

            if (isset($digest)) {
                $digests[$repository][] = $digest;
                unset($digest);
            }

            if ($tag !== null) {
                $tags[$repository][] = $tag;
            }
        }

        return [$tags, $digests];
    }

    /** @return list<string> */
    private function repositories(): array
    {
        $repositories = [];
        $path = '/v2/_catalog?n=1000';

        for ($page = 0; $path !== null && $page < 100; $page++) {
            $response = $this->http()->get($this->url($path))->throw();
            array_push($repositories, ...array_map('strval', (array) ($response->json('repositories') ?? [])));
            $path = self::nextPage($response);
        }

        return $repositories;
    }

    /** @return list<string> */
    private function tags(string $repository): array
    {
        $response = $this->http($repository)->get($this->url("/v2/{$repository}/tags/list"));

        return $response->status() === 404 ? [] : array_map('strval', (array) ($response->throw()->json('tags') ?? []));
    }

    private function digest(string $repository, string $tag): ?string
    {
        $response = $this->http($repository)->withHeaders(['Accept' => self::MANIFEST_TYPES])->head($this->url("/v2/{$repository}/manifests/{$tag}"));
        $digest = strtolower((string) $response->header('Docker-Content-Digest'));

        return $response->successful() && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : null;
    }

    /** A bearer token minted here (this is the token server) for the catalog, or pull + delete on one repository. */
    private function http(?string $repository = null): PendingRequest
    {
        $access = $repository === null
            ? ['type' => 'registry', 'name' => 'catalog', 'actions' => ['*']]
            : ['type' => 'repository', 'name' => $repository, 'actions' => ['pull', 'delete']];

        return Http::withToken($this->registry->token('falak-prune', [$access]))->timeout(30)->acceptJson();
    }

    private function url(string $path): string
    {
        $scheme = str_starts_with(strtolower((string) config('builds.registry.url')), 'http://') ? 'http' : 'https';

        return "{$scheme}://{$this->registry->url()}{$path}";
    }

    private function graceDays(): int
    {
        return max(1, (int) config('builds.registry.deleted_site_grace_days', 7));
    }

    private static function nextPage(Response $response): ?string
    {
        // Link: </v2/_catalog?last=x&n=1000>; rel="next"
        return preg_match('/<([^>]+)>;\s*rel="?next"?/', (string) $response->header('Link'), $m) === 1 ? $m[1] : null;
    }

    /** Seconds since the epoch encoded in a (Crockford base32) ULID. */
    private static function ulidTime(string $ulid): int
    {
        $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
        $ms = 0;

        foreach (str_split(substr($ulid, 0, 10)) as $char) {
            $ms = $ms * 32 + (int) strpos($alphabet, $char);
        }

        return intdiv($ms, 1000);
    }
}
