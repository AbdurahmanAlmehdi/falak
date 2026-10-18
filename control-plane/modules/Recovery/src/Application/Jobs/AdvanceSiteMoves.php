<?php

namespace Falak\Recovery\Application\Jobs;

use Falak\Recovery\Application\SiteMoveRunner;
use Falak\Recovery\Domain\Models\SiteMove;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Every minute (scheduler): running site moves move on as their backups, databases, volumes and deployments finish;
 * a "Move all sites" starts its next queued move.
 */
final class AdvanceSiteMoves implements ShouldQueue
{
    use Queueable;

    public function handle(SiteMoveRunner $runner): void
    {
        SiteMove::query()->where('status', 'running')->orderBy('created_at')->each(fn (SiteMove $move) => $runner->advance($move));
        $runner->kick();
    }
}
