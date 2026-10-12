<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ChangeSet;
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
            // [decision] A rollback never applies a risky change (it has no approval): e.g. a release made before a
            // volume was added would unmount it. Nor does it create data resources (a volume, a database unit, a
            // database): a new, empty one at an old mount path or name would stand in for data the file detached.
            // Those are skipped and listed as warnings.
            $skip = array_values(array_filter($plan->changes->changes, fn (Change $c) => $c->risky || self::createsData($c)));
            $safe = new ChangeSet(array_values(array_filter($plan->changes->changes, fn (Change $c) => ! in_array($c, $skip, true))));
            $skipped = array_map(fn (Change $c) => ConfigIssue::warning('rollback.risky_skipped', $c->path, $c->risky
                ? "Not restored by the rollback ({$c->rule}): {$c->reason}"
                : 'Not restored by the rollback: it would create an empty data resource; add it again with a push.')->toArray(), $skip);
            $run->forceFill(['plan' => EnvMask::plan([...$plan->toArray(), 'deploy' => []]), 'deploy' => [], ...($skipped !== [] ? ['errors' => $skipped] : [])])->save();

            DB::transaction(function () use ($scope, $safe, $run, $unit, $resolved, $releaseId) {
                $scope = $this->applier->phaseA($scope, $safe, new ApplyContext($run->id, ApplyPhase::BeforeBuild, $run->commit), $unit);
                $this->applier->phaseB($scope, $resolved, new ApplyContext($run->id, ApplyPhase::WithRelease, $run->commit, null, false, $unit, null, $releaseId), $unit);
            });

            $run->forceFill(['status' => RunStatus::Applied, 'phase_a_at' => now(), 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('config: restoring a release config failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->forceFill(['status' => RunStatus::Failed, 'error' => 'Restoring the release\'s config failed: '.$e->getMessage(), 'finished_at' => now()])->save();
        }

        return $run;
    }

    /** A Create of a data resource: a database or redis unit, a volume, a database of a unit. */
    private static function createsData(Change $change): bool
    {
        if ($change->op !== ChangeOp::Create) {
            return false;
        }

        $tokens = Pointer::tokens($change->path);

        return (count($tokens) === 2 && $tokens[0] === 'units' && in_array(is_array($change->after) ? ($change->after['type'] ?? null) : null, ['database', 'redis'], true))
            || (count($tokens) === 4 && $tokens[0] === 'units' && in_array($tokens[2], ['volumes', 'databases'], true));
    }
}
