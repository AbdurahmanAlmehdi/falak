<?php

namespace Falak\Network\Application\Listeners;

use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Network\Application\ConfigureEbpf;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Settles the eBPF setting's delivery (docs/plans/network/ebpf.md) and re-sends it to an upgraded agent (which may
 * only now list `net.ebpf`).
 */
final class HandleEbpfOutcome implements ShouldQueue
{
    public function __construct(private readonly ConfigureEbpf $configure) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type === 'net.ebpf.configure') {
            $this->configure->settled($event->commandId, true, result: $event->result);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type === 'net.ebpf.configure') {
            $this->configure->settled($event->commandId, false, $event->error ?: "Command {$event->status}");
        }
    }

    public function handleUpgrade(AgentVersionChanged $event): void
    {
        if ($event->serverId !== null) {
            ($this->configure)($event->serverId, force: true);
        }
    }
}
