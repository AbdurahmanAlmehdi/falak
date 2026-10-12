<?php

namespace Falak\Functions\Infrastructure;

use Falak\Deployments\Contracts\Data\FunctionSource;
use Falak\Deployments\Contracts\FunctionSources;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Functions\Domain\Models\FunctionVersionCommit;

/**
 * Deployments' view of function code: a version plus the function's current runtime image, scaling and limits. A
 * function of a falak.yaml is also found by the repository commit its code was read at.
 */
final class StoredFunctionSources implements FunctionSources
{
    public function __construct(private readonly FunctionStore $functions) {}

    public function head(string $siteId): ?FunctionSource
    {
        $function = $this->functions->find($siteId);
        $version = $function?->head();

        return $function !== null && $version !== null ? $this->source($function, $version) : null;
    }

    public function find(string $siteId, string $hash): ?FunctionSource
    {
        $function = $this->functions->find($siteId);
        $version = $function !== null
            ? FunctionVersion::query()->where('function_id', $function->id)->where('hash', strtolower($hash))->orderByDesc('number')->first()
            : null;

        if ($function !== null && $version === null && ($id = FunctionVersionCommit::query()->where('function_id', $function->id)->where('commit', strtolower($hash))->value('version_id')) !== null) {
            $version = FunctionVersion::query()->find($id);
        }

        return $function !== null && $version !== null ? $this->source($function, $version) : null;
    }

    private function source(CloudFunction $function, FunctionVersion $version): FunctionSource
    {
        return new FunctionSource(
            hash: $version->hash,
            number: $version->number,
            message: $version->message,
            author: $version->author_name,
            runtime: $function->runtime,
            image: (string) config("functions.runtimes.{$function->runtime}.image"),
            entrypoint: $version->entrypoint,
            files: $version->files,
            scaling: $function->scaling(),
            limits: $function->limits(),
            access: $function->access(),
        );
    }
}
