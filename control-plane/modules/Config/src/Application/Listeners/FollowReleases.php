<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Application\Runs\RollbackRestorer;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentUpdated;
use Falak\Deployments\Events\ReleaseActivated;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;

/**
 * Releases of config-managed units: **[decision]** phase B runs when the unit's release went live on all its servers
 * (`ReleaseActivated`, after the activate steps and health checks), so a failed deployment never gets the new
 * workers, schedules or domains. The unit's resolved config is saved on the release; a rollback (manual or by the
 * release watch) re-applies the config saved on the release it returns to.
 */
final class FollowReleases
{
    public function __construct(
        private readonly RunEngine $engine,
        private readonly RollbackRestorer $rollbacks,
        private readonly ConfigReleases $releases,
        private readonly ProjectDirectory $projects,
    ) {}

    public function activated(ReleaseActivated $event): void
    {
        $service = $this->projects->projectOf(ServiceKind::Site, $event->siteId);

        if ($service === null) {
            return;
        }

        if ($event->trigger === 'rollback') {
            $this->rollbacks->restore($service->projectId, $service->environmentId, $service->name, $event->releaseId, $event->commit);

            return;
        }

        $run = $this->runOf($service->environmentId, $event->deploymentId);

        if ($run !== null) {
            $this->engine->released($run, $event->deploymentId, $event->releaseId);

            return;
        }

        if ($this->startedByConfig($service->environmentId, $event->deploymentId)) {
            return; // a superseded run's deployment: its release is never paired with another run's config
        }

        // A deployment Config didn't start (the dashboard, the API): the release keeps the config in force.
        $applied = ConfigRun::query()->where('environment_id', $service->environmentId)->where('status', RunStatus::Applied)->latest('finished_at')->first();
        $config = $applied?->unit($service->name);

        if ($config !== null && $this->releases->config($event->releaseId) === null) {
            $this->releases->store($event->releaseId, $config);
        }
    }

    public function failed(DeploymentFailed $event): void
    {
        $service = $this->projects->projectOf(ServiceKind::Site, $event->siteId);
        $run = $service !== null ? $this->runOf($service->environmentId, $event->deploymentId) : null;

        if ($run !== null) {
            $this->engine->deploymentFailed($run, $event->deploymentId, "deployment #{$event->number} failed: {$event->error}");
        }
    }

    public function updated(DeploymentUpdated $event): void
    {
        if ($event->status !== 'cancelled') {
            return;
        }

        $service = $this->projects->projectOf(ServiceKind::Site, $event->siteId);
        $run = $service !== null ? $this->runOf($service->environmentId, $event->deploymentId) : null;

        if ($run !== null) {
            $this->engine->deploymentCancelled($run, $event->deploymentId);
        }
    }

    /** The applying run whose deployment of a unit this is (matched by deployment id, never another run). */
    private function runOf(string $environmentId, string $deploymentId): ?ConfigRun
    {
        return ConfigRun::query()->where('environment_id', $environmentId)->where('status', RunStatus::Applying)->latest()->limit(20)->get()
            ->first(fn (ConfigRun $r) => collect((array) $r->deploy)->contains(fn (array $e) => ($e['deployment_id'] ?? null) === $deploymentId && ($e['status'] ?? null) === 'deploying'));
    }

    private function startedByConfig(string $environmentId, string $deploymentId): bool
    {
        return ConfigRun::query()->where('environment_id', $environmentId)->whereNotNull('deploy')->latest()->limit(50)->get()
            ->contains(fn (ConfigRun $r) => collect((array) $r->deploy)->contains(fn (array $e) => ($e['deployment_id'] ?? null) === $deploymentId));
    }
}
