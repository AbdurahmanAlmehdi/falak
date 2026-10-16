<?php

namespace Falak\Fleet\Contracts;

use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;

/**
 * For queued listeners of CommandFinished / CommandFailed that never handle sandbox.* commands: those events aren't
 * queued for them at all (Laravel asks shouldQueue() before it serializes the event). Sandboxes run commands at API
 * rates (exec, files), and each queued listener would otherwise put a job in Redis per command.
 */
trait SkipsSandboxCommands
{
    public function shouldQueue(object $event): bool
    {
        return ! (($event instanceof CommandFinished || $event instanceof CommandFailed) && str_starts_with($event->type, 'sandbox.'));
    }
}
