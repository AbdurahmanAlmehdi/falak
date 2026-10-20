<?php

namespace Falak\Templates\Infrastructure;

use Closure;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\CatalogLocation;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateSource;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads <catalog>/<slug>/{template.yaml, compose.yaml, icon.svg?} (the synced or the baked copy, {@see CatalogLocation}). File contents are cached under a key derived
 * from the files' paths, sizes and modification times (edits are picked up without clearing the cache); parsed
 * templates are memoized per process. Templates that fail to parse are skipped and logged — the catalog test
 * (TemplateCatalogTest) keeps them from shipping.
 */
final class FilesystemCatalog implements Catalog
{
    /** @var ?array<string, Template> */
    private ?array $templates = null;

    /** The directory $templates was read from: a sync switches it under a long-lived worker. */
    private ?string $loadedFrom = null;

    /**
     * @param  string|Closure(): string  $path  the catalog directory, or how to find it on every call ({@see CatalogLocation})
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly TemplateParser $parser,
        private readonly string|Closure $path,
        private readonly int $ttl = 3600,
    ) {}

    private function path(): string
    {
        return is_string($this->path) ? $this->path : ($this->path)();
    }

    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $slug): ?Template
    {
        return $this->load()[$slug] ?? null;
    }

    /**
     * Raw files of every template directory (for the catalog test and the cache).
     *
     * @return array<string, array{template: string, compose: string, icon: ?string}>
     */
    public function files(): array
    {
        $files = [];

        foreach (glob(rtrim($this->path(), '/').'/*/template.yaml') ?: [] as $templateFile) {
            $directory = dirname($templateFile);
            $compose = is_file("{$directory}/compose.yaml") ? (string) file_get_contents("{$directory}/compose.yaml") : '';
            $icon = is_file("{$directory}/icon.svg") ? "{$directory}/icon.svg" : null;

            $files[basename($directory)] = ['template' => (string) file_get_contents($templateFile), 'compose' => $compose, 'icon' => $icon];
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array<string, Template>
     */
    private function load(): array
    {
        $path = $this->path();

        if ($this->templates !== null && $this->loadedFrom === $path) {
            return $this->templates;
        }

        /** @var array<string, array{template: string, compose: string, icon: ?string}> $files */
        $files = $this->cache->remember('templates:catalog:'.$this->fingerprint(), $this->ttl, fn () => $this->files());
        $templates = [];
        $panel = self::panelVersion();

        foreach ($files as $directory => $file) {
            if (! self::supported($file['template'], $panel)) {
                continue;
            }

            try {
                $template = $this->parser->parse($file['template'], $file['compose']);
            } catch (InvalidTemplate $e) {
                Log::warning("Skipping catalog template {$directory}: {$e->getMessage()}");

                continue;
            }

            if ($template->slug !== $directory) {
                Log::warning("Skipping catalog template {$directory}: its slug is {$template->slug}");

                continue;
            }

            $templates[$template->slug] = $template->withSource(TemplateSource::Catalog, iconPath: $file['icon']);
        }

        uasort($templates, fn (Template $a, Template $b) => strcasecmp($a->name, $b->name));

        $this->loadedFrom = $path;

        return $this->templates = $templates;
    }

    /**
     * Whether a panel of version $panel (null: development, no release) can run the template. Reads only
     * min_falak, before strict parsing: a template for a newer Falak may use keys this panel does not know yet.
     */
    public static function supported(string $templateYaml, ?string $panel): bool
    {
        // Builds that are not a release (sim/local `dev`, CI `ci-<sha>`) run everything; v1.2.0-rc.1 counts as 1.2.0.
        if ($panel === null || preg_match('/^v?(\d+\.\d+\.\d+)/', $panel, $release) !== 1) {
            return true;
        }

        try {
            $min = Yaml::parse($templateYaml)['min_falak'] ?? null;
        } catch (ParseException) {
            return true; // reported by the parser
        }

        return ! is_string($min) || preg_match('/^\d+\.\d+\.\d+$/', $min) !== 1 || version_compare($release[1], $min, '>=');
    }

    /** The release this panel runs (as ControlPlane's ReleaseCheck reads it; modules share config, not internals). */
    private static function panelVersion(): ?string
    {
        $version = trim((string) config('controlplane.version'));

        if ($version === '' && is_readable($file = (string) config('controlplane.version_file'))) {
            $version = trim((string) file_get_contents($file));
        }

        return $version !== '' ? $version : null;
    }

    private function fingerprint(): string
    {
        $parts = [$path = $this->path()];

        foreach (glob(rtrim($path, '/').'/*/{template.yaml,compose.yaml,icon.svg}', GLOB_BRACE) ?: [] as $file) {
            $parts[] = $file.':'.filesize($file).':'.filemtime($file);
        }

        return sha1(implode('|', $parts));
    }
}
