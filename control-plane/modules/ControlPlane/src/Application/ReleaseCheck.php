<?php

namespace Falak\ControlPlane\Application;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is there a newer Falak? GitHub's latest release of config('controlplane.repository') (no token: 60 requests an
 * hour per IP is plenty for one check every 6 h), cached. Drafts and pre-releases never count. The release notes are
 * the release body as plain text; the UI renders them as text, never as HTML.
 *
 * @phpstan-type Release array{tag: ?string, url: ?string, published_at: ?string, notes: ?string, checked_at: string, error: ?string}
 */
final class ReleaseCheck
{
    public const CACHE_KEY = 'controlplane:latest-release';

    private const STABLE = '/^v\d+\.\d+\.\d+$/';

    private const NOTES_LIMIT = 20000;

    public function enabled(): bool
    {
        return (bool) config('controlplane.update_check', true);
    }

    public function currentVersion(): ?string
    {
        $version = trim((string) config('controlplane.version'));

        if ($version === '' && is_readable($file = (string) config('controlplane.version_file'))) {
            $version = trim((string) file_get_contents($file));
        }

        return $version !== '' ? $version : null;
    }

    /**
     * @return Release|null
     */
    public function latest(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public function updateAvailable(): bool
    {
        return self::newer($this->latest()['tag'] ?? null, $this->currentVersion());
    }

    /**
     * Ask GitHub now. A failure keeps the last known release and records the error.
     *
     * @return Release
     */
    public function check(): array
    {
        $previous = $this->latest();
        $result = ['tag' => $previous['tag'] ?? null, 'url' => $previous['url'] ?? null, 'published_at' => $previous['published_at'] ?? null, 'notes' => $previous['notes'] ?? null, 'checked_at' => now()->toIso8601String(), 'error' => null];

        try {
            $response = Http::timeout(10)->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'falak-control-plane/'.($this->currentVersion() ?? 'unknown'), 'Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/repos/'.config('controlplane.repository').'/releases/latest');

            if (! $response->successful()) {
                $result['error'] = 'GitHub answered HTTP '.$response->status().'.';
            } else {
                $result = [...$result, ...self::parse((array) $response->json())];
            }
        } catch (Throwable $e) {
            $result['error'] = 'Could not reach GitHub: '.$e->getMessage();
        }

        Cache::forever(self::CACHE_KEY, $result);

        return $result;
    }

    /**
     * The fields we keep from a GitHub release; a draft, a pre-release or a non-release tag is an error, not a release.
     *
     * @param  array<string, mixed>  $release
     * @return array{tag?: string, url?: ?string, published_at?: ?string, notes?: ?string, error: ?string}
     */
    public static function parse(array $release): array
    {
        $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';

        if (($release['draft'] ?? false) || ($release['prerelease'] ?? false) || preg_match(self::STABLE, $tag) !== 1) {
            return ['error' => 'The latest release on GitHub ('.($tag !== '' ? mb_substr($tag, 0, 40) : 'no tag').') is not a stable release; ignored.'];
        }

        $url = is_string($release['html_url'] ?? null) && str_starts_with($release['html_url'], 'https://github.com/') ? $release['html_url'] : null;
        $notes = is_string($release['body'] ?? null) ? trim($release['body']) : '';

        return [
            'tag' => $tag,
            'url' => $url,
            'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
            'notes' => $notes !== '' ? mb_strimwidth($notes, 0, self::NOTES_LIMIT, '…') : null,
            'error' => null,
        ];
    }

    /**
     * Semver: is $candidate (a stable vX.Y.Z) newer than $current (vX.Y.Z, optionally -pre)? Anything unparsable
     * (a "dev" build) is never behind.
     */
    public static function newer(?string $candidate, ?string $current): bool
    {
        $semver = '/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/';

        if (preg_match($semver, (string) $candidate, $c) !== 1 || preg_match($semver, (string) $current, $v) !== 1) {
            return false;
        }

        return version_compare($c[1], $v[1], '>');
    }
}
