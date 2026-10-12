<?php

namespace Falak\Sites\Contracts;

/**
 * Framework presets: defaults for runtime, web directory, deploy script, shared paths and toggles.
 */
enum Framework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case Statamic = 'statamic';
    case WordPress = 'wordpress';
    case Php = 'php';
    case Next = 'next';
    case Nuxt = 'nuxt';
    case Node = 'node';
    case Static = 'static';
    case Docker = 'docker';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::Statamic => 'Statamic',
            self::WordPress => 'WordPress',
            self::Php => 'Plain PHP',
            self::Next => 'Next.js',
            self::Nuxt => 'Nuxt',
            self::Node => 'Generic Node',
            self::Static => 'Static site',
            self::Docker => 'Docker',
        };
    }

    public function isLaravel(): bool
    {
        return $this === self::Laravel || $this === self::Statamic;
    }

    /**
     * The framework a falak.yaml unit declares for a new site: its `framework`, else Laravel for a PHP runtime with a
     * `laravel` block (what `falak init` writes for a Laravel repository), else null (the runtime's default).
     *
     * @param  array<string, mixed>  $unit
     */
    public static function declaredBy(array $unit, ?SiteRuntime $runtime): ?self
    {
        if (isset($unit['framework'])) {
            return self::tryFrom((string) $unit['framework']);
        }

        return $runtime?->isPhp() === true && is_array($unit['laravel'] ?? null) ? self::Laravel : null;
    }

    public function isPhp(): bool
    {
        return in_array($this, [self::Laravel, self::Symfony, self::Statamic, self::WordPress, self::Php], true);
    }
}
