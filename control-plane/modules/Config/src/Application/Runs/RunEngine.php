<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A config run from start to end: validate → plan → (risky: refused while experimental, else awaiting approval) →
 * phase A → one deployment per unit that changed → phase B as each unit's release goes live (or right away for units
 * that are not deployed).
 */
final class RunEngine
{
    public function __construct(
        private readonly FileCheck $check,
        private readonly Planner $planner,
        private readonly ScopeFactory $scopes,
        private readonly Applier $applier,
        private readonly ConfigReleases $releases,
        private readonly AuditLog $audit,
        private readonly ProjectDirectory $projects,
        private readonly ImplicitEnvironment $implicit,
    ) {}

    /**
     * Create a run and take it as far as it goes now.
     *
     * @param  ?list<string>  $changedPaths  files the push changed (null: unknown, every unit counts as changed)
     * @param  array{message?: ?string, author?: ?string}  $commitInfo
     */
    public function start(
        ConfigSource $source,
        EnvironmentData $environment,
        string $raw,
        string $format,
        RunTrigger $trigger,
        ?string $commit = null,
        ?string $branch = null,
        ?string $requestedBy = null,
        bool $allowDestroy = false,
        ?array $changedPaths = null,
        array $commitInfo = [],
    ): ConfigRun {
        // Only a push deploys units for their code; other runs deploy the units whose config changed.
        $changedPaths = $trigger === RunTrigger::Push ? $changedPaths : [];

        $run = ConfigRun::query()->create([
            'organization_id' => $source->organization_id,
            'project_id' => $source->project_id,
            'environment_id' => $environment->id,
            'environment' => $environment->slug,
            'commit' => $commit,
            'branch' => $branch,
            'trigger' => $trigger,
            'status' => RunStatus::Planning,
            'file' => $raw,
            'format' => $format,
            'allow_destroy' => $allowDestroy,
            'requested_by' => $requestedBy,
            'started_at' => now(),
        ]);

        try {
            $validation = $this->check->check($raw, $format, $environment->slug,
                fn (array $resolved) => $this->scopes->make($environment, $source, $resolved, $commit, $requestedBy), $this->implicit->forCheck($source, $environment));
            $issues = array_map(fn (ConfigIssue $i) => $i->toArray(), [...$validation->errors, ...$validation->warnings]);

            if (! $validation->valid() || $validation->resolved === null) {
                return $this->finish($run, RunStatus::Failed, $validation->summary($source->path), ['errors' => $issues, 'resolved' => $validation->resolved]);
            }

            $scope = $this->scopes->make($environment, $source, $validation->resolved, $commit, $requestedBy);
            $plan = $this->planner->plan($scope, $validation->resolved);
            $deploy = $plan->deploy;

            foreach (array_keys((array) ($validation->resolved['units'] ?? [])) as $unit) {
                if (! in_array($unit, $deploy, true) && self::filesChanged($validation->resolved['units'][$unit], $changedPaths) && $scope->refId((string) $unit) !== null
                    && ($validation->resolved['units'][$unit]['type'] ?? null) === 'app') {
                    $deploy[] = (string) $unit;
                }
            }

            $planArray = [...$plan->toArray(), 'deploy' => array_values($deploy)];
            $run->forceFill(['resolved' => $validation->resolved, 'plan' => $planArray, 'errors' => $issues])->save();

            if ($plan->changes->isRisky()) {
                if ($source->experimental) {
                    $rules = implode(', ', array_unique(array_map(fn ($c) => (string) $c->rule, $plan->changes->risky())));

                    return $this->finish($run, RunStatus::Refused, "The plan has risky changes ({$rules}); while config as code is experimental they are refused. Make them in the dashboard, or split them out of the file.");
                }

                $run->forceFill(['status' => RunStatus::AwaitingApproval])->save();

                return $run;
            }

            $this->apply($run, $commitInfo);
        } catch (Throwable $e) {
            Log::warning('config: run failed', ['run' => $run->id, 'error' => $e->getMessage()]);

            return $this->finish($run, RunStatus::Failed, self::message($e));
        }

        return $run->refresh();
    }

