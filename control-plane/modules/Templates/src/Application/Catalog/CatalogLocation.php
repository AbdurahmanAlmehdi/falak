<?php

namespace Falak\Templates\Application\Catalog;

/**
 * Which catalog copy the panel serves: the one `templates:sync` downloaded from FALAK_TEMPLATES_URL, or the one baked
 * into the image — whichever is newer, so an upgraded image is never held back by an old sync. A synced copy from
 * another URL (a fork that was switched off) is never used. Read on every call (one small file): long-lived
 * FrankenPHP workers pick up a sync without a restart.
 */
final class CatalogLocation
{
    public const POINTER = 'current.json';

    /**
     * @return array{path: string, version: ?string, source: 'remote'|'built-in', synced_at: ?string}
     */
    public function current(): array
    {
        $baked = (string) config('templates.catalog_path');
        $bakedVersion = is_file("{$baked}/VERSION") ? trim((string) file_get_contents("{$baked}/VERSION")) : null;
        $synced = $this->synced();

        if ($synced !== null && ($bakedVersion === null || version_compare($synced['version'], $bakedVersion, '>'))) {
            return ['path' => $synced['path'], 'version' => $synced['version'], 'source' => 'remote', 'synced_at' => $synced['synced_at']];
        }

        return ['path' => $baked, 'version' => $bakedVersion ?: null, 'source' => 'built-in', 'synced_at' => null];
    }

    /**
     * The synced copy, when it came from the configured URL and is still on disk.
     *
     * @return ?array{path: string, version: string, url: string, synced_at: string}
     */
    public function synced(): ?array
    {
        $root = (string) config('templates.synced_path');
        $url = (string) config('templates.remote_url');
        $pointer = is_file("{$root}/".self::POINTER) ? json_decode((string) file_get_contents("{$root}/".self::POINTER), true) : null;

        if ($url === '' || ! is_array($pointer) || ($pointer['url'] ?? null) !== $url || ! is_string($version = $pointer['version'] ?? null)
            || preg_match(CatalogSync::VERSION_PATTERN, $version) !== 1 || ! is_dir("{$root}/{$version}")) {
            return null;
        }

        return ['path' => "{$root}/{$version}", 'version' => $version, 'url' => $url, 'synced_at' => (string) ($pointer['synced_at'] ?? '')];
    }
}
