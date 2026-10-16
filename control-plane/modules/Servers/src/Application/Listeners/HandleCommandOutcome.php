<?php

namespace Falak\Servers\Application\Listeners;

use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Application\Actions\RecordMachineCheck;
use Falak\Servers\Application\Actions\SyncServerSshKeys;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\PhpVersionChanged;
use Falak\Servers\Events\ServerAttentionCleared;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to the outcome of commands Servers dispatched: machine checks, provisioning plans and PHP version changes.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(
        private readonly ServerStatusUpdater $status,
        private readonly SyncServerSshKeys $syncKeys,
        private readonly AuditLog $audit,
        private readonly RecordMachineCheck $machineCheck,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        $server = Server::query()->find($event->serverId);

        if (! $server || $server->organization_id !== $event->organizationId) {
            return;
        }

        if ($event->commandId === $server->provision_command_id) {
            $this->provisioned($server);
        }

        if ($inspection = $this->inspection($server, $event->commandId)) {
            $this->machineCheck->finished($server, $inspection, $event->result);
        }

        $this->settlePhpVersions($server, $event->commandId, succeeded: true, error: null);
    }

    public function handleFailed(CommandFailed $event): void
    {
        $server = Server::query()->find($event->serverId);

        if (! $server || $server->organization_id !== $event->organizationId) {
            return;
        }

        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        if ($inspection = $this->inspection($server, $event->commandId)) {
            $this->machineCheck->failed($server, $inspection, $reason);
        }

        if ($event->commandId === $server->provision_command_id && $server->status === ServerStatus::Provisioning) {
            $this->status->set($server, ServerStatus::Error, "Provisioning failed: {$reason}");
            $this->audit->record('server.provisioning_failed', 'server', $server->id, ['command_id' => $event->commandId, 'status' => $event->status], $server->organization_id);
        }

        $this->settlePhpVersions($server, $event->commandId, succeeded: false, error: $reason);
    }

    /**
     * The server's running machine check, when the command is its provision.inspect.
     */
    private function inspection(Server $server, string $commandId): ?MachineInspection
    {
        return MachineInspection::query()->where('server_id', $server->id)->where('command_id', $commandId)->where('status', MachineInspection::RUNNING)->first();
    }

    private function provisioned(Server $server): void
    {
        // Error covers a control-plane timeout that the agent later overturned with a successful result.
        $wasProvisioning = in_array($server->status, [ServerStatus::Provisioning, ServerStatus::Error], true);

        // Versions that the plan installed / removed.
        $server->phpVersions()->where('status', PhpVersionStatus::Installing)->where(fn ($q) => $q->whereNull('command_id')->orWhere('command_id', $server->provision_command_id))
            ->update(['status' => PhpVersionStatus::Installed, 'status_message' => null]);

        if (! $wasProvisioning) {
            return;
        }

        $this->status->set($server, ServerStatus::Active, null, ['provisioned_at' => $server->provisioned_at ?? now()]);
        $this->audit->record('server.provisioned', 'server', $server->id, ['attempt' => $server->provision_attempts], $server->organization_id);

        ServerProvisioned::dispatch($server->id, $server->organization_id, $server->type->value, $server->name);
        ServerAttentionCleared::dispatch($server->id, $server->organization_id, $server->name);

        ($this->syncKeys)($server->refresh());
    }

    private function settlePhpVersions(Server $server, string $commandId, bool $succeeded, ?string $error): void
    {
        $server->phpVersions()->where('command_id', $commandId)->get()->each(function (PhpVersion $php) use ($server, $succeeded, $error) {
            if ($php->status === PhpVersionStatus::Removing) {
                if ($succeeded) {
                    $php->delete();
                    PhpVersionChanged::dispatch($server->id, $server->organization_id, $php->version, 'removed');
                } else {
                    $php->forceFill(['status' => PhpVersionStatus::Installed, 'status_message' => "Removal failed: {$error}"])->save();
                }

                return;
            }

            // Failed + success = a late result after a control-plane timeout.
            if ($php->status === PhpVersionStatus::Installing || ($succeeded && $php->status === PhpVersionStatus::Failed)) {
                $php->forceFill([
                    'status' => $succeeded ? PhpVersionStatus::Installed : PhpVersionStatus::Failed,
                    'status_message' => $succeeded ? null : $error,
                ])->save();

                if ($succeeded) {
                    PhpVersionChanged::dispatch($server->id, $server->organization_id, $php->version, 'installed');
                }
            }
        });
    }
}
