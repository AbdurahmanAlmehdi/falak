<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A rollback went live: the unit's config saved on the release it returned to is applied again (both phases, no
 * deployment: the release is already running), recorded as a `rollback` run.
 */
final class RollbackRestorer
{
    public function __construct(
        private readonly ConfigReleases $releases,
        private readonly ProjectDirectory $projects,
        private readonly ScopeFactory $scopes,
        private readonly Planner $planner,
        private readonly Applier $applier,
    ) {}

    public function restore(string $projectId, string $environmentId, string $unit, string $releaseId, ?string $commit): ?ConfigRun
    {
        $config = $this->releases->config($releaseId);
        $source = ConfigSource::enabledFor($projectId);
        $environment = $this->projects->environment($environmentId);

        if ($source === null || $environment === null) {
            return null;
        }

        if ($config === null) {
            // [decision] The release predates the file (or its config): say so on a run instead of restoring nothing silently.
            return ConfigRun::query()->create([
                'organization_id' => $source->organization_id,
                'project_id' => $projectId,
                'environment_id' => $environmentId,
                'environment' => $environment->slug,
                'commit' => $commit,
                'trigger' => RunTrigger::Rollback,
                'status' => RunStatus::Applied,
                'file' => '',
                'format' => $source->format(),
                'errors' => [ConfigIssue::warning('rollback.no_config', '/units/'.Pointer::escape($unit),
                    "The release {$unit} rolled back to predates its falak.yaml config: the config was not restored; today's settings stay.")->toArray()],
                'deploy' => [],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }

        $previous = ConfigRun::query()->where('environment_id', $environmentId)->whereNotNull('resolved')
            ->whereIn('status', [RunStatus::Applied, RunStatus::Applying])->latest()->first();
        $resolved = $previous?->resolved ?? ['version' => 1, 'units' => [], 'environments' => [$environment->slug => []]];
        $resolved['units'][$unit] = $config;

        $run = ConfigRun::query()->create([
            'organization_id' => $source->organization_id,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'environment' => $environment->slug,
            'commit' => $commit,
            'trigger' => RunTrigger::Rollback,
            'status' => RunStatus::Applying,
            'file' => $previous?->file ?? '',
            'format' => $previous?->format ?? $source->format(),
            'resolved' => $resolved,
            'started_at' => now(),
        ]);

        try {
            $scope = $this->scopes->make($environment, $source, $resolved, $run->commit);
            $plan = $this->planner->plan($scope, $resolved, $unit);
            $run->forceFill(['plan' => EnvMask::plan([...$plan->toArray(), 'deploy' => []]), 'deploy' => []])->save();

            DB::transaction(function () use ($scope, $plan, $run, $unit, $resolved, $releaseId) {
                $scope = $this->applier->phaseA($scope, $plan->changes, new ApplyContext($run->id, ApplyPhase::BeforeBuild, $run->commit), $unit);
                $this->applier->phaseB($scope, $resolved, new ApplyContext($run->id, ApplyPhase::WithRelease, $run->commit, null, false, $unit, null, $releaseId), $unit);
            });

            $run->forceFill(['status' => RunStatus::Applied, 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('config: restoring a release config failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->forceFill(['status' => RunStatus::Failed, 'error' => 'Restoring the release\'s config failed: '.$e->getMessage(), 'finished_at' => now()])->save();
        }

        return $run;
    }
}
