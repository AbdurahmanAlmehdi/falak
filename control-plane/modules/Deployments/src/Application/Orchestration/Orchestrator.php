<?php

namespace Falak\Deployments\Application\Orchestration;

use Closure;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Contracts\Data\BuildRequest;
use Falak\Deployments\Application\Jobs\RunHealthCheck;
use Falak\Deployments\Application\Planning\PlanBuilder;
use Falak\Deployments\Application\Planning\ScriptSections;
use Falak\Deployments\Contracts\FunctionSources;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\ReleaseStatus;
use Falak\Deployments\Domain\Enums\StepKind;
use Falak\Deployments\Domain\Enums\StepStatus;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Enums\TargetStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Falak\Deployments\Domain\Models\ServerRelease;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Domain\Models\StepCommand;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentRolledBack;
use Falak\Deployments\Events\DeploymentStarted;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Deployments\Events\DeploymentUpdated;
use Falak\Deployments\Events\ReleaseActivated;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Falak\Fleet\Contracts\Exceptions\UnknownCommandType;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SiteTargetData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Telemetry\Contracts\Annotations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The deployment state machine. Every entry point (start, command/build/health-check outcome,
 * reconciliation) locks the deployment row, applies the change, then advances the step DAG:
 * dispatching every pending step whose dependencies are satisfied. Nothing ever blocks waiting for
 * an agent — progress is driven by Fleet CommandFinished/CommandFailed, Builds events and queued
 * health checks. Settling is idempotent (terminal steps never change), so re-delivered events are
 * harmless, and dispatches use a stable idempotency key per step.
 *
 * On any failure: no new forward steps start; once in-flight steps settle, every server that
 * already switched releases is rolled back (deploy.rollback / container swap to the previous
 * image, then a process restart), and the deployment fails with an alert.
 */
final class Orchestrator
{
    /** @var list<Closure(): void> side effects run after the transaction commits */
    private array $after = [];

