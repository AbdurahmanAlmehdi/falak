<?php

namespace Falak\Templates\Domain;

/**
 * A parsed, schema-valid template (template.yaml + compose.yaml). Whether the compose file satisfies the
 * template (variables, public services, policy, pinned images) is checked by the TemplateValidator.
 */
final readonly class Template
{
    /**
     * @param  list<array{service: string, port: int, health_check_path?: string}>  $public
     * @param  list<TemplateInput>  $inputs
     * @param  list<string>  $tags
     * @param  ?string  $icon  Cool Icons key (slug or slug/variant), or "./icon.svg" (catalog only)
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $description,
        public Category $category,
        public ?string $icon,
        public ?string $docs,
        public bool $stateful,
        public ?int $minMemoryMb,
        public bool $popular,
        public array $tags,
        public array $public,
        public array $inputs,
        public string $templateYaml,
        public string $composeYaml,
        public TemplateSource $source = TemplateSource::Catalog,
        /** Custom templates: their database id. */
        public ?string $id = null,
        /** Catalog templates: absolute path of icon.svg when the template ships one. */
        public ?string $iconPath = null,
        /** The oldest Falak release that runs this template (catalog: older panels skip it). */
        public ?string $minFalak = null,
    ) {}

    public function input(string $key): ?TemplateInput
    {
        foreach ($this->inputs as $input) {
            if ($input->key === $key) {
                return $input;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function inputKeys(): array
    {
        return array_map(fn (TemplateInput $input) => $input->key, $this->inputs);
    }

    /**
     * @return list<string>
     */
    public function publicServices(): array
    {
        return array_map(fn (array $public) => $public['service'], $this->public);
    }

    public function withSource(TemplateSource $source, ?string $id = null, ?string $iconPath = null): self
    {
        return new self(
            $this->slug, $this->name, $this->version, $this->description, $this->category, $this->icon, $this->docs,
            $this->stateful, $this->minMemoryMb, $this->popular, $this->tags, $this->public, $this->inputs,
            $this->templateYaml, $this->composeYaml, $source, $id, $iconPath, $this->minFalak,
        );
    }
}
