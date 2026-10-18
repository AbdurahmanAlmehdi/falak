<?php

namespace Falak\Sites\Contracts;

/**
 * How a site runs on its servers (ARCHITECTURE §5).
 */
enum SiteRuntime: string
{
    case FrankenPhp = 'frankenphp';
    case PhpFpm = 'php-fpm';
    case Node = 'node';
    case Bun = 'bun';
    case Deno = 'deno';
    case Static = 'static';
    case Docker = 'docker';
    case Compose = 'compose';
    /** Code written in Falak (no repository), run by the server's function gateway and scaled to zero when idle. */
    case Function = 'function';

    public function label(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
            self::PhpFpm => 'PHP-FPM',
            self::Node => 'Node.js',
            self::Bun => 'Bun',
            self::Deno => 'Deno',
            self::Static => 'Static',
            self::Docker => 'Docker',
            self::Compose => 'Docker Compose',
            self::Function => 'Function',
        };
    }

    public function isPhp(): bool
    {
        return $this === self::FrankenPhp || $this === self::PhpFpm;
    }

    /** Runtimes whose app listens on a local port that the edge reverse-proxies to. */
    public function proxiesToPort(): bool
    {
        return in_array($this, [self::Node, self::Bun, self::Deno, self::Docker, self::Compose], true);
    }

    public function isContainer(): bool
    {
        return $this === self::Docker || $this === self::Compose;
    }

    public function isFunction(): bool
    {
        return $this === self::Function;
    }

    /** Runtimes that run in Docker on the server (containers and functions): no native processes, Docker required. */
    public function usesDocker(): bool
    {
        return $this->isContainer() || $this === self::Function;
    }

    /** Whether the edge serves files from a document root. */
    public function servesFiles(): bool
    {
        return $this->isPhp() || $this === self::Static;
    }

    /**
     * @return list<BuildMode>
     */
    public function buildModes(): array
    {
        return match (true) {
            // Docker sites can also build their image on the leader server (Builds' server-pinned builder).
            $this === self::Docker => [BuildMode::Docker, BuildMode::OnServer],
            $this->usesDocker() => [BuildMode::Docker],
            default => [BuildMode::Native, BuildMode::OnServer],
        };
    }
}
