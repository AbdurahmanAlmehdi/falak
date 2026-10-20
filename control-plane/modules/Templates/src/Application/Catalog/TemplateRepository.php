<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateSource;
use Illuminate\Support\Facades\Log;

/**
 * Templates an organization can deploy: the curated catalog plus its own custom templates.
 */
final class TemplateRepository
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TemplateParser $parser,
    ) {}

    /**
     * @return list<Template>
     */
    public function catalog(): array
    {
        return $this->catalog->all();
    }

    /**
     * @return list<Template>
     */
    public function custom(string $organizationId): array
    {
        return CustomTemplate::query()->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (CustomTemplate $row) => $this->fromModel($row))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A template by slug. Without an explicit source the organization's own template wins: the catalog may gain the
     * same slug at any hourly sync, and a call that deployed the custom one must keep doing so.
     */
    public function find(string $organizationId, string $slug, ?TemplateSource $source = null): ?Template
    {
        if ($source !== TemplateSource::Catalog) {
            $row = CustomTemplate::query()->where('organization_id', $organizationId)->where('slug', $slug)->first();

            if ($row !== null || $source === TemplateSource::Custom) {
                return $row !== null ? $this->fromModel($row) : null;
            }
        }

        return $this->catalog->find($slug);
    }

    public function fromModel(CustomTemplate $row): ?Template
    {
        try {
            return $this->parser->parse($row->template_yaml, $row->compose_yaml)->withSource(TemplateSource::Custom, $row->id);
        } catch (InvalidTemplate $e) {
            Log::warning("Custom template {$row->id} no longer parses: {$e->getMessage()}");

            return null;
        }
    }
}
