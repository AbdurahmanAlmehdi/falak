<?php

namespace Falak\Builds\Application\Actions;

use Falak\Builds\Application\BuildProgress;
use Falak\Builds\Application\JobPayload;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\Builder;
use Falak\Builds\Events\BuildUpdated;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hand the oldest eligible queued build to a polling builder. The claim is a conditional UPDATE, so
 * two builders polling at once never get the same build.
 */
final class AssignBuild
{
    public function __construct(
        private readonly JobPayload $payloads,
        private readonly BuildProgress $progress,
    ) {}

    /**
     * @return ?array{build: Build, job: array<string, mixed>}
     */
    public function __invoke(Builder $builder, ?string $runId = null, ?string $name = null): ?array
    {
        if (! $builder->enabled) {
            return null;
        }

        $candidates = Build::query()
            ->where('status', BuildStatus::Queued)
            ->whereIn('mode', $builder->modes)
            ->when($builder->organization_id !== null, fn ($q) => $q->where('organization_id', $builder->organization_id))
            // Pinned (on-server) builds go only to the builder on their server; unpinned ones only to pool builders.
            ->where(fn ($q) => $q
                ->when($builder->server_id !== null, fn ($q) => $q->orWhere('builder_server_id', $builder->server_id))
                ->when($builder->pool, fn ($q) => $q->orWhereNull('builder_server_id'))
                ->when($builder->server_id === null && ! $builder->pool, fn ($q) => $q->whereRaw('1 = 0')))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $key = $candidate->mode === 'native' ? JobPayload::artifactKey($candidate) : null;

            $claimed = Build::query()->whereKey($candidate->id)->where('status', BuildStatus::Queued)->update([
                'status' => BuildStatus::Assigned,
                'builder_id' => $builder->id,
                'assigned_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'builder_name' => $name,
                'builder_run_id' => $runId,
                'heartbeat_at' => now(),
                'artifact_key' => $key,
                'updated_at' => now(),
            ]);

            if ($claimed !== 1) {
                continue;
            }

            $build = $candidate->refresh();

            try {
                $job = $this->payloads->for($build);
            } catch (Throwable $e) {
                $this->progress->fail($build, 'Could not prepare the build: '.$e->getMessage());

                continue;
            }

            $this->progress->log($build, ["Assigned to builder {$builder->name} (attempt {$build->attempts}).\n"]);
            BuildUpdated::dispatch($build->id, $build->status->value, $build->progress, null);

            return ['build' => $build, 'job' => $job];
        }

        return null;
    }
}
