<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Application\Compose\ComposeDocument;
use Falak\Templates\Domain\Template;

/**
 * The curated catalog's own quality bar, on top of {@see TemplateValidator} (which every template, custom ones
 * included, passes). Authoring and CI only (templates:render, the main repo's catalog checks): the panel does not
 * apply these when loading the catalog, so a stricter rule in a newer panel never hides a published template.
 */
final class CatalogRules
{
    /**
     * @param  ?string  $iconFile  path of the template's icon.svg, when it ships one
     * @return list<string>
     */
    public function problems(Template $template, ?string $iconFile): array
    {
        $slug = $template->slug;
        $problems = [];

        $compose = ComposeDocument::parse($template->composeYaml);
        $namedVolumes = array_keys((array) ($compose->data['volumes'] ?? []));

        foreach ($compose->services() as $name => $service) {
            if (! array_key_exists('healthcheck', $service)) {
                $problems[] = "{$slug}: services.{$name} needs a healthcheck";
            }

            if (($service['restart'] ?? null) !== 'unless-stopped') {
                $problems[] = "{$slug}: services.{$name} should restart unless-stopped";
            }

            foreach ((array) ($service['volumes'] ?? []) as $volume) {
                $source = explode(':', (string) $volume)[0];

                if (! in_array($source, $namedVolumes, true)) {
                    $problems[] = "{$slug}: {$name} mounts {$source}, which is not a named volume";
                }
            }
        }

        if ($template->stateful && $namedVolumes === []) {
            $problems[] = "{$slug} is stateful but keeps no data in named volumes";
        }

        if ($template->minMemoryMb === null) {
            $problems[] = "{$slug}: set min_memory_mb";
        }

        if ($template->docs === null) {
            $problems[] = "{$slug}: link the docs";
        }

        if (mb_strlen($template->description) > 120) {
            $problems[] = "{$slug}: keep the description to one line";
        }

        if ($template->icon === './icon.svg') {
            if ($iconFile === null || ! is_file($iconFile)) {
                $problems[] = "{$slug}: icon.svg is missing";
            } elseif (preg_match('/<script|on[a-z]+=|href=/i', (string) file_get_contents($iconFile)) === 1) {
                $problems[] = "{$slug}: icon.svg must not carry scripts, event handlers or links";
            }
        }

        return $problems;
    }
}
