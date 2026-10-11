<?php

namespace Falak\Config\Application\Jobs;

use Falak\Config\Application\Drift\DriftChecker;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduled drift check of every environment of every project that follows a file.
 */
final class CheckDrift implements ShouldQueue
{
    use Queueable;

    public function handle(DriftChecker $checker, ProjectDirectory $projects): void
    {
        foreach (ConfigSource::query()->where('enabled', true)->get() as $source) {
            foreach ($projects->environments($source->project_id) as $environment) {
                if ($environment->isPreview) {
                    continue;
                }

                try {
                    $checker->check($environment);
                } catch (Throwable $e) {
                    Log::warning('config: drift check failed', ['environment' => $environment->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
