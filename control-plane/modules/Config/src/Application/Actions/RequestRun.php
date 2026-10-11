<?php

namespace Falak\Config\Application\Actions;

use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Illuminate\Validation\ValidationException;

/**
 * A run asked for by a person (the CLI's `falak apply`, the dashboard's "Apply now"): from a file they send (commit
 * null: the units deploy their branch's head), or from the file at a commit.
 */
final class RequestRun
{
    public function __construct(
        private readonly FileFetcher $files,
        private readonly RunEngine $engine,
    ) {}

    public function __invoke(ConfigSource $source, EnvironmentData $environment, ?string $file, ?string $format, ?string $commit, RunTrigger $trigger, ?string $userId, bool $allowDestroy = false): ConfigRun
    {
        if ($file === null) {
            [$file, $problem] = $this->files->fetch($source, (string) $commit);

            if ($file === null) {
                throw ValidationException::withMessages(['commit' => $problem]);
            }

            $format = $source->format();
        }

        return $this->engine->start($source, $environment, $file, $format === 'json' ? 'json' : 'yaml', $trigger, $commit, null, $userId, $allowDestroy);
    }
}
