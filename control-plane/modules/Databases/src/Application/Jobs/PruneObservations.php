<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Domain\Models\DatabaseInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Top query stats, query shapes nobody ran since, and size samples older than databases.observability.retention_days
 * (30) are deleted, and so is what deleted instances left behind. Daily.
 */
final class PruneObservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $cutoff = now()->subDays(max(1, (int) config('databases.observability.retention_days', 30)));

        DB::table('databases_query_stats')->where('hour', '<', $cutoff)->delete();
        DB::table('databases_size_samples')->where('day', '<', $cutoff->toDateString())->delete();

        $stale = DB::table('databases_query_shapes')->where('last_seen_at', '<', $cutoff)->pluck('id');

        foreach ($stale->chunk(500) as $ids) {
            DB::table('databases_query_stats')->whereIn('shape_id', $ids->all())->delete();
            DB::table('databases_query_shapes')->whereIn('id', $ids->all())->delete();
        }

        // Instances that are gone (their rows are deleted when the agent confirms the container is).
        foreach (['databases_query_stats', 'databases_query_shapes', 'databases_size_samples'] as $table) {
            DB::table($table)->whereNotIn('database_instance_id', DatabaseInstance::query()->select('id'))->delete();
        }
    }
}
