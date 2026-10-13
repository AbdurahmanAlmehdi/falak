<?php

namespace Falak\Servers\Domain\Stack;

use Falak\Servers\Contracts\ServerType;

/**
 * Software selected for a server at creation. PHP versions installed later are tracked in
 * servers_php_versions; the stack keeps the runtime choice and the other components. Every server runs Docker, and
 * databases are containers (Databases), so neither is part of the stack.
 */
final readonly class Stack
{
    public const PHP_RUNTIMES = ['frankenphp', 'fpm'];

    /** Builders run PHP only for builds (Composer): the CLI, no web runtime. */
    public const PHP_CLI = 'cli';

    /**
     * @param  list<string>  $phpVersions
     */
    public function __construct(
        public ?string $phpRuntime = null,
        public array $phpVersions = [],
        public ?string $phpDefault = null,
        public ?string $node = null,
    ) {}

    public static function defaultsFor(ServerType $type): self
    {
        $php = (string) config('servers.default_php', '8.4');
        $node = (string) config('servers.default_node', '22');

        return match ($type) {
            ServerType::App, ServerType::Web, ServerType::Worker => new self('frankenphp', [$php], $php, $node),
            ServerType::Database, ServerType::Cache, ServerType::LoadBalancer => new self,
            ServerType::Builder => new self(node: $node),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $php = is_array($data['php'] ?? null) ? $data['php'] : null;
        $versions = array_values(array_unique(array_map('strval', (array) ($php['versions'] ?? []))));

        return new self(
            phpRuntime: $php ? (string) ($php['runtime'] ?? 'frankenphp') : null,
            phpVersions: $versions,
            phpDefault: $php ? (string) ($php['default'] ?? ($versions[0] ?? '')) ?: null : null,
            node: isset($data['node']) && $data['node'] !== '' ? (string) $data['node'] : null,
        );
    }

    /**
     * @return array{php: array{runtime: string, versions: list<string>, default: ?string}|null, node: ?string}
     */
    public function toArray(): array
    {
        return [
            'php' => $this->phpRuntime !== null ? ['runtime' => $this->phpRuntime, 'versions' => $this->phpVersions, 'default' => $this->phpDefault] : null,
            'node' => $this->node,
        ];
    }

    public function withPhp(string $runtime, array $versions, ?string $default): self
    {
        return new self($runtime, array_values($versions), $default, $this->node);
    }

    /**
     * @return array<string, string> field => error message (empty = valid)
     */
    public function errorsFor(ServerType $type): array
    {
        $allowed = $type->allowedComponents();
        $errors = [];

        $present = array_filter([
            'php' => $this->phpRuntime !== null,
            'node' => $this->node !== null,
        ]);

        foreach (array_keys($present) as $component) {
            if (! in_array($component, $allowed, true)) {
                $errors["stack.{$component}"] = "A {$type->label()} cannot include {$component}.";
            }
        }

        if ($this->phpRuntime !== null) {
            $offered = (array) config('servers.php_versions', []);

            if ($type === ServerType::Builder ? $this->phpRuntime !== self::PHP_CLI : ! in_array($this->phpRuntime, self::PHP_RUNTIMES, true)) {
                $errors['stack.php.runtime'] = $type === ServerType::Builder ? 'A builder runs the PHP CLI only (runtime "cli").' : 'Choose FrankenPHP or PHP-FPM.';
            }

            if ($this->phpVersions === [] || array_diff($this->phpVersions, $offered) !== []) {
                $errors['stack.php.versions'] = 'Choose one or more supported PHP versions.';
            }

            if ($this->phpDefault === null || ! in_array($this->phpDefault, $this->phpVersions, true)) {
                $errors['stack.php.default'] = 'The default PHP version must be one of the selected versions.';
            }
        }

        if ($this->node !== null && ! array_key_exists($this->node, (array) config('servers.node_versions', []))) {
            $errors['stack.node'] = 'Unsupported Node.js version.';
        }

        return $errors;
    }
}
