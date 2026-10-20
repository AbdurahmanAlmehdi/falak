<?php

namespace Falak\Templates\Infrastructure;

use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateSource;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads templates/<slug>/{template.yaml, compose.yaml, icon.svg?}. File contents are cached under a key derived
 * from the files' paths, sizes and modification times (edits are picked up without clearing the cache); parsed
 * templates are memoized per process. Templates that fail to parse are skipped and logged — the catalog test
 * (TemplateCatalogTest) keeps them from shipping.
 */
final class FilesystemCatalog implements Catalog
{
    /** @var ?array<string, Template> */
    private ?array $templates = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly TemplateParser $parser,
        private readonly string $path,
        private readonly int $ttl = 3600,
    ) {}

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

        foreach (glob(rtrim($this->path, '/').'/*/template.yaml') ?: [] as $templateFile) {
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
        if ($this->templates !== null) {
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

        return $this->templates = $templates;
    }

    /**
     * Whether a panel of version $panel (null: development, no release) can run the template. Reads only
     * min_falak, before strict parsing: a template for a newer Falak may use keys this panel does not know yet.
     */
    public static function supported(string $templateYaml, ?string $panel): bool
    {
        if ($panel === null) {
            return true;
        }

        try {
            $min = Yaml::parse($templateYaml)['min_falak'] ?? null;
        } catch (ParseException) {
            return true; // reported by the parser
        }

        return ! is_string($min) || preg_match('/^\d+\.\d+\.\d+$/', $min) !== 1 || version_compare(ltrim($panel, 'v'), $min, '>=');
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
        $parts = [$this->path];

        foreach (glob(rtrim($this->path, '/').'/*/{template.yaml,compose.yaml,icon.svg}', GLOB_BRACE) ?: [] as $file) {
            $parts[] = $file.':'.filesize($file).':'.filemtime($file);
        }

        return sha1(implode('|', $parts));
    }
}
