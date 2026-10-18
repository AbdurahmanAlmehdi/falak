<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a volume to another server: its services stop → archive → restore there into a volume of the same name → its
 * services switch to it and redeploy → the source is deleted. Each step starts when the previous one succeeded
 * (HandleCommandOutcome); a failure stops the move with the source untouched and redeploys the services where they
 * were. The services are down from the archive to the redeploy. They must already run on the target server.
 *
 * A compose stack's volume (moving its site, Recovery's SiteMove only: $compose) is restored into a volume of the same
 * Docker name and compose key on the target, which the stack's `up` there uses; nothing is handed over or deleted
 * here (the stack switches when it deploys there, the source goes with VolumeRecovery::finishMove()).
 */
final class MoveVolume
{
    public function __construct(
        private readonly CreateVolume $create,
        private readonly RunVolumeBackup $backup,
        private readonly SiteDirectory $sites,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Volume $source, string $serverId, string $storageProviderId, ?string $actorId = null, bool $compose = false): Operation
    {
        if (! $source->kind->portable() || $source->status !== VolumeStatus::Active) {
            throw ValidationException::withMessages(['volume' => 'Only active Docker and sized volumes can be moved.']);
        }

        if ($source->composeKey() !== null && ! $compose) {
            throw ValidationException::withMessages(['volume' => 'A compose stack’s volume moves with its stack: clone it instead.']);
        }

        if ($serverId === $source->server_id) {
            throw ValidationException::withMessages(['server_id' => 'Choose another server.']);
        }

        if (Operation::query()->where('meta->source_id', $source->id)->where('kind', OperationKind::Move)->where('status', OperationStatus::Running)->exists()) {
            throw ValidationException::withMessages(['volume' => 'The volume is already being moved.']);
        }

        foreach ($source->attachments as $attachment) {
            /** @var Attachment $attachment */
            $site = $attachment->siteId() !== null ? $this->sites->find($attachment->siteId()) : null;

            if ($site !== null && $site->target($serverId) === null) {
                throw ValidationException::withMessages(['server_id' => "{$site->name} does not run on that server: add the server to it first."]);
            }
        }

        $target = $this->create->prepare($source->organization_id, $serverId, $source->name, $source->kind, $source->size_limit_bytes, labels: (array) $source->labels, protected: $source->protected, actorId: $actorId);
        $compose = $source->composeKey() !== null;

        if ($compose) {
            // The stack's `up` on the target finds the restored volume by its Docker name; SyncComposeVolumes by its key.
            $target->forceFill(['docker_name' => $source->docker_name, 'options' => $source->options]);
        }

        $operation = DB::transaction(function () use ($target, $source, $actorId, $compose) {
            CreateVolume::save($target);

            return Operation::query()->create([
                'organization_id' => $source->organization_id,
                'volume_id' => $target->id,
                'kind' => OperationKind::Move,
                'status' => OperationStatus::Running,
                'meta' => ['source_id' => $source->id, 'source_name' => $source->name, 'from_server_id' => $source->server_id, ...($compose ? ['compose' => true] : [])],
                'requested_by' => $actorId,
            ]);
        });

        try {
            $backup = ($this->backup)($source, $storageProviderId, Consistency::Stop, 'move', actorId: $actorId);

            if ($backup->status === BackupStatus::Failed) {
                throw ValidationException::withMessages(['volume' => (string) $backup->error]);
            }
        } catch (ValidationException $e) {
            $operation->delete();
            $target->delete();

            throw $e;
        }

        $operation->forceFill(['step' => 'archive', 'command_id' => $backup->command_id, 'meta' => [...(array) $operation->meta, 'backup_id' => $backup->id]])->save();

        $this->audit->record('volumes.move_started', 'volume', $source->id, ['name' => $source->name, 'from' => $source->server_id, 'to' => $serverId], $source->organization_id);

        return $operation;
    }
}