    /**
     * Phase A, then the deployments (or phase B right away when nothing deploys).
     *
     * @param  array{message?: ?string, author?: ?string}  $commitInfo
     */
    public function apply(ConfigRun $run, array $commitInfo = []): ConfigRun
    {
        $source = ConfigSource::query()->where('project_id', $run->project_id)->first();
        $environment = $this->projects->environment((string) $run->environment_id);

        if ($source === null || $environment === null || $run->resolved === null) {
            return $this->finish($run, RunStatus::Failed, 'The project, environment or config source no longer exists.');
        }

        $run->forceFill(['status' => RunStatus::Applying])->save();
        $scope = $this->scopes->make($environment, $source, $run->resolved, $run->commit, $run->requested_by);
        $ctx = new ApplyContext($run->id, ApplyPhase::BeforeBuild, $run->commit, $run->requested_by, $run->allow_destroy);

        try {
            $scope = DB::transaction(fn () => $this->applier->phaseA($scope, $run->changes(), $ctx));
        } catch (Throwable $e) {
            return $this->finish($run, RunStatus::Failed, 'Phase A failed: '.self::message($e).' Nothing was deployed.');
        }

        $entries = [];

        foreach ((array) ($run->plan['deploy'] ?? []) as $unit) {
            $siteId = $scope->refId((string) $unit);
            $entry = ['unit' => (string) $unit, 'site_id' => $siteId, 'deployment_id' => null, 'status' => 'pending', 'error' => null];

            if ($siteId === null || ($scope->services[$unit]['kind'] ?? null) !== 'site') {
                $entry['status'] = 'skipped';
                $entries[] = $entry;

                continue;
            }

            try {
                $entry['deployment_id'] = $this->releases->deploy($siteId, $run->branch ?? $scope->branch, $run->commit,
                    $commitInfo['message'] ?? null, $commitInfo['author'] ?? null, $run->trigger === RunTrigger::Push ? null : $run->requested_by);
                $entry['status'] = 'deploying';
            } catch (ValidationException $e) {
                $entry['status'] = 'skipped';
                $entry['error'] = collect($e->errors())->flatten()->first();
            } catch (Throwable $e) {
                $entry['status'] = 'failed';
                $entry['error'] = self::message($e);
            }

            $entries[] = $entry;
        }

        $run->forceFill(['deploy' => $entries])->save();
        $this->audit->record('config.run_applied', 'project', $run->project_id, ['run_id' => $run->id, 'environment' => $run->environment, 'commit' => $run->commit,
            'summary' => $run->plan['summary'] ?? []], $run->organization_id, $run->requested_by);

        // Units that are not deployed (no deploy source, a deployment that failed to start, or a unit the file
        // removed) get phase B now.
        $deploying = array_column(array_filter($entries, fn (array $e) => $e['status'] === 'deploying'), 'unit');
        $units = array_unique(array_filter(array_map(fn ($c) => $c->unit, $run->changes()->actionable())));

        foreach ($units as $unit) {
            if (! in_array($unit, $deploying, true)) {
                try {
                    $this->phaseB($run, (string) $unit, null, null);
                } catch (Throwable $e) {
                    return $this->finish($run, RunStatus::Failed, "Phase B of {$unit} failed: ".self::message($e));
                }
            }
        }

        return $this->settle($run->refresh());
    }

