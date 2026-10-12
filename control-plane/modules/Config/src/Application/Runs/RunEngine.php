<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Application\EnvMask;
use Falak\Config\Application\Planning\Plan;
use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Events\ConfigRunAwaitingApproval;
use Falak\Config\Events\ConfigRunFailed;
use Falak\Config\Http\Presenters;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Cache;
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
        private readonly SiteDirectory $sites,
        private readonly ApprovalNotice $notice,
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

            $scope = $this->scopes->make($environment, $source, $validation->resolved, $commit, $requestedBy, $allowDestroy);
            $plan = $this->planner->plan($scope, $validation->resolved);
            $deploy = $plan->deploy;

            foreach (array_keys((array) ($validation->resolved['units'] ?? [])) as $unit) {
                if (! in_array($unit, $deploy, true) && self::filesChanged($validation->resolved['units'][$unit], $changedPaths) && $scope->refId((string) $unit) !== null
                    && in_array($validation->resolved['units'][$unit]['type'] ?? null, ['app', 'compose', 'function'], true)) {
                    $deploy[] = (string) $unit;
                }
            }

            $planArray = EnvMask::plan([...$plan->toArray(), 'deploy' => array_values($deploy)]);
            $run->forceFill(['resolved' => $validation->resolved, 'plan' => $planArray, 'errors' => $issues])->save();

            if ($plan->changes->isRisky()) {
                // [decision] A risky plan always waits for an approval (experimental is a label only since phase 4).
                return $this->awaitApproval($run, $plan, $commitInfo['message'] ?? null);
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
        // [decision] One run applies at a time per environment; a newer run supersedes the older ones not done yet.
        return Cache::lock('config:environment:'.$run->environment_id, 600)->block(120, fn () => $this->applyLocked($run->refresh(), $commitInfo));
    }

    /**
     * @param  array{message?: ?string, author?: ?string}  $commitInfo
     */
    private function applyLocked(ConfigRun $run, array $commitInfo): ConfigRun
    {
        // [decision] Only a run still planning applies (a new run, or an approval that claimed it): one already
        // applying, waiting or finished is left alone, so a repeated or racing call never applies twice.
        if ($run->status !== RunStatus::Planning) {
            return $run;
        }

        $source = ConfigSource::enabledFor($run->project_id);
        $environment = $this->projects->environment((string) $run->environment_id);

        if ($source === null) {
            return $this->finish($run, RunStatus::Cancelled, 'Config as code was disabled for this project before the run applied.', ['cancel_reason' => 'source_disabled']);
        }

        if ($environment === null || $run->resolved === null) {
            return $this->finish($run, RunStatus::Failed, 'The project, environment or config source no longer exists.');
        }

        $this->supersede($run);
        $scope = $this->scopes->make($environment, $source, $run->resolved, $run->commit, $run->requested_by, $run->allow_destroy);

        // Planned again against today's state: what is applied is never older than the run, and stored plans are masked.
        $plan = $this->planner->plan($scope, $run->resolved);

        $deployList = (array) ($run->plan['deploy'] ?? []);

        if ($plan->changes->isRisky() && ($run->approved_at === null || RiskSet::beyond($plan->changes->changes, (array) $run->approved_risky) !== [])) {
            // Not approved, or the plan gained risky changes the approver didn't see: it waits (again).
            $run->forceFill(['plan' => EnvMask::plan([...$plan->toArray(), 'deploy' => $deployList])])->save();

            return $this->awaitApproval($run, $plan, $commitInfo['message'] ?? null, reset: $run->approved_at !== null);
        }

        $deploy = array_values(array_unique([...(array) ($run->plan['deploy'] ?? []), ...$plan->deploy]));
        $run->forceFill(['status' => RunStatus::Applying, 'plan' => EnvMask::plan([...$plan->toArray(), 'deploy' => $deploy])])->save();
        $ctx = new ApplyContext($run->id, ApplyPhase::BeforeBuild, $run->commit, $run->requested_by, $run->allow_destroy);

        try {
            $scope = DB::transaction(fn () => $this->applier->phaseA($scope, $plan->changes, $ctx));
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

            if ($run->trigger === RunTrigger::Push && $this->sites->find($siteId)?->pushToDeploy === false) {
                // [decision] A push never deploys a site whose push to deploy is off; its config is applied.
                $entry['status'] = 'skipped';
                $entry['error'] = 'Push to deploy is off for this site: deploy it to release the change.';
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
        $units = array_unique(array_filter(array_map(fn ($c) => $c->unit, $plan->changes->actionable())));
        $failures = [];

        foreach ($units as $unit) {
            if (! in_array($unit, $deploying, true)) {
                try {
                    $this->phaseB($run, (string) $unit, null, null);
                } catch (Throwable $e) {
                    $failures[(string) $unit] = 'Phase B failed: '.self::message($e);
                }
            }
        }

        if ($failures !== []) {
            $this->entries($run, function (array $entries) use ($failures) {
                foreach ($failures as $unit => $error) {
                    $found = false;

                    foreach ($entries as $i => $entry) {
                        if ($entry['unit'] === $unit) {
                            $entries[$i] = [...$entry, 'status' => 'failed', 'error' => $error];
                            $found = true;
                        }
                    }

                    if (! $found) {
                        $entries[] = ['unit' => $unit, 'site_id' => null, 'deployment_id' => null, 'status' => 'failed', 'error' => $error];
                    }
                }

                return $entries;
            });
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

    /** The unit's deployment was cancelled before it ran: the unit never gets its release, nor phase B. */
    public function deploymentCancelled(ConfigRun $run, string $deploymentId): void
    {
        $this->entryFailed($run, $deploymentId, 'The deployment was cancelled.', 'cancelled');
        $this->settle($run->refresh());
    }

    private function entryFailed(ConfigRun $run, string $deploymentId, string $error, string $status = 'failed'): void
    {
        $this->entries($run, function (array $entries) use ($deploymentId, $error, $status) {
            foreach ($entries as $i => $entry) {
                if (($entry['deployment_id'] ?? null) === $deploymentId && ($entry['status'] ?? null) === 'deploying') {
                    $entries[$i]['status'] = $status;
                    $entries[$i]['error'] = $error;
                }
            }

            return $entries;
        });
    }

    /**
     * Update a run's deploy entries under a row lock.
     *
     * @param  \Closure(list<array<string, mixed>>): list<array<string, mixed>>  $change
     */
    private function entries(ConfigRun $run, \Closure $change): void
    {
        DB::transaction(function () use ($run, $change) {
            $locked = ConfigRun::query()->lockForUpdate()->find($run->id);
            $locked?->forceFill(['deploy' => $change(array_values((array) $locked->deploy))])->save();
        });
    }

    /**
     * Older runs of the environment that haven't finished are superseded: cancelled, their deployments in flight no
     * longer theirs (a coalesced deployment then belongs to the newer run).
     */
    private function supersede(ConfigRun $run): void
    {
        $older = ConfigRun::query()->where('environment_id', $run->environment_id)->whereKeyNot($run->id)->where('id', '<', $run->id)
            ->whereIn('status', [RunStatus::Planning, RunStatus::AwaitingApproval, RunStatus::Applying])->get();

        foreach ($older as $previous) {
            $this->entries($previous, fn (array $entries) => array_map(fn (array $e) => in_array($e['status'] ?? null, ['pending', 'deploying'], true)
                ? [...$e, 'status' => 'superseded', 'error' => "Superseded by run {$run->id}."] : $e, $entries));
            $this->finish($previous->refresh(), RunStatus::Cancelled, "Superseded by a newer run ({$run->id}).", ['cancel_reason' => 'superseded']);
        }
    }

    private function phaseB(ConfigRun $run, string $unit, ?string $deploymentId, ?string $releaseId): void
    {
        $source = ConfigSource::enabledFor($run->project_id);
        $environment = $this->projects->environment((string) $run->environment_id);

        if ($source === null || $environment === null || $run->resolved === null) {
            return;
        }

        $scope = $this->scopes->make($environment, $source, $run->resolved, $run->commit, $run->requested_by, $run->allow_destroy);
        $ctx = new ApplyContext($run->id, ApplyPhase::WithRelease, $run->commit, $run->requested_by, $run->allow_destroy, $unit, $deploymentId, $releaseId);
        $skipped = [];
        DB::transaction(function () use ($scope, $run, $ctx, $unit, &$skipped) {
            $skipped = $this->applier->phaseB($scope, $run->resolved, $ctx, $unit, (array) ($run->approved_risky ?? []));
        });

        if ($skipped !== []) {
            // [decision] Phase B plans again when the release is live: a risky change no approval covered is skipped.
            $run->forceFill(['errors' => [...(array) $run->errors, ...array_map(fn (Change $c) => ConfigIssue::warning('phase_b.risky_skipped', $c->path,
                "Not applied: {$c->reason} It needs an approval: push the file again.")->toArray(), $skipped)]])->save();
        }
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

        $failed = array_values(array_filter($entries, fn (array $e) => in_array($e['status'] ?? null, ['failed', 'cancelled'], true)));

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
        $was = $run->status;
        $run->forceFill([...$extra, 'status' => $status, 'error' => $error, 'finished_at' => now()])->save();

        if ($status === RunStatus::Failed && $was !== RunStatus::Failed) {
            DB::afterCommit(fn () => ConfigRunFailed::dispatch($run->organization_id, $run->project_id, $run->id, $run->environment, Presenters::run($run)['url'], '', (string) $error));
        }

        return $run;
    }

    /**
     * The run waits for an approval (until `approval_days`), announced once: an alert, and the merged pull request's
     * plan comment.
     */
    private function awaitApproval(ConfigRun $run, Plan $plan, ?string $message, bool $reset = false): ConfigRun
    {
        $announce = $run->status !== RunStatus::AwaitingApproval;
        $run->forceFill([
            'status' => RunStatus::AwaitingApproval,
            'approval_expires_at' => $run->approval_expires_at ?? now()->addDays(max(1, (int) config('config_as_code.approval_days', 7))),
            ...($reset ? ['approved_by' => null, 'approved_at' => null, 'approved_risky' => null] : []),
        ])->save();

        // [decision] One run waits per environment: the older waiting ones are superseded.
        ConfigRun::query()->where('environment_id', $run->environment_id)->where('id', '<', $run->id)->where('status', RunStatus::AwaitingApproval)
            ->update(['status' => RunStatus::Cancelled->value, 'cancel_reason' => 'superseded', 'error' => "Superseded by a newer run ({$run->id}).",
                'finished_at' => now(), 'updated_at' => now()]);

        if ($announce) {
            // Every time a run starts waiting (a run sent back to waiting too): its alert key carries the plan hash.
            $rules = implode(', ', array_unique(array_map(fn ($c) => (string) $c->rule, $plan->changes->risky())));
            $hash = RiskSet::hash($plan->changes->changes);
            DB::afterCommit(function () use ($run, $rules, $message, $hash) {
                ConfigRunAwaitingApproval::dispatch($run->organization_id, $run->project_id, $run->id, $run->environment, Presenters::run($run)['url'], $rules, '', $hash);

                if ($run->trigger === RunTrigger::Push) {
                    $this->notice->post($run, $message);
                }
            });
        }

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
