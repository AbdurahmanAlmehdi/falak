<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\SourceControl\Contracts\SourceControlGateway;
use stdClass;

/**
 * **[decision]** A file without `environments` binds the project's production environment to the repository's
 * default branch: a push to that branch applies it there, and plans for production work as if the file said
 * `environments: {<production slug>: {branch: <default branch>}}`.
 */
final class ImplicitEnvironment
{
    /** @var array<string, ?string> */
    private array $branches = [];

    public function __construct(
        private readonly SourceControlGateway $gateway,
        private readonly ProjectDirectory $projects,
    ) {}

    public static function applies(mixed $document): bool
    {
        if (! $document instanceof stdClass) {
            return false;
        }

        return ! isset($document->environments) || ! $document->environments instanceof stdClass || (array) $document->environments === [];
    }

    public function defaultBranch(ConfigSource $source): ?string
    {
        return $this->branches[$source->id] ??= rescue(fn () => $this->gateway->repository($source->source_connection_id, $source->repository)?->defaultBranch, null, false);
    }

    /** The project's production environment, when the file binds nothing and $branch is the default branch. */
    public function environmentFor(ConfigSource $source, string $branch): ?EnvironmentData
    {
        if ($branch !== $this->defaultBranch($source)) {
            return null;
        }

        foreach ($this->projects->environments($source->project_id) as $environment) {
            if ($environment->isProduction && ! $environment->isPreview) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * What FileCheck adds to a file without environments for $environment (null: nothing, it isn't production).
     *
     * @return ?array{slug: string, branch: ?string}
     */
    public function forCheck(ConfigSource $source, EnvironmentData $environment): ?array
    {
        return $environment->isProduction ? ['slug' => $environment->slug, 'branch' => $this->defaultBranch($source)] : null;
    }
}
