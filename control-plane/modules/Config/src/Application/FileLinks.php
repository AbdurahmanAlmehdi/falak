<?php

namespace Falak\Config\Application;

use Falak\Config\Domain\Models\ConfigSource;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;

/**
 * Links to a source's file at a commit on its git provider (null for plain git servers).
 */
final class FileLinks
{
    public function __construct(private readonly SourceControlGateway $gateway) {}

    public function file(ConfigSource $source, string $commit): ?string
    {
        $connection = rescue(fn () => $this->gateway->connection($source->source_connection_id), null, false);

        if ($connection === null) {
            return null;
        }

        $base = $connection->baseUrl !== null && ! str_contains($connection->baseUrl, '/api') ? rtrim($connection->baseUrl, '/') : null;
        $path = ltrim($source->path, '/');

        return match ($connection->provider) {
            ProviderType::GitHub => ($base ?? 'https://github.com')."/{$source->repository}/blob/{$commit}/{$path}",
            ProviderType::GitLab => ($base ?? 'https://gitlab.com')."/{$source->repository}/-/blob/{$commit}/{$path}",
            ProviderType::Bitbucket => "https://bitbucket.org/{$source->repository}/src/{$commit}/{$path}",
            ProviderType::Custom => null,
        };
    }
}
