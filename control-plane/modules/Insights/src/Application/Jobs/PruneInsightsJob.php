<?php

namespace Falak\Insights\Application\Jobs;

use Falak\Insights\Application\Actions\PruneInsights;
use Falak\Insights\Infrastructure\TrackedTrafficFindings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class PruneInsightsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 3600;

    public function handle(PruneInsights $prune, TrackedTrafficFindings $traffic): void
    {
        $prune();
        $traffic->pruneClientSamples();
    }
}
