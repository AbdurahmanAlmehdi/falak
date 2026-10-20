<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Domain\Category;
use Falak\Templates\Domain\Generator;
use Falak\Templates\Domain\InputType;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateInput;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * template.yaml → {@see Template}, enforcing the schema of docs/COMPOSE_TEMPLATES.md §2. Unknown keys are errors
 * (typos should not silently drop settings). A single "bundle" document may carry the compose file under `compose:`
 * (a string or a mapping) — the import format for pasted / uploaded / fetched templates.
 */
final class TemplateParser
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,49}$/';

    public const KEY_PATTERN = '/^[A-Z_][A-Z0-9_]{0,127}$/';

    private const KEYS = ['name', 'slug', 'version', 'description', 'category', 'icon', 'docs', 'stateful', 'min_memory_mb', 'min_falak', 'popular', 'tags', 'public', 'inputs', 'compose'];

    private const INPUT_KEYS = ['key', 'type', 'label', 'description', 'default', 'generate', 'options', 'required', 'placeholder'];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  ?string  $composeYaml  null: take it from the bundle's `compose:` key
     *
     * @throws InvalidTemplate
     */
    public function parse(string $templateYaml, ?string $composeYaml = null): Template
    {
        $this->errors = [];

        try {
            $doc = Yaml::parse($templateYaml);
        } catch (ParseException $e) {
            throw new InvalidTemplate(['template.yaml: '.$e->getMessage()]);
        }

        if (! is_array($doc) || array_is_list($doc)) {
            throw new InvalidTemplate(['template.yaml: must be a mapping (name, slug, version, …)']);
        }

        foreach (array_diff(array_keys($doc), self::KEYS) as $unknown) {
            $this->error((string) $unknown, 'unknown key');
        }

        $compose = $this->compose($doc, $composeYaml);
        unset($doc['compose']);

        $slug = $this->string($doc, 'slug', 50);
        if ($slug !== null && preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->error('slug', 'use lowercase letters, digits and dashes (max 50)');
        }

        $name = $this->string($doc, 'name', 60);
        $version = $this->string($doc, 'version', 32);
        if ($version !== null && preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            $this->error('version', 'must be a semantic version like 1.0.0');
        }

        $description = $this->string($doc, 'description', 300);
        $category = null;
        $rawCategory = $this->string($doc, 'category', 32);
        if ($rawCategory !== null) {
            $category = Category::tryFrom($rawCategory);
            if ($category === null) {
                $this->error('category', 'must be one of '.implode(', ', array_column(Category::cases(), 'value')));
            }
        }

        $icon = $this->string($doc, 'icon', 64, required: false);
        if ($icon !== null && $icon !== './icon.svg' && preg_match('/^[a-z0-9]{1,64}$/', $icon) !== 1) {
            $this->error('icon', 'must be a simple-icons key (e.g. n8n) or ./icon.svg');
        }

        $docs = $this->string($doc, 'docs', 255, required: false);
        if ($docs !== null && (filter_var($docs, FILTER_VALIDATE_URL) === false || ! str_starts_with($docs, 'https://'))) {
            $this->error('docs', 'must be an https URL');
        }

        $stateful = $this->bool($doc, 'stateful');
        $popular = $this->bool($doc, 'popular');

        $minMemory = null;
        if (array_key_exists('min_memory_mb', $doc)) {
            if (! is_int($doc['min_memory_mb']) || $doc['min_memory_mb'] < 64 || $doc['min_memory_mb'] > 262144) {
                $this->error('min_memory_mb', 'must be an integer between 64 and 262144');
            } else {
                $minMemory = $doc['min_memory_mb'];
            }
        }

        $minFalak = $this->string($doc, 'min_falak', 32, required: false);
        if ($minFalak !== null && preg_match('/^\d+\.\d+\.\d+$/', $minFalak) !== 1) {
            $this->error('min_falak', 'must be a version like 1.2.0');
        }

        $tags = [];
        if (array_key_exists('tags', $doc)) {
            if (! is_array($doc['tags']) || ! array_is_list($doc['tags']) || count($doc['tags']) > 10) {
                $this->error('tags', 'must be a list of at most 10 words');
            } else {
                foreach ($doc['tags'] as $i => $tag) {
                    is_string($tag) && preg_match('/^[a-z0-9][a-z0-9 -]{0,23}$/', $tag) === 1 ? $tags[] = $tag : $this->error("tags[{$i}]", 'lowercase words, max 24 characters');
                }
            }
        }

        $public = $this->public($doc['public'] ?? null);
        $inputs = $this->inputs($doc['inputs'] ?? []);

        if ($this->errors !== []) {
            throw new InvalidTemplate($this->errors);
        }

        return new Template(
            slug: (string) $slug,
            name: (string) $name,
            version: (string) $version,
            description: (string) $description,
            category: $category ?? Category::DevTools,
            icon: $icon,
            docs: $docs,
            stateful: $stateful,
            minMemoryMb: $minMemory,
            popular: $popular,
            tags: $tags,
            public: $public,
            inputs: $inputs,
            templateYaml: $composeYaml === null ? $this->withoutCompose($templateYaml) : $templateYaml,
            composeYaml: $compose,
            minFalak: $minFalak,
        );
    }

    /**
     * @param  array<mixed>  $doc
     */
    private function compose(array $doc, ?string $composeYaml): string
    {
        if ($composeYaml !== null) {
            if (array_key_exists('compose', $doc)) {
                $this->error('compose', 'give the compose file either inline or as compose.yaml, not both');
            }

            if (trim($composeYaml) === '') {
                $this->errors[] = 'compose.yaml: is empty';
            }

            return $composeYaml;
        }

        $compose = $doc['compose'] ?? null;

        if (is_string($compose) && trim($compose) !== '') {
            return $compose;
        }

        if (is_array($compose) && $compose !== []) {
            return Yaml::dump($compose, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        }

        $this->error('compose', 'missing: add the compose file (compose.yaml) or a `compose:` key');

        return '';
    }

    /** template.yaml of a bundle: the document without its `compose:` key. */
    private function withoutCompose(string $templateYaml): string
    {
        $doc = Yaml::parse($templateYaml);
        unset($doc['compose']);

        return Yaml::dump($doc, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @return list<array{service: string, port: int, health_check_path?: string}>
     */
    private function public(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->error('public', 'list at least one public service: [{service, port}]');

            return [];
        }

        $public = [];
        $seen = [];

        foreach ($value as $i => $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['service', 'port', 'health_check_path']) !== []) {
                $this->error("public[{$i}]", 'must be {service, port, health_check_path?}');

                continue;
            }

            $service = $entry['service'] ?? null;
            $port = $entry['port'] ?? null;

            if (! is_string($service) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $service) !== 1) {
                $this->error("public[{$i}].service", 'must be a compose service name');

                continue;
            }

            if (! is_int($port) || $port < 1 || $port > 65535) {
                $this->error("public[{$i}].port", 'must be a port number');

                continue;
            }

            if (isset($seen[$service])) {
                $this->error("public[{$i}].service", "{$service} is listed twice (one public port per service)");

                continue;
            }

            // The deploy health check's path for this service (e.g. MinIO answers 403 on / without credentials).
            $health = $entry['health_check_path'] ?? null;

            if ($health !== null && (! is_string($health) || preg_match('#^/[^\s]{0,254}$#', $health) !== 1)) {
                $this->error("public[{$i}].health_check_path", 'must be a path starting with / (e.g. /health)');

                continue;
            }

            $seen[$service] = true;
            $public[] = ['service' => $service, 'port' => $port] + ($health !== null ? ['health_check_path' => $health] : []);
        }

        return $public;
    }

    /**
     * @return list<TemplateInput>
     */
    private function inputs(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->error('inputs', 'must be a list');

            return [];
        }

        $inputs = [];
        $keys = [];

        foreach ($value as $i => $raw) {
            $at = "inputs[{$i}]";

            if (! is_array($raw) || array_is_list($raw)) {
                $this->error($at, 'must be a mapping');

                continue;
            }

            foreach (array_diff(array_keys($raw), self::INPUT_KEYS) as $unknown) {
                $this->error("{$at}.{$unknown}", 'unknown key');
            }

            $key = $raw['key'] ?? null;

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $this->error("{$at}.key", 'must be an environment variable name (A-Z, 0-9, _)');

                continue;
            }

            $at = "inputs.{$key}";

            if (str_starts_with($key, 'FALAK_')) {
                $this->error("{$at}.key", 'FALAK_* variables are reserved');
            }

            if (isset($keys[$key])) {
                $this->error("{$at}.key", 'duplicate key');
            }
            $keys[$key] = true;

            $type = InputType::tryFrom((string) ($raw['type'] ?? 'string'));

            if ($type === null) {
                $this->error("{$at}.type", 'must be one of '.implode(', ', array_column(InputType::cases(), 'value')));

                continue;
            }

            $generate = null;
            if (isset($raw['generate'])) {
                if (! $type->canGenerate()) {
                    $this->error("{$at}.generate", 'only string and secret inputs can be generated');
                } else {
                    try {
                        $generate = Generator::parse((string) $raw['generate']);
                    } catch (InvalidArgumentException $e) {
                        $this->error("{$at}.generate", $e->getMessage());
                    }
                }
            }

            $options = [];
            if ($type === InputType::Select) {
                if (! is_array($raw['options'] ?? null) || ! array_is_list($raw['options']) || $raw['options'] === []) {
                    $this->error("{$at}.options", 'select inputs need a list of options');
                } else {
                    foreach ($raw['options'] as $option) {
                        if (! is_scalar($option) || is_bool($option)) {
                            $this->error("{$at}.options", 'options must be strings or numbers');

                            break;
                        }
                        $options[] = (string) $option;
                    }
                }
            } elseif (isset($raw['options'])) {
                $this->error("{$at}.options", 'only select inputs have options');
            }

            $default = null;
            if (array_key_exists('default', $raw) && $raw['default'] !== null) {
                if (! is_scalar($raw['default'])) {
                    $this->error("{$at}.default", 'must be a scalar');
                } else {
                    $default = is_bool($raw['default']) ? ($raw['default'] ? 'true' : 'false') : (string) $raw['default'];
                }
            }

            if ($default !== null && $generate !== null) {
                $this->error("{$at}.default", 'give either a default or generate, not both');
            }

            if ($type === InputType::Secret && $default !== null && ! str_contains($default, '${{')) {
                $this->error("{$at}.default", 'secrets cannot have a literal default; use generate or a ${{ service.KEY }} reference');
            }

            if ($type === InputType::Select && $default !== null && $options !== [] && ! in_array($default, $options, true)) {
                $this->error("{$at}.default", 'must be one of the options');
            }

            if ($type === InputType::Boolean && $default !== null && ! in_array($default, ['true', 'false'], true)) {
                $this->error("{$at}.default", 'must be true or false');
            }

            if ($type === InputType::Number && $default !== null && ! is_numeric($default)) {
                $this->error("{$at}.default", 'must be a number');
            }

            $label = isset($raw['label']) && is_string($raw['label']) ? mb_substr(trim($raw['label']), 0, 80) : TemplateInput::labelFor($key);
            $description = isset($raw['description']) && is_string($raw['description']) ? mb_substr(trim($raw['description']), 0, 300) : null;
            $placeholder = isset($raw['placeholder']) && is_scalar($raw['placeholder']) ? mb_substr((string) $raw['placeholder'], 0, 120) : null;
            $required = array_key_exists('required', $raw)
                ? (bool) $raw['required']
                : $default === null && $generate === null && $type !== InputType::Boolean;

            $inputs[] = new TemplateInput($key, $type, $label, $description, $default, $generate, $options, $required, $placeholder);
        }

        return $inputs;
    }

    /**
     * @param  array<mixed>  $doc
     */
    private function string(array $doc, string $key, int $max, bool $required = true): ?string
    {
        $value = $doc[$key] ?? null;

        if ($value === null || $value === '') {
            if ($required) {
                $this->error($key, 'is required');
            }

            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $this->error($key, 'must be a string');

            return null;
        }

        $value = trim((string) $value);

        if (mb_strlen($value) > $max) {
            $this->error($key, "must be at most {$max} characters");

            return null;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $doc
     */
    private function bool(array $doc, string $key): bool
    {
        if (! array_key_exists($key, $doc)) {
            return false;
        }

        if (! is_bool($doc[$key])) {
            $this->error($key, 'must be true or false');

            return false;
        }

        return $doc[$key];
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = "template.yaml: {$path} {$message}";
    }
}
