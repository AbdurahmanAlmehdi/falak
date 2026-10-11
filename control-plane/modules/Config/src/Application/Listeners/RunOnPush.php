<?php

namespace Falak\Config\Application\Listeners;

use Falak\Config\Application\Reading\ConfigReader;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\Runs\RunEngine;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use stdClass;

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
            // Which environments the push targets is in the file: without it, one failed run says why.
            $this->failed($source, $event, $environments[0] ?? null, (string) $problem);

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
            $this->failed($source, $event, $environments[0] ?? null, "{$source->path} can't be read: {$file->error?->message}", $raw);

            return;
        }

        foreach ($bound as $slug) {
            $environment = collect($environments)->first(fn (EnvironmentData $e) => $e->slug === $slug && ! $e->isPreview);

            if ($environment === null) {
                $this->failed($source, $event, null, "The project has no environment \"{$slug}\"; create it in the dashboard (creating environments from the file comes in a later version).", $raw, $slug);

                continue;
            }

            $this->engine->start($source, $environment, $raw, $format, RunTrigger::Push, $event->commit->sha, $event->branch,
                changedPaths: $event->changedPaths, commitInfo: ['message' => $event->commit->message, 'author' => $event->commit->authorName ?? $event->pusher]);
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