    private int $depth = 0;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly AgentGateway $agents,
        private readonly BuildService $builds,
        private readonly EdgeRoutes $edge,
        private readonly Annotations $annotations,
        private readonly StepPayloads $payloads,
        private readonly PlanBuilder $planner,
        private readonly DeploymentLog $log,
        private readonly DeploymentQueue $queue,
        private readonly ProcessControl $processes,
        private readonly ComposeSites $compose,
        private readonly FunctionSources $functions,
        private readonly Secrets $secrets,
    ) {}

    // ---- entry points -------------------------------------------------------------------------

    /** Plan and start a deployment claimed from the site queue. */
    public function begin(string $deploymentId): void
    {
        $this->locked($deploymentId, function (Deployment $deployment) {
            if ($deployment->status->isTerminal() || $deployment->steps()->exists()) {
                return;
            }

            if ($this->staleAutomaticRollback($deployment)) {
                return;
            }

            try {
                $this->plan($deployment);
            } catch (InvalidArgumentException $e) {
                $deployment->forceFill(['rolling_back' => true, 'error' => $e->getMessage(), 'settings' => [...($deployment->settings ?? []), 'rollback_planned' => true]])->save();
                $this->log->note($deployment->id, "Deployment cannot start: {$e->getMessage()}", stream: 'stderr');
            }

            $this->drive($deployment);
        });
    }

    public function advance(string $deploymentId): void
    {
        $this->locked($deploymentId, fn (Deployment $deployment) => $this->drive($deployment));
    }

    /**
     * Outcome of an agent command (Fleet CommandFinished / CommandFailed).
     *
     * @param  array<string, mixed>|null  $result
     */
    public function commandSettled(string $commandId, bool $succeeded, ?int $exitCode, ?array $result, ?string $error, string $status = 'succeeded'): void
    {
        $link = StepCommand::query()->find($commandId);

        if ($link === null) {
            return;
        }

        $this->locked($link->deployment_id, function (Deployment $deployment) use ($commandId, $succeeded, $exitCode, $result, $error, $status) {
            $link = StepCommand::query()->find($commandId);
            $step = $link ? DeploymentStep::query()->with('target')->find($link->step_id) : null;

            if (! $link || ! $step || $link->status !== null || $step->status->isTerminal()) {
                return; // re-delivered or already reconciled
            }

            if (in_array($link->type, ['deploy.hook', 'system.exec'], true)) {
                $code = is_numeric($result['exit_code'] ?? null) ? (int) $result['exit_code'] : $exitCode;
                $succeeded = $succeeded && ($code ?? 0) === 0;
                $exitCode = $code;
            }

            $link->forceFill(['status' => $succeeded ? 'succeeded' : 'failed'])->save();

            // A step with several commands succeeds once all of them did; any failure fails it.
            if ($succeeded && StepCommand::query()->where('step_id', $step->id)->whereNull('status')->exists()) {
                return;
            }

            $this->settle($deployment, $step, $succeeded, $exitCode, $result, $succeeded ? null : $this->commandError($error, $exitCode, $status), $status);
            $this->drive($deployment);
        });
    }

    /**
     * Outcome of the deployment's build (Builds events).
     */
    public function buildSettled(string $buildId, bool $succeeded, ?string $error, bool $cancelled = false): void
    {
        $deploymentId = DeploymentStep::query()->where('build_id', $buildId)->where('kind', StepKind::Build)->value('deployment_id');

        if (! is_string($deploymentId)) {
            return;
        }

        $this->locked($deploymentId, function (Deployment $deployment) use ($buildId, $succeeded, $error, $cancelled) {
            $step = DeploymentStep::query()->where('build_id', $buildId)->where('kind', StepKind::Build)->first();

            if (! $step || $step->status->isTerminal()) {
                return;
            }

            $this->settleBuildStep($deployment, $step, $succeeded, $cancelled ? 'The build was cancelled.' : ($error ?: 'The build failed.'));
            $this->drive($deployment);
        });
    }

    public function healthChecked(string $stepId, bool $healthy, string $message): void
    {
        $deploymentId = DeploymentStep::query()->whereKey($stepId)->value('deployment_id');

        if (! is_string($deploymentId)) {
            return;
        }

        $this->locked($deploymentId, function (Deployment $deployment) use ($stepId, $healthy, $message) {
            $step = DeploymentStep::query()->with('target')->find($stepId);

            if (! $step || $step->status !== StepStatus::Running) {
                return;
            }

            $this->settle($deployment, $step, $healthy, null, ['message' => $message], $healthy ? null : $message);
            $this->drive($deployment);
        });
    }

    /**
     * Resume a deployment whose events were lost: settle steps from the agent's recorded command
     * status, re-dispatch steps that never reached the agent, then advance.
     */
    public function reconcile(string $deploymentId): void
    {
        $this->locked($deploymentId, function (Deployment $deployment) {
            foreach ($deployment->steps()->with('target')->where('status', StepStatus::Running)->get() as $step) {
                $links = StepCommand::query()->where('step_id', $step->id)->whereNull('status')->get();

                if ($links->isNotEmpty()) {
                    foreach ($links as $link) {
                        try {
                            $status = $this->agents->status($link->command_id);
                        } catch (Throwable) {
                            continue;
                        }

                        if (! $status->isFinished()) {
                            continue;
                        }

                        $ok = $status->isSuccessful() && (! in_array($link->type, ['deploy.hook', 'system.exec'], true) || (int) ($status->result['exit_code'] ?? $status->exitCode ?? 0) === 0);
                        $link->forceFill(['status' => $ok ? 'succeeded' : 'failed'])->save();

                        if (! $ok) {
                            $this->settle($deployment, $step, false, $status->exitCode, $status->result, $this->commandError($status->error, $status->exitCode, $status->status->value), $status->status->value);

                            continue 2;
                        }
                    }

                    if (! StepCommand::query()->where('step_id', $step->id)->whereNull('status')->exists()) {
                        $this->settle($deployment, $step, true, 0, null, null);
                    }
                } elseif ($step->kind === StepKind::Build && $step->build_id !== null) {
                    $build = $this->builds->find($step->build_id);

                    if ($build?->isFinished()) {
                        $this->settleBuildStep($deployment, $step, $build->isSuccessful(), $build->error ?? "Build {$build->status->value}.");
                    }
                } elseif ($step->kind === StepKind::HealthCheck && $step->started_at?->lt(now()->subMinutes(10))) {
                    $this->afterCommit(fn () => RunHealthCheck::dispatch($step->id, 1));
                }
            }

            $this->drive($deployment);
        });
    }

    /**
     * Cancel a queued or waiting deployment, or one still building (nothing has touched the servers yet).
     */
    public function cancel(string $deploymentId): bool
    {
        $cancelled = false;

        $this->locked($deploymentId, function (Deployment $deployment) use (&$cancelled) {
            if ($deployment->status === DeploymentStatus::Queued || $deployment->status === DeploymentStatus::Waiting) {
                $waiting = $deployment->status === DeploymentStatus::Waiting;
                $deployment->forceFill(['status' => DeploymentStatus::Cancelled, 'finished_at' => now(), 'error' => 'Cancelled before it started.', 'waiting_reason' => null])->save();
                $this->log->note($deployment->id, 'Deployment cancelled before it started.');
                $this->updated($deployment);
                $cancelled = true;

                if ($waiting) {
                    // A waiting deployment held the site's queue: let the next one go.
                    $siteId = $deployment->site_id;
                    $this->afterCommit(fn () => $this->queue->startNext($siteId));
                }

                return;
            }

            if ($deployment->status !== DeploymentStatus::Building) {
                return;
            }

            $deployment->forceFill(['cancel_requested' => true])->save();
            $build = $deployment->steps()->where('kind', StepKind::Build)->first();
            $this->log->note($deployment->id, 'Cancelling the deployment…');

            if ($build?->build_id !== null) {
                $buildId = $build->build_id;
                $this->afterCommit(fn () => $this->builds->cancel($buildId));
            } else {
                $this->fail($deployment, $build ?? $deployment->steps()->first(), 'Cancelled.');
            }

            $cancelled = true;
            $this->drive($deployment);
        });

        return $cancelled;
    }

    /**
     * An automatic rollback (a live release's watch tripped) only reverts the release it was started for: when the site
     * runs another release by the time it starts (a deploy went live in between), it is cancelled with the reason and
     * the watched deployment is no longer marked rolled back.
     */
    private function staleAutomaticRollback(Deployment $deployment): bool
    {
        if ($deployment->trigger !== Trigger::Rollback || $deployment->auto_rollback_of === null) {
            return false;
        }

        $watched = Deployment::query()->find($deployment->auto_rollback_of);

        if ($watched !== null && $watched->release_id !== null && Release::current($deployment->site_id)?->id === $watched->release_id) {
            return false;
        }

        $reason = 'Cancelled: the site no longer runs the release whose watch asked for this rollback.';
        $deployment->forceFill(['status' => DeploymentStatus::Cancelled, 'finished_at' => now(), 'error' => $reason])->save();
        $this->log->note($deployment->id, $reason, stream: 'stderr');

        if ($watched !== null) {
            $watched->forceFill(['rolled_back' => false, 'rolled_back_at' => null])->save();
            ReleaseWatch::query()->whereKey($watched->id)->update(['status' => WatchStatus::Alerted, 'updated_at' => now()]);
            Release::query()->whereKey($watched->release_id)->update(['auto_rolled_back_at' => null, 'updated_at' => now()]);
            $this->log->note($watched->id, "Watch: the automatic rollback (deployment #{$deployment->number}) was cancelled; the site had moved on to another release.", stream: 'stderr');
        }

        $this->updated($deployment);
        $siteId = $deployment->site_id;
        $this->afterCommit(fn () => $this->queue->startNext($siteId));

        return true;
    }

    // ---- planning -----------------------------------------------------------------------------

    private function plan(Deployment $deployment): void
    {
        $site = $this->sites->find($deployment->site_id) ?? throw new InvalidArgumentException('The site no longer exists.');

        $readiness = TargetReadiness::of($site, $this->servers);
        $blocker = $readiness->blocker();

        if ($blocker !== null) {
            throw new InvalidArgumentException($blocker);
        }

        $ready = $readiness->ready;

        if ($ready === []) {
            throw new InvalidArgumentException('The site has no ready servers.');
        }

        foreach ($readiness->skipped() as $warning) {
            $this->log->note($deployment->id, $warning, stream: 'stderr');
        }

        usort($ready, fn (SiteTargetData $a, SiteTargetData $b) => (int) $b->isLeader() <=> (int) $a->isLeader());

        if (! $ready[0]->isLeader()) {
            throw new InvalidArgumentException('The leader server of the site is not ready.');
        }

        if ($site->runtime === SiteRuntime::Docker && $site->appPort === null) {
            throw new InvalidArgumentException('The site has no app port for its container.');
        }

        $settings = SiteSettings::for($site);
        $snapshot = $settings->snapshot($site);
        $previous = Release::current($site->id);
        $needsBuild = false;
        $sections = new ScriptSections('', '', '', '');

        if ($deployment->trigger === Trigger::Rollback) {
            $release = Release::query()->where('site_id', $site->id)->find($deployment->target_release_id);

            if (! $release || ! $release->canRollBackTo()) {
                throw new InvalidArgumentException('The release is no longer available to roll back to.');
            }

            if ($site->runtime === SiteRuntime::Docker && $release->image === null) {
                throw new InvalidArgumentException('The release has no image to roll back to.');
            }

            if ($site->runtime === SiteRuntime::Compose && ! is_array($release->compose)) {
                throw new InvalidArgumentException('The release has no compose files to roll back to.');
            }

            if ($site->runtime === SiteRuntime::Function && ($release->commit === null || $this->functions->find($site->id, $release->commit) === null)) {
                throw new InvalidArgumentException('The release has no function code to roll back to.');
            }

            $releaseId = $release->id;
        } else {
            $hasRepository = $site->repository !== null && $site->sourceConnectionId !== null;
            $inlineCompose = $site->runtime === SiteRuntime::Compose && $site->compose?->source === ComposeSource::Inline;
            $needsBuild = $hasRepository && ! $inlineCompose;

            if ($inlineCompose && $site->compose?->version === null) {
                throw new InvalidArgumentException('The site has no compose file; add one in Settings → Compose.');
            }

            if ($site->runtime === SiteRuntime::Function) {
                // A function deploys a version of its code: the requested one (its hash is the commit) or the newest.
                $needsBuild = false;
                $source = $deployment->commit !== null ? $this->functions->find($site->id, $deployment->commit) : $this->functions->head($site->id);

                if ($source === null) {
                    throw new InvalidArgumentException($deployment->commit !== null ? 'That version of the function no longer exists.' : 'The function has no code yet; write it in the Code tab and deploy.');
                }

                $deployment->forceFill([
                    'commit' => $source->hash,
                    'commit_message' => $deployment->commit_message ?? "v{$source->number}".($source->message ? ": {$source->message}" : ''),
                    'commit_author' => $deployment->commit_author ?? $source->author,
                ])->save();
            } elseif (! $hasRepository && ! $inlineCompose && ! ($site->runtime === SiteRuntime::Docker && $site->dockerImage)) {
                throw new InvalidArgumentException('The site has no repository to deploy.');
            }

            if (! $site->runtime->usesDocker()) {
                $sections = ScriptSections::parse($site->deployScript);
            }

            $releaseId = strtolower((string) Str::ulid());
            Release::query()->create([
                'id' => $releaseId,
                'organization_id' => $deployment->organization_id,
                'site_id' => $site->id,
                'deployment_id' => $deployment->id,
                'commit' => $deployment->commit,
                'branch' => $deployment->branch,
                'commit_message' => $deployment->commit_message,
                'commit_author' => $deployment->commit_author,
                'image' => ! $needsBuild && $site->runtime === SiteRuntime::Docker ? $site->dockerImage : null,
                'status' => ReleaseStatus::Pending,
            ]);
        }

        $targets = [];

        foreach ($ready as $position => $target) {
            $targets[] = DeploymentTarget::query()->create([
                'deployment_id' => $deployment->id,
                'server_id' => $target->serverId,
                'server_name' => $this->servers->find($target->serverId)?->name ?? $target->serverId,
                'role' => $target->isLeader() ? 'leader' : 'member',
                'position' => $position,
                'status' => TargetStatus::Pending,
            ]);
        }

        $deployment->forceFill([
            'strategy' => Strategy::from($snapshot['strategy']),
            'settings' => $snapshot,
            'release_id' => $releaseId,
            'previous_release_id' => $previous?->id,
            'status' => $needsBuild ? DeploymentStatus::Building : DeploymentStatus::Deploying,
            'started_at' => $deployment->started_at ?? now(),
        ])->save();

        $this->planner->build($deployment, $site->runtime, $targets, $sections, $needsBuild);

        $names = implode(', ', array_map(fn (DeploymentTarget $t) => $t->server_name.($t->isLeader() ? ' (leader)' : ''), $targets));
        $this->log->note($deployment->id, sprintf('Deployment #%d (%s) started: %s strategy on %s.', $deployment->number, $deployment->trigger->label(), $deployment->strategy?->label(), $names));

        $this->afterCommit(function () use ($deployment, $targets) {
            $this->annotate($deployment, 'started');
            DeploymentStarted::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->trigger->value, (string) $deployment->strategy?->value,
                $deployment->commit, $deployment->branch, $deployment->release_id, array_map(fn (DeploymentTarget $t) => $t->server_id, $targets));
        });
        $this->updated($deployment);
    }

    // ---- driving the DAG ----------------------------------------------------------------------

    private function drive(Deployment $deployment): void
    {
        for ($i = 0; $i < 200 && ! $deployment->status->isTerminal(); $i++) {
            /** @var Collection<string, DeploymentStep> $steps */
            $steps = $deployment->steps()->with('target')->get()->keyBy('key');

            $progressed = $deployment->rolling_back ? $this->driveRollback($deployment, $steps) : $this->driveForward($deployment, $steps);

            if (! $progressed) {
                return;
            }
        }
    }

    /**
     * @param  Collection<string, DeploymentStep>  $steps
     */
    private function driveForward(Deployment $deployment, Collection $steps): bool
    {
        $forward = $steps->filter(fn (DeploymentStep $s) => ! $s->rollback);
        $failed = $forward->first(fn (DeploymentStep $s) => $s->status === StepStatus::Failed);

        if ($failed !== null) {
            $this->beginRollback($deployment, $failed, $forward);

            return true;
        }

        $started = false;

        foreach ($forward as $step) {
            if ($step->status !== StepStatus::Pending) {
                continue;
            }

            if ($this->depsSatisfied($step, $steps)) {
                $this->start($deployment, $step);
                $started = true;
            }
        }

        if (! $started && $forward->every(fn (DeploymentStep $s) => $s->status->isSatisfied())) {
            $this->succeed($deployment);

            return false;
        }

        return $started;
    }

    /**
     * @param  Collection<string, DeploymentStep>  $steps
     */
    private function driveRollback(Deployment $deployment, Collection $steps): bool
    {
        // Wait for in-flight forward steps: an activation still running may yet switch a server.
        if ($steps->contains(fn (DeploymentStep $s) => ! $s->rollback && $s->status === StepStatus::Running)) {
            return false;
        }

        if (! $deployment->setting('rollback_planned', false)) {
            $this->planRollback($deployment);

            return true;
        }

        $rollback = $steps->filter(fn (DeploymentStep $s) => $s->rollback);
        $progressed = false;

        foreach ($rollback as $step) {
            if ($step->status !== StepStatus::Pending) {
                continue;
            }

            $deps = collect($step->depends_on)->map(fn (string $key) => $steps->get($key));

            if ($deps->contains(fn (?DeploymentStep $d) => $d !== null && in_array($d->status, [StepStatus::Failed, StepStatus::Skipped], true))) {
                $step->forceFill(['status' => StepStatus::Skipped, 'finished_at' => now()])->save();
                $progressed = true;
            } elseif ($deps->every(fn (?DeploymentStep $d) => $d === null || $d->status === StepStatus::Succeeded)) {
                $this->start($deployment, $step);
                $progressed = true;
            }
        }

        if (! $progressed && $rollback->every(fn (DeploymentStep $s) => $s->status->isTerminal())) {
            $this->finishFailed($deployment, $rollback);

            return false;
        }

        return $progressed;
    }

    /**
     * @param  Collection<string, DeploymentStep>  $steps
     */
    private function depsSatisfied(DeploymentStep $step, Collection $steps): bool
    {
        foreach ($step->depends_on as $key) {
            $dep = $steps->get($key);

            if ($dep !== null && ! $dep->status->isSatisfied()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<string, DeploymentStep>  $forward
     */
    private function beginRollback(Deployment $deployment, DeploymentStep $failed, Collection $forward): void
    {
        $where = $failed->target ? " on {$failed->target->server_name}" : '';
        $error = $deployment->error ?? mb_substr(ucfirst($failed->label()).$where.' failed: '.($failed->error ?? 'unknown error'), 0, 2000);

        $deployment->forceFill(['rolling_back' => true, 'error' => $error])->save();

        foreach ($forward as $step) {
            if ($step->status === StepStatus::Pending) {
                $step->forceFill(['status' => StepStatus::Skipped])->save();
            }
        }

        $this->log->note($deployment->id, "Deployment failed: {$error}", stream: 'stderr');
        $this->updated($deployment);
    }

    private function planRollback(Deployment $deployment): void
    {
        $deployment->forceFill(['settings' => [...($deployment->settings ?? []), 'rollback_planned' => true], 'phase' => 'rollback'])->save();

        $site = $this->sites->find($deployment->site_id);
        $activated = $deployment->targets()->where('activated', true)->get();

        if ($site === null || $activated->isEmpty()) {
            if ($site !== null) {
                $this->log->note($deployment->id, 'No server switched to the new release; nothing to roll back.');
            }

            return;
        }

        $previous = $deployment->previous_release_id ? Release::query()->find($deployment->previous_release_id) : null;
        $position = (int) $deployment->steps()->max('position') + 1;
        $this->log->note($deployment->id, 'Rolling back '.$activated->count().' server(s) to the previous release…');

        foreach ($activated as $target) {
            /** @var DeploymentTarget $target */
            if ($site->runtime === SiteRuntime::Compose) {
                // `up --wait` with the previous release's files (images pinned to what ran then).
                if (! is_array($previous?->compose)) {
                    $this->log->note($deployment->id, "{$target->server_name}: no previous compose release to return to.", stream: 'stderr');

                    continue;
                }

                $this->addRollbackStep($deployment, $target, "revert:{$target->id}", StepKind::Revert, [], ['release_id' => $previous->id], $position++, 'docker.compose.up');

                continue;
            }

            if ($site->runtime === SiteRuntime::Function) {
                // The agent switches a function only once the new release boots, so this runs after a later failure
                // (e.g. on another server) and re-applies the previous release.
                if ($previous === null || $previous->commit === null) {
                    $this->log->note($deployment->id, "{$target->server_name}: no previous function release to return to.", stream: 'stderr');

                    continue;
                }

                $this->addRollbackStep($deployment, $target, "revert:{$target->id}", StepKind::Revert, [], ['release_id' => $previous->id], $position++, 'fn.release.apply');

                continue;
            }

            if ($site->runtime->isContainer()) {
                if ($previous?->image === null) {
                    $this->log->note($deployment->id, "{$target->server_name}: no previous image to return to.", stream: 'stderr');

                    continue;
                }

                $this->addRollbackStep($deployment, $target, "revert_swap:{$target->id}", StepKind::RevertSwap, [], ['image' => $previous->image], $position++);

                continue;
            }

            $releaseId = $target->previous_release_id ?? $deployment->previous_release_id;

            if ($releaseId === null || strtolower($releaseId) === strtolower((string) $deployment->release_id)) {
                $this->log->note($deployment->id, "{$target->server_name}: no previous release to return to.", stream: 'stderr');

                continue;
            }

            $revert = $this->addRollbackStep($deployment, $target, "revert:{$target->id}", StepKind::Revert, [], ['release_id' => strtolower($releaseId)], $position++);

            if ($site->runtime !== SiteRuntime::Static) {
                $this->addRollbackStep($deployment, $target, "revert_restart:{$target->id}", StepKind::RevertRestart, [$revert->key], null, $position++);
            }
        }
    }

    /**
     * @param  list<string>  $deps
     * @param  array<string, mixed>|null  $meta
     */
    private function addRollbackStep(Deployment $deployment, DeploymentTarget $target, string $key, StepKind $kind, array $deps, ?array $meta, int $position, ?string $commandType = null): DeploymentStep
    {
        return DeploymentStep::query()->create([
            'deployment_id' => $deployment->id,
            'target_id' => $target->id,
            'server_id' => $target->server_id,
            'key' => $key,
            'kind' => $kind,
            'phase' => 'rollback',
            'rollback' => true,
            'batch' => $target->batch,
            'position' => $position,
            'depends_on' => $deps,
            'meta' => $meta,
            'status' => StepStatus::Pending,
            'command_type' => $commandType ?? $kind->commandType(),
        ]);
    }

    // ---- steps --------------------------------------------------------------------------------

    private function start(Deployment $deployment, DeploymentStep $step): void
    {
        $step->forceFill(['status' => StepStatus::Running, 'started_at' => now(), 'attempts' => $step->attempts + 1])->save();

        if (! $step->rollback) {
            $deployment->forceFill([
                'phase' => $step->phase,
                'status' => $step->kind === StepKind::Build ? DeploymentStatus::Building : DeploymentStatus::Deploying,
            ])->save();
        }

        if ($step->target !== null && $step->target->status === TargetStatus::Pending) {
            $step->target->forceFill(['status' => TargetStatus::Deploying])->save();
        }
        $this->updated($deployment);

        match ($step->kind) {
            StepKind::Build => $this->startBuild($deployment, $step),
            StepKind::HealthCheck => $this->startHealthCheck($deployment, $step),
            default => $this->dispatchCommand($deployment, $step),
        };
    }

    private function startBuild(Deployment $deployment, DeploymentStep $step): void
    {
        $this->log->note($deployment->id, 'Requesting a build'.($deployment->commit ? ' of '.$deployment->shortCommit() : '').'…', $step);

        try {
            $build = $this->builds->request(new BuildRequest($deployment->site_id, $deployment->commit, $deployment->branch, $deployment->id, $deployment->requested_by));
        } catch (Throwable $e) {
            $this->fail($deployment, $step, 'Could not request a build: '.$e->getMessage());

            return;
        }

        $step->forceFill(['build_id' => $build->id])->save();
        $deployment->forceFill(['build_id' => $build->id])->save();

        if ($build->status === BuildStatus::Succeeded) {
            $this->settleBuildStep($deployment, $step, true, null);
        } elseif ($build->status->isTerminal()) {
            $this->settleBuildStep($deployment, $step, false, $build->error ?? "Build {$build->status->value}.");
        }
    }

    private function settleBuildStep(Deployment $deployment, DeploymentStep $step, bool $succeeded, ?string $error): void
    {
        if ($succeeded && $step->build_id !== null) {
            $build = $this->builds->find($step->build_id);

            if ($build?->commit !== null && $deployment->commit === null) {
                $deployment->forceFill(['commit' => $build->commit])->save();
                Release::query()->whereKey($deployment->release_id)->whereNull('commit')->update(['commit' => $build->commit]);
            }

            if ($build?->imageRef !== null) {
                Release::query()->whereKey($deployment->release_id)->update(['image' => $build->imageRef]);
            }

            $this->log->note($deployment->id, $build?->reused ? 'Reusing an identical earlier build.' : 'Build succeeded.', $step);
        }

        $this->settle($deployment, $step, $succeeded, null, null, $succeeded ? null : $error);
    }

    private function startHealthCheck(Deployment $deployment, DeploymentStep $step): void
    {
        if (! $deployment->setting('health.enabled', true)) {
            $step->forceFill(['status' => StepStatus::Skipped, 'finished_at' => now()])->save();
            $this->markTargetDone($deployment, $step);

            return;
        }

        $stepId = $step->id;
        $this->afterCommit(fn () => RunHealthCheck::dispatch($stepId, 1));
    }

    private function dispatchCommand(Deployment $deployment, DeploymentStep $step): void
    {
        $site = $this->sites->find($deployment->site_id);

        if ($site === null) {
            $this->fail($deployment, $step, 'The site no longer exists.');

            return;
        }

        $key = "deploy:{$deployment->id}:{$step->key}";

        try {
            if ($step->kind === StepKind::Restart || $step->kind === StepKind::RevertRestart) {
                // Processes restarts the site's programs (Horizon: horizon:terminate; others: proc.restart).
                // A new release: Octane is restarted (octane:reload would keep the old release), the edge holds requests meanwhile.
                $handles = $this->processes->restartForSite($site->id, (string) $step->server_id, newRelease: true);
            } else {
                // Secrets the payload resolves are logged as read by this deployment.
                $payload = $this->secrets->accessedAs(
                    SecretAccessor::deployment($deployment->id, $deployment->number),
                    fn () => $this->payloads->for($step, $deployment, $site),
                );

                if ($payload === null) {
                    $this->log->note($deployment->id, 'No leader command to run.', $step);
                    $this->settle($deployment, $step, true, null, null, null);

                    return;
                }

                $handles = [$this->agents->dispatch((string) $step->server_id, (string) $step->command_type, $payload, $this->payloads->timeout($step->kind, $step->command_type), $key)];
            }
        } catch (AgentUnavailable) {
            $this->fail($deployment, $step, 'The server agent is not connected.');

            return;
        } catch (InvalidCommandPayload|UnknownCommandType|RuntimeException $e) {
            $this->fail($deployment, $step, $e->getMessage());

            return;
        }

        if ($handles === []) {
            $this->log->note($deployment->id, 'No running processes to restart.', $step);
            $this->settle($deployment, $step, true, null, null, null);

            return;
        }

        foreach ($handles as $handle) {
            StepCommand::query()->firstOrCreate(['command_id' => $handle->id], [
                'step_id' => $step->id,
                'deployment_id' => $deployment->id,
                'type' => $handle->type,
            ]);
        }

        $step->forceFill(['command_id' => $handles[0]->id, 'command_type' => $handles[0]->type, 'idempotency_key' => $handles[0]->idempotencyKey])->save();
        $this->log->note($deployment->id, '→ '.$step->label(), $step);
    }

    private function fail(Deployment $deployment, ?DeploymentStep $step, string $error): void
    {
        if ($step === null) {
            $deployment->forceFill(['rolling_back' => true, 'error' => $error])->save();

            return;
        }

        $this->settle($deployment, $step, false, null, null, $error);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function settle(Deployment $deployment, DeploymentStep $step, bool $succeeded, ?int $exitCode, ?array $result, ?string $error, string $status = 'succeeded'): void
    {
        $step->forceFill([
            'status' => $succeeded ? StepStatus::Succeeded : StepStatus::Failed,
            'exit_code' => $exitCode,
            'result' => $result,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'finished_at' => now(),
        ])->save();

        $target = $step->target;

        if (! $succeeded) {
            // A timed-out activation may still have switched the server: treat it as activated. A failed
            // `docker compose up` has already replaced containers (they just never got healthy): roll it back too.
            if ($target && (($step->kind->activates() && $status === 'timed_out') || ($step->command_type === 'docker.compose.up' && ! $step->rollback && $step->kind === StepKind::Activate))) {
                $target->activated = true;
            }

            $target?->forceFill(['status' => $step->rollback ? $target->status : TargetStatus::Failed, 'error' => $target->error ?? $error])->save();
            $this->log->note($deployment->id, '✗ '.$step->label().' failed: '.$error, $step, 'stderr');

            return;
        }

        if ($target === null) {
            return;
        }

        $this->recordLiveRelease($deployment, $step, $target);

        if ($step->command_type === 'docker.compose.up') {
            $this->composeUpSettled($deployment, $step, $target, $result ?? []);

            return;
        }

        match ($step->kind) {
            StepKind::Activate => $target->forceFill(['activated' => true, 'previous_release_id' => self::ulid($result['previous_release_id'] ?? $result['previous_release'] ?? null) ?? $target->previous_release_id])->save(),
            StepKind::Switch => $target->forceFill(['activated' => true, 'previous_release_id' => self::ulid($result['from_release_id'] ?? null) ?? $deployment->previous_release_id])->save(),
            StepKind::Swap, StepKind::RevertSwap => $this->swapped($deployment, $step, $target, $result ?? []),
            StepKind::Revert => $target->forceFill(['status' => TargetStatus::RolledBack])->save(),
            StepKind::HealthCheck => $this->markTargetDone($deployment, $step),
            default => null,
        };
    }

    /**
     * The server now runs another release: remember it (Processes supervises the site's programs there with that
     * release's ids and environment; the restart step that follows converges them).
     */
    private function recordLiveRelease(Deployment $deployment, DeploymentStep $step, DeploymentTarget $target): void
    {
        $releaseId = match ($step->kind) {
            StepKind::Activate, StepKind::Switch, StepKind::Swap => $deployment->release_id,
            StepKind::Revert => is_string($step->meta['release_id'] ?? null) ? $step->meta['release_id'] : null,
            StepKind::RevertSwap => $deployment->previous_release_id,
            default => null,
        };

        if ($releaseId !== null && $releaseId !== '') {
            ServerRelease::record($deployment->site_id, $target->server_id, $releaseId, $deployment->id);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function swapped(Deployment $deployment, DeploymentStep $step, DeploymentTarget $target, array $result): void
    {
        if ($step->kind === StepKind::RevertSwap) {
            $target->forceFill(['status' => TargetStatus::RolledBack])->save();
        } else {
            $target->forceFill(['activated' => true])->save();
        }

        $upstream = $result['upstream'] ?? null;

        if (is_string($upstream) && $upstream !== '') {
            $siteId = $deployment->site_id;
            $serverId = $target->server_id;
            $this->afterCommit(fn () => $this->edge->recordUpstream($siteId, $serverId, $upstream));
        }
    }

    /**
     * docker.compose.up finished: record the services' state for the Services tab and pin the release's pulled
     * images to the digests the server resolved (so rolling back to it is exact).
     *
     * @param  array<string, mixed>  $result
     */
    private function composeUpSettled(Deployment $deployment, DeploymentStep $step, DeploymentTarget $target, array $result): void
    {
        match ($step->kind) {
            StepKind::Revert => $target->forceFill(['status' => TargetStatus::RolledBack])->save(),
            default => $target->forceFill(['activated' => true, 'previous_release_id' => $target->previous_release_id ?? $deployment->previous_release_id])->save(),
        };

        $services = array_values(array_filter((array) ($result['services'] ?? []), 'is_array'));

        if ($services === []) {
            return;
        }

        $siteId = $deployment->site_id;
        $serverId = $target->server_id;
        $this->afterCommit(fn () => $this->compose->recordStatus($siteId, $serverId, $services));

        if ($step->kind !== StepKind::Activate) {
            return;
        }

        $release = Release::query()->find($deployment->release_id);
        $compose = $release?->compose;

        if (! is_array($compose) || ($compose['pinned'] ?? false)) {
            return;
        }

        $digests = [];

        foreach ($services as $service) {
            if (is_string($service['service'] ?? null) && is_string($service['image_digest'] ?? null)) {
                $digests[$service['service']] = $service['image_digest'];
            }
        }

        $release->forceFill(['compose' => [...$compose, 'yaml' => $this->compose->pinDigests((string) $compose['yaml'], $digests), 'pinned' => true]])->save();
    }

    private function markTargetDone(Deployment $deployment, DeploymentStep $step): void
    {
        $step->target?->forceFill(['status' => TargetStatus::Succeeded])->save();
    }

    // ---- finishing ----------------------------------------------------------------------------

    private function succeed(Deployment $deployment): void
    {
        $site = $this->sites->find($deployment->site_id);
        $release = Release::query()->find($deployment->release_id);
        $previous = Release::current($deployment->site_id);
        $serverIds = $deployment->targets()->pluck('server_id')->all();

        if ($release !== null) {
            Release::query()->where('site_id', $deployment->site_id)->where('status', ReleaseStatus::Active)->whereKeyNot($release->id)->update(['status' => ReleaseStatus::Inactive, 'updated_at' => now()]);
            $release->forceFill(['status' => ReleaseStatus::Active, 'activated_at' => now()])->save();
        }

        $deployment->forceFill(['status' => DeploymentStatus::Succeeded, 'phase' => null, 'finished_at' => now(), 'error' => null])->save();
        $deployment->targets()->where('status', '!=', TargetStatus::Failed)->update(['status' => TargetStatus::Succeeded, 'updated_at' => now()]);

        if ($site !== null && $release !== null) {
            $this->retain($deployment, $site, $release);
        }

        $duration = (int) ($deployment->started_at?->diffInMilliseconds(now(), true) ?? 0);
        $this->log->note($deployment->id, sprintf('Deployment #%d succeeded in %.1fs; release %s is live.', $deployment->number, $duration / 1000, strtoupper((string) $deployment->release_id)));
        $this->updated($deployment);

        $this->afterCommit(function () use ($deployment, $serverIds, $duration, $previous, $release, $site) {
            $this->annotate($deployment, 'succeeded');
            DeploymentSucceeded::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->trigger->value, $deployment->commit, (string) $deployment->release_id, $serverIds, $duration);
            ReleaseActivated::dispatch((string) $deployment->release_id, $deployment->organization_id, $deployment->site_id, $deployment->id, $release?->commit ?? $deployment->commit, $previous?->id, $serverIds, $deployment->trigger->value);

            if ($deployment->trigger === Trigger::Rollback) {
                // An automatic rollback after a release went live tells why (the watch of the deployment it replaced).
                $watched = $deployment->auto_rollback_of !== null ? ReleaseWatch::query()->find($deployment->auto_rollback_of) : null;
                DeploymentRolledBack::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $site->slug ?? $deployment->site_slug, $deployment->release_id, $serverIds,
                    $deployment->auto_rollback_of !== null, $watched?->reason, (bool) $watched?->migrations);
            }

            // Programs and schedules follow the live release (sites without a restart step, e.g. static, included).
            $this->processes->converge(...$serverIds);

            $this->queue->startNext($deployment->site_id);
        });
    }

    /**
     * Keep the newest N releases (current included); prune the rest on the servers.
     */
    private function retain(Deployment $deployment, SiteData $site, Release $current): void
    {
        $keep = max(1, (int) $deployment->setting('keep_releases', 5));

        $stale = Release::query()->where('site_id', $site->id)->where('status', ReleaseStatus::Inactive)
            ->orderByDesc('activated_at')->orderByDesc('created_at')->orderByDesc('id')->get()->slice($keep - 1);

        foreach ($stale as $release) {
            $release->forceFill(['status' => ReleaseStatus::Pruned])->save();
        }

        // Failed releases may have left directories behind; the agent's keep-N prune removes them too.
        Release::query()->where('site_id', $site->id)->where('status', ReleaseStatus::Failed)->where('created_at', '<', now()->subDay())->update(['status' => ReleaseStatus::Pruned]);

        // Container images and function releases are pruned by the agent itself.
        if ($site->runtime === SiteRuntime::Docker || $site->runtime === SiteRuntime::Function) {
            return;
        }

        foreach ($deployment->targets as $target) {
            try {
                $this->agents->dispatch($target->server_id, 'deploy.prune', [
                    'site' => $site->slug,
                    'sites_root' => dirname($site->rootPath),
                    'keep' => $keep,
                    'protect' => [strtoupper($current->id)],
                ], (int) config('deployments.timeouts.prune', 300), "deploy:{$deployment->id}:prune:{$target->server_id}");
            } catch (Throwable $e) {
                Log::info('deployments: prune not dispatched', ['deployment' => $deployment->id, 'server' => $target->server_id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  Collection<string, DeploymentStep>  $rollback
     */
    private function finishFailed(Deployment $deployment, Collection $rollback): void
    {
        $rolledBackServers = $rollback->filter(fn (DeploymentStep $s) => in_array($s->kind, [StepKind::Revert, StepKind::RevertSwap], true) && $s->status === StepStatus::Succeeded)
            ->pluck('server_id')->filter()->values()->all();
        $rolledBack = $rolledBackServers !== [];
        $cancelled = $deployment->cancel_requested;
        $failedPhase = $deployment->steps()->where('rollback', false)->where('status', StepStatus::Failed)->value('phase');

        if ($deployment->trigger !== Trigger::Rollback && $deployment->release_id !== null) {
            Release::query()->whereKey($deployment->release_id)->where('status', ReleaseStatus::Pending)->update(['status' => ReleaseStatus::Failed, 'updated_at' => now()]);
        }

        $deployment->forceFill([
            'status' => $cancelled ? DeploymentStatus::Cancelled : DeploymentStatus::Failed,
            'rolled_back' => $rolledBack,
            'phase' => null,
            'finished_at' => now(),
            'error' => $cancelled ? 'Cancelled.' : ($deployment->error ?? 'The deployment failed.'),
        ])->save();

        $deployment->targets()->whereIn('status', [TargetStatus::Pending, TargetStatus::Deploying])->update(['status' => TargetStatus::Skipped, 'updated_at' => now()]);

        $this->log->note($deployment->id, $cancelled ? 'Deployment cancelled.' : 'Deployment failed'.($rolledBack ? '; '.count($rolledBackServers).' server(s) rolled back.' : '.'), stream: $cancelled ? 'stdout' : 'stderr');
        $this->updated($deployment);

        $activatedServers = $deployment->targets()->where('activated', true)->pluck('server_id')->all();

        $this->afterCommit(function () use ($deployment, $cancelled, $rolledBack, $rolledBackServers, $failedPhase, $activatedServers) {
            $this->annotate($deployment, $rolledBack ? 'rolled_back' : 'failed');

            if (! $cancelled) {
                DeploymentFailed::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->site_slug, $deployment->number,
                    $deployment->trigger->value, $failedPhase, (string) $deployment->error, $deployment->commit, $rolledBack);
            }

            if ($rolledBack) {
                DeploymentRolledBack::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->site_slug, $deployment->previous_release_id, $rolledBackServers, true);
            }

            if ($activatedServers !== []) {
                $this->processes->converge(...$activatedServers);
            }

            $this->queue->startNext($deployment->site_id);
        });
    }

    // ---- plumbing -----------------------------------------------------------------------------

    /**
     * @param  Closure(Deployment): void  $callback
     */
    private function locked(string $deploymentId, Closure $callback): void
    {
        $this->depth++;

        try {
            DB::transaction(function () use ($deploymentId, $callback) {
                $deployment = Deployment::query()->lockForUpdate()->find($deploymentId);

                if ($deployment !== null) {
                    $callback($deployment);
                }
            });
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $after = $this->after;
            $this->after = [];

            foreach ($after as $effect) {
                $effect();
            }
        }
    }

    private function afterCommit(Closure $effect): void
    {
        $this->after[] = $effect;
    }

    private function updated(Deployment $deployment): void
    {
        $id = $deployment->id;
        $siteId = $deployment->site_id;
        $status = $deployment->status->value;
        $phase = $deployment->phase;
        $this->afterCommit(fn () => DeploymentUpdated::dispatch($id, $siteId, $status, $phase));
    }

    private function annotate(Deployment $deployment, string $status): void
    {
        $text = sprintf('Deployment #%d of %s %s%s (%s)', $deployment->number, $deployment->site_slug, $status,
            $deployment->commit ? ' @ '.$deployment->shortCommit() : '', $deployment->trigger->value);

        $this->annotations->deployment(
            $deployment->organization_id,
            strtoupper($deployment->id),
            strtoupper($deployment->site_id),
            $status,
            $text,
            $deployment->started_at,
            $status === 'started' ? null : ($deployment->finished_at ?? now()),
            array_values(array_filter(['site:'.$deployment->site_slug, $deployment->strategy?->value, $deployment->trigger->value])),
        );
    }

    private function commandError(?string $error, ?int $exitCode, string $status): string
    {
        return $error ?: match ($status) {
            'timed_out' => 'Timed out.',
            'cancelled' => 'Cancelled.',
            default => $exitCode !== null ? "Exited with code {$exitCode}." : 'Failed.',
        };
    }

    private static function ulid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $value) === 1 ? strtolower($value) : null;
    }
}
