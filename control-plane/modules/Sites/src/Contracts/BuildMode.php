<?php

namespace Falak\Sites\Contracts;

enum BuildMode: string
{
    /** Railpack builds a release tarball on the builder. */
    case Native = 'native';
    /** BuildKit builds an image pushed to the built-in registry. */
    case Docker = 'docker';
    /** Build on the target server itself (needs ≥ 2 GB RAM, explicit opt-in). */
    case OnServer = 'on-server';

    /** Minimum memory for on-server builds. */
    public const ON_SERVER_MIN_MEMORY_BYTES = 2 * 1024 ** 3;

    public function label(): string
    {
        return match ($this) {
            self::Native => 'Native (Railpack)',
            self::Docker => 'Docker image',
            self::OnServer => 'Build on the site\'s server',
        };
    }
}
