<?php

namespace Falak\Templates\Application\Catalog;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Downloads the published catalog (falak-sh/templates releases: catalog.json + catalog.tar.gz) into
 * templates.synced_path/<version>/ and points current.json at it. Every failure leaves the last good copy in place.
 */
final class CatalogSync
{
    public const VERSION_PATTERN = '/^\d{1,4}(\.\d{1,9}){1,5}$/';

    public function __construct(private readonly Http $http, private readonly CatalogLocation $location) {}

    /**
     * @return string 'disabled' | 'unchanged' | 'updated:<version>'
     *
     * @throws RuntimeException a bad or failed download (the current catalog stays)
     */
    public function sync(): string
    {
        $root = (string) config('templates.synced_path');
        $url = (string) config('templates.remote_url');
        $current = $this->location->synced();

        if ($url === '' || $current === null) {
            // Remote off, or a copy from another URL: it must never be served again.
            File::deleteDirectory($root, preserve: true);
        }

        if ($url === '') {
            return 'disabled';
        }

        $index = $this->get("{$url}/catalog.json")->json();
        $version = is_array($index) ? ($index['version'] ?? null) : null;
        $sha256 = is_array($index) ? ($index['sha256'] ?? null) : null;

        if (! is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1 || ! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException("{$url}/catalog.json is not a catalog index (version + sha256).");
        }

        if ($current !== null && $current['version'] === $version) {
            return 'unchanged';
        }

        // ponytail: the archive is held in memory, capped at templates.max_archive_bytes (the catalog is ~1 MB).
        $archive = $this->get("{$url}/catalog.tar.gz")->body();
        $max = (int) config('templates.max_archive_bytes');

        if (strlen($archive) > $max) {
            throw new RuntimeException("The catalog archive is larger than {$max} bytes.");
        }

        if (! hash_equals($sha256, hash('sha256', $archive))) {
            throw new RuntimeException('The catalog archive does not match its sha256.');
        }

        $this->install($root, $version, $archive);
        File::put("{$root}/".CatalogLocation::POINTER.'.tmp', (string) json_encode(['version' => $version, 'url' => $url, 'synced_at' => now()->toIso8601String()]));
        rename("{$root}/".CatalogLocation::POINTER.'.tmp', "{$root}/".CatalogLocation::POINTER);

        // Keep the new copy and the one before it (a request may still be reading it).
        foreach (File::directories($root) as $directory) {
            if (! in_array(basename($directory), [$version, $current['version'] ?? null], true)) {
                File::deleteDirectory($directory);
            }
        }

        return "updated:{$version}";
    }

    private function install(string $root, string $version, string $archive): void
    {
        File::ensureDirectoryExists($root);
        $staging = "{$root}/{$version}.tmp";
        File::deleteDirectory($staging);
        $file = "{$root}/catalog-{$version}.tar.gz";
        File::put($file, $archive);

        try {
            // PharData keeps every entry inside $staging ("../x" and "/x" land inside it) and writes link entries as
            // plain files — CatalogSyncTest pins both.
            (new PharData($file))->extractTo($staging, null, true);
        } catch (Throwable $e) {
            File::deleteDirectory($staging);

            throw new RuntimeException("The catalog archive could not be read: {$e->getMessage()}");
        } finally {
            File::delete($file);
        }

        if (glob("{$staging}/*/template.yaml") === []) {
            File::deleteDirectory($staging);

            throw new RuntimeException('The catalog archive holds no templates.');
        }

        File::deleteDirectory("{$root}/{$version}");
        rename($staging, "{$root}/{$version}");
    }

    private function get(string $url): Response
    {
        $response = $this->http->timeout((int) config('templates.fetch.timeout', 10))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("{$url} answered {$response->status()}.");
        }

        return $response;
    }
}
