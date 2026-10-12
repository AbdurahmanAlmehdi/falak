<?php

namespace Falak\Config\Application\Jobs;

use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The scheduled drift check: one unique job per environment of every project that follows a file.
 */
final class CheckDrift implements ShouldQueue
{
    use Queueable;

    public function handle(ProjectDirectory $projects): void
    {
        foreach (ConfigSource::query()->where('enabled', true)->pluck('project_id') as $projectId) {
            foreach ($projects->environments((string) $projectId) as $environment) {
                if (! $environment->isPreview) {
                    CheckEnvironmentDrift::dispatch($environment->id);
                }
            }
        }
    }
}