    /**
     * A unit's release went live: phase B for it, then the run settles when every unit is done.
     */
    public function released(ConfigRun $run, string $deploymentId, string $releaseId): void
    {
        $unit = null;

        DB::transaction(function () use ($run, $deploymentId, &$unit) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);
            $entries = (array) $locked?->deploy;

            foreach ($entries as $i => $entry) {
                if (($entry['deployment_id'] ?? null) === $deploymentId && ($entry['status'] ?? null) === 'deploying') {
                    $entries[$i]['status'] = 'released';
                    $unit = $entry['unit'];
                }
            }

            $locked?->forceFill(['deploy' => $entries])->save();
        });

        if ($unit === null) {
            return;
        }

        $config = $run->unit($unit);

        if ($config !== null) {
            $this->releases->store($releaseId, $config);
        }

        try {
            $this->phaseB($run, $unit, $deploymentId, $releaseId);
        } catch (Throwable $e) {
            $this->entryFailed($run, $deploymentId, 'Phase B failed: '.self::message($e));
        }

        $this->settle($run->refresh());
    }

    public function deploymentFailed(ConfigRun $run, string $deploymentId, string $error): void
    {
        $this->entryFailed($run, $deploymentId, $error);
        $this->settle($run->refresh());
    }

    private function entryFailed(ConfigRun $run, string $deploymentId, string $error): void
    {
        DB::transaction(function () use ($run, $deploymentId, $error) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);
            $entries = (array) $locked?->deploy;

            foreach ($entries as $i => $entry) {
                if (($entry['deployment_id'] ?? null) === $deploymentId) {
                    $entries[$i]['status'] = 'failed';
                    $entries[$i]['error'] = $error;
                }
            }

            $locked?->forceFill(['deploy' => $entries])->save();
        });
    }

    private function phaseB(ConfigRun $run, string $unit, ?string $deploymentId, ?string $releaseId): void
    {
        $source = ConfigSource::query()->where('project_id', $run->project_id)->first();
        $environment = $this->projects->environment((string) $run->environment_id);

        if ($source === null || $environment === null || $run->resolved === null) {
            return;
        }

        $scope = $this->scopes->make($environment, $source, $run->resolved, $run->commit, $run->requested_by);
        $ctx = new ApplyContext($run->id, ApplyPhase::WithRelease, $run->commit, $run->requested_by, $run->allow_destroy, $unit, $deploymentId, $releaseId);
        DB::transaction(fn () => $this->applier->phaseB($scope, $run->resolved, $ctx, $unit));
    }

    /** Applied once every deployment settled (failed when one failed). */
    private function settle(ConfigRun $run): ConfigRun
    {
        if ($run->status !== RunStatus::Applying) {
            return $run;
        }

        $entries = (array) $run->deploy;

        if (array_filter($entries, fn (array $e) => in_array($e['status'] ?? null, ['pending', 'deploying'], true))) {
            return $run;
        }

        $failed = array_values(array_filter($entries, fn (array $e) => ($e['status'] ?? null) === 'failed'));

        if ($failed !== []) {
            return $this->finish($run, RunStatus::Failed, implode(' ', array_map(fn (array $e) => "{$e['unit']}: ".($e['error'] ?? 'the deployment failed.'), $failed)));
        }

        return $this->finish($run, RunStatus::Applied, null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(ConfigRun $run, RunStatus $status, ?string $error, array $extra = []): ConfigRun
    {
        $run->forceFill([...$extra, 'status' => $status, 'error' => $error, 'finished_at' => now()])->save();

        return $run;
    }

    private static function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', collect($e->errors())->flatten()->all());
        }

        return $e->getMessage();
    }

    /**
     * Whether a push changed files under the unit's root (every unit when the push didn't say which files).
     *
     * @param  array<string, mixed>  $unit
     * @param  ?list<string>  $paths
     */
    public static function filesChanged(array $unit, ?array $paths): bool
    {
        if ($paths === null) {
            return true;
        }

        $root = trim((string) ($unit['root'] ?? '.'), '/');

        if ($root === '' || $root === '.') {
            return $paths !== [];
        }

        foreach ($paths as $path) {
            if ($path === $root || str_starts_with(ltrim($path, '/'), $root.'/')) {
                return true;
            }
        }

        return false;
    }
}
