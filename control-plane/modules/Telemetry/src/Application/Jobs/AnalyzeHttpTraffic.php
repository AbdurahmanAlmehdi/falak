<?php

namespace Falak\Telemetry\Application\Jobs;

use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Telemetry\Application\Http\EvaluateHttpTraffic;
use Falak\Telemetry\Application\Http\RecordBandwidth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP analytics per organization (docs/plans/network/http.md): `evaluate` (every 5 minutes) decides slow endpoints,
 * scanners and alerts from the access logs; `bandwidth` (every 10 minutes) records bytes per site and hour. One
 * organization failing (Loki rejecting a query) never stops the others.
 */
final class AnalyzeHttpTraffic implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $task = 'evaluate') {}

    public function handle(OrganizationDirectory $organizations, EvaluateHttpTraffic $evaluate, RecordBandwidth $bandwidth): void
    {
        $loki = (string) config('telemetry.loki.url') !== '';

        if ($this->task === 'evaluate' && ! $loki) {
            return;
        }

        foreach ($organizations->all() as $organization) {
            try {
                $this->task === 'evaluate' ? $evaluate($organization->id) : $bandwidth($organization->id);
            } catch (Throwable $e) {
                Log::warning("HTTP analytics ({$this->task}) failed for organization {$organization->id}: {$e->getMessage()}");
            }
        }
    }
}
