<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * A push to a repository a project follows: the file is read at the pushed commit, and every environment whose
 * `branch` is the pushed branch gets a run (**[decision]** one run per environment, not per site).
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

        foreach ($bound as $slug) {
            $environment = collect($environments)->first(fn (EnvironmentData $e) => $e->slug === $slug && ! $e->isPreview);

            if ($environment === null) {
                $this->failed($source, $event, null, "The project has no environment \"{$slug}\"; create it in the dashboard (creating environments from the file comes in a later version).", $raw, $slug);

                continue;
            }

            $run = $this->engine->start($source, $environment, $raw, $format, RunTrigger::Push, $event->commit->sha, $event->branch,
                changedPaths: $event->changedPaths, commitInfo: ['message' => $event->commit->message, 'author' => $event->commit->authorName ?? $event->pusher]);

            if ($run->status === RunStatus::Failed && $run->plan === null) {
                $this->legacyDeploy($event, $environment->id); // the file is invalid: nothing was planned
            }
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
            $applied = ConfigRun::query()->where('environment_id', $environment->id)->where('status', RunStatus::Applied)->whereNotNull('resolved')
                ->latest('finished_at')->orderByDesc('id')->first();

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
}
