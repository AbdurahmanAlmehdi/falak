<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Deployments\Application\Orchestration\Orchestrator;
use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Advances deployments when their agent commands finish (proc.apply: the restart step converges the new
 * release's program env through Processes). Idempotent: a re-delivered event for a
 * settled step is ignored by the Orchestrator.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    private const TYPES = ['deploy.fetch', 'deploy.prepare', 'deploy.hook', 'deploy.activate', 'deploy.rollback', 'deploy.container.swap', 'proc.restart', 'proc.apply', 'system.exec', 'docker.compose.pull', 'docker.compose.up', 'fn.release.apply'];

    public function __construct(private readonly Orchestrator $orchestrator) {}

    public function handleFinished(CommandFinished $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->orchestrator->commandSettled($event->commandId, true, $event->exitCode, $event->result, null);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->orchestrator->commandSettled($event->commandId, false, $event->exitCode, $event->result, $event->error, $event->status);
        }
    }
}
