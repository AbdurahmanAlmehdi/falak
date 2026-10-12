<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Projects\Contracts\ConfigEnvironments;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * A push to a repository a project follows: the file is read at the pushed commit, and every environment whose
 * `branch` is the pushed branch gets a run (**[decision]** one run per environment, not per site). An environment the
 * file declares and the project doesn't have is created first, by a push to the production environment's branch only
 * (not production: the flag is its run's risky change); an environment the file no longer declares is only no longer
 * managed.
 */
final class RunOnPush implements ShouldQueue
{
    public function __construct(
        private readonly FileFetcher $files,
        private readonly ConfigReader $reader,
        private readonly RunEngine $engine,
        private readonly ProjectDirectory $projects,
        private readonly ImplicitEnvironment $implicit,
        private readonly SiteDirectory $sites,
        private readonly ConfigSources $sources,
        private readonly ConfigReleases $releases,
        private readonly ConfigEnvironments $environments,
    ) {}

    public function handle(PushReceived $event): void
    {
        $sources = ConfigSource::query()
            ->where('organization_id', $event->organizationId)
            ->where('source_connection_id', $event->connectionId)
            ->where('enabled', true)
            ->get()
            ->filter(fn (ConfigSource $s) => strcasecmp($s->repository, $event->repository) === 0);

        foreach ($sources as $source) {
            $this->push($source, $event);
        }
    }

    private function push(ConfigSource $source, PushReceived $event): void
    {
        [$raw, $problem] = $this->files->fetch($source, $event->commit->sha);
        $environments = $this->projects->environments($source->project_id);

        if ($raw === null) {
            // The file isn't readable at the commit: the environments the last applied file bound to this branch get a
            // failed run each (other branches record nothing), and their sites deploy the legacy way.
            $this->unplanned($source, $event, (string) $problem, '');

            return;
        }

        $format = $source->format();
        $file = $this->reader->read($raw, $format);
        $bound = [];

        if ($file->ok() && ImplicitEnvironment::applies($file->document)) {
            $production = $this->implicit->environmentFor($source, $event->branch);
            $bound = $production !== null ? [$production->slug] : [];
        } elseif ($file->ok() && $file->document instanceof stdClass && ($file->document->environments ?? null) instanceof stdClass) {
            foreach ((array) $file->document->environments as $slug => $env) {
                if ($env instanceof stdClass && ($env->branch ?? null) === $event->branch) {
                    $bound[] = (string) $slug;
                }
            }
        } elseif (! $file->ok()) {
            $this->unplanned($source, $event, "{$source->path} can't be read: {$file->error?->message}", $raw);

            return;
        }

        $productionBranch = $this->productionBranch($source, $file->document, $environments);

        if ($event->branch === $productionBranch && $file->ok() && ! ImplicitEnvironment::applies($file->document)) {
            // [decision] The production branch's file creates every environment it declares (bound to any branch):
            // their runs come with pushes to their branches.
            foreach (array_keys((array) $file->document->environments) as $slug) {
                if (! in_array((string) $slug, $bound, true) && ! collect($environments)->contains(fn (EnvironmentData $e) => $e->slug === (string) $slug && ! $e->isPreview)) {
                    try {
                        $this->environments->create($source->project_id, (string) $slug);
                    } catch (ValidationException $e) {
                        $this->failed($source, $event, null, "The environment \"{$slug}\" can't be created: ".collect($e->errors())->flatten()->first(), $raw, (string) $slug);
                    }
                }
            }
        }

        foreach ($bound as $slug) {
            $environment = collect($environments)->first(fn (EnvironmentData $e) => $e->slug === $slug && ! $e->isPreview);

            if ($environment === null && $event->branch !== $productionBranch) {
                // [decision] Only a push to the production environment's branch creates environments.
                $this->failed($source, $event, null, "The project has no environment \"{$slug}\": environments are created only by a push to "
                    .($productionBranch !== null ? "{$productionBranch} (the production environment's branch)" : "the production environment's branch").'. Push the file there, or create it in the dashboard.', $raw, $slug);

                continue;
            }

            if ($environment === null) {
                // [decision] The file creates the environments it declares (empty, not production).
                try {
                    $environment = $this->environments->create($source->project_id, $slug);
                } catch (ValidationException $e) {
                    $this->failed($source, $event, null, "The environment \"{$slug}\" can't be created: ".collect($e->errors())->flatten()->first(), $raw, $slug);

                    continue;
                }
            }

            $run = $this->engine->start($source, $environment, $raw, $format, RunTrigger::Push, $event->commit->sha, $event->branch,
                changedPaths: $event->changedPaths, commitInfo: ['message' => $event->commit->message, 'author' => $event->commit->authorName ?? $event->pusher]);

            if ($run->status === RunStatus::Failed && $run->plan === null) {
                $this->legacyDeploy($event, $environment->id); // the file is invalid: nothing was planned
            }
        }

        if ($file->ok()) {
            // [decision] A file without `environments` declares production only: the others it bound are released.
            $declared = ImplicitEnvironment::applies($file->document)
                ? array_values(array_map(fn (EnvironmentData $e) => $e->slug, array_filter($environments, fn (EnvironmentData $e) => $e->isProduction && ! $e->isPreview)))
                : array_keys((array) $file->document->environments);
            $this->released($source, $event, $declared);
        }
    }

    /**
     * The branch the production environment follows: the pushed file's `environments.<production>.branch`, else the
     * repository's default branch (a file without environments binds it).
     *
     * @param  list<EnvironmentData>  $environments
     */
    private function productionBranch(ConfigSource $source, mixed $document, array $environments): ?string
    {
        $production = collect($environments)->first(fn (EnvironmentData $e) => $e->isProduction && ! $e->isPreview);
        $declared = $production !== null && $document instanceof stdClass && ($document->environments ?? null) instanceof stdClass
            ? ($document->environments->{$production->slug} ?? null) : null;

        return $declared instanceof stdClass && isset($declared->branch) ? (string) $declared->branch : $this->implicit->defaultBranch($source);
    }

    /**
     * **[decision]** An environment the last applied file bound to the pushed branch that the pushed file no longer
     * declares is released, never deleted: its sites deploy this push the old way and are no longer managed (their
     * fields are editable again and push to deploy takes them back).
     *
     * @param  list<string>  $declared  the environments of the pushed file
     */
    private function released(ConfigSource $source, PushReceived $event, array $declared): void
    {
        foreach ($this->projects->environments($source->project_id) as $environment) {
            if ($environment->isPreview || in_array($environment->slug, $declared, true)) {
                continue;
            }

            $applied = ConfigRun::query()->where('environment_id', $environment->id)->baseline()->first();

            if (($applied?->resolved['environments'][$environment->slug]['branch'] ?? null) !== $event->branch) {
                continue;
            }

            // Under the environment's run lock: a run applying now finishes first, and none starts mid-release.
            Cache::lock('config:environment:'.$environment->id, 600)->block(120, function () use ($event, $environment, $applied) {
                $this->legacyDeploy($event, $environment->id);
                $this->listReleased($environment->id, $applied);
                ConfigOwnership::query()->where('environment_id', $environment->id)->delete();

                // A run still waiting can't apply the file to it later.
                ConfigRun::query()->where('environment_id', $environment->id)->whereIn('status', [RunStatus::Planning, RunStatus::AwaitingApproval])
                    ->update(['status' => RunStatus::Cancelled, 'cancel_reason' => 'environment_released', 'error' => 'The environment is no longer in the file: it was released.',
                        'finished_at' => now(), 'updated_at' => now()]);
            });
        }
    }

    /**
     * The file at the commit can't be planned (missing, unreadable, not parseable). **[decision]** Runs are recorded
     * only for environments bound to the pushed branch by the file last applied there; the sites Config deploys on push
     * fall back to push to deploy for this push.
     */
    private function unplanned(ConfigSource $source, PushReceived $event, string $error, string $raw): void
    {
        foreach ($this->projects->environments($source->project_id) as $environment) {
            $applied = ConfigRun::query()->where('environment_id', $environment->id)->baseline()->first();

            if (($applied?->resolved['environments'][$environment->slug]['branch'] ?? null) !== $event->branch) {
                continue;
            }

            $this->failed($source, $event, $environment, $error, $raw);
            $this->legacyDeploy($event, $environment->id);
        }
    }

    /** Push to deploy for the sites of an environment that Config would have deployed (DeployOnPush left them). */
    private function legacyDeploy(PushReceived $event, string $environmentId): void
    {
        foreach ($this->sites->forRepository($event->connectionId, $event->repository, $event->branch) as $site) {
            $service = $this->projects->projectOf(ServiceKind::Site, $site->id);

            if (! $site->pushToDeploy || $site->branch !== $event->branch || $service?->environmentId !== $environmentId || ! $this->sources->managesSite($site->id)) {
                continue;
            }

            try {
                $this->releases->deploy($site->id, $event->branch, $event->commit->sha, $event->commit->message, $event->commit->authorName ?? $event->pusher);
            } catch (Throwable $e) {
                Log::warning('config: push to deploy fallback failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function failed(ConfigSource $source, PushReceived $event, ?EnvironmentData $environment, string $error, string $raw = '', ?string $slug = null): void
    {
        ConfigRun::query()->create([
            'organization_id' => $source->organization_id,
            'project_id' => $source->project_id,
            'environment_id' => $slug === null ? $environment?->id : null,
            'environment' => $slug ?? $environment?->slug ?? '',
            'commit' => $event->commit->sha,
            'branch' => $event->branch,
            'trigger' => RunTrigger::Push,
            'status' => RunStatus::Failed,
            'file' => $raw,
            'format' => $source->format(),
            'error' => $error,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * What a released environment's file managed becomes its unmanaged resources (reason `environment_released`): one
     * row per resource, deleted from its own page if no longer wanted.
     */
    private function listReleased(string $environmentId, ConfigRun $applied): void
    {
        $owned = ConfigOwnership::query()->where('environment_id', $environmentId)->where('resource_type', '!=', 'environment')
            ->get(['organization_id', 'project_id', 'resource_type', 'resource_id', 'unit'])->unique(fn (ConfigOwnership $o) => $o->resource_type.'|'.$o->resource_id);

        foreach ($owned as $row) {
            $kind = ucfirst(str_replace('_', ' ', (string) $row->resource_type));
            ConfigDetached::query()->updateOrCreate(
                ['environment_id' => $environmentId, 'resource_type' => $row->resource_type, 'resource_id' => $row->resource_id],
                [
                    'organization_id' => $row->organization_id,
                    'project_id' => $row->project_id,
                    'unit' => $row->unit,
                    'label' => $row->unit !== null ? "{$kind} ({$row->unit})" : $kind,
                    'reason' => ConfigDetached::ENVIRONMENT_RELEASED,
                    'delete_url' => null,
                    'run_id' => $applied->id,
                    'detached_at' => now(),
                ],
            );
        }
    }
}
