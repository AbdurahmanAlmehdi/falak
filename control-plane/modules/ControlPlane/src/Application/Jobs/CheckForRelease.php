<?php

namespace Falak\ControlPlane\Application\Jobs;

use Falak\ControlPlane\Application\ReleaseCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every 6 h: is a newer Falak released? (FALAK_UPDATE_CHECK=false turns it off.) */
final class CheckForRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(ReleaseCheck $releases): void
    {
        if ($releases->enabled()) {
            $releases->check();
        }
    }
}
