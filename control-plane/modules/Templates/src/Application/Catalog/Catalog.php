<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Domain\Template;

/**
 * The curated catalog (github.com/falak-sh/templates): the synced or the baked copy, {@see CatalogLocation}.
 */
interface Catalog
{
    /**
     * @return list<Template> sorted by name
     */
    public function all(): array;

    public function find(string $slug): ?Template;
}
