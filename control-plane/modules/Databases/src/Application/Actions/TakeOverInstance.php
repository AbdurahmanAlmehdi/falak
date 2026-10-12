<?php

namespace Falak\Databases\Application\Actions;

use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A new instance takes over an old one (a major upgrade's copy, a point-in-time restore swapped in): its databases,
 * users, backup schedules, DNS name and host port (and the published addresses with it), and what a falak.yaml
 * managed of it. The old one is retired: stopped, its data volume kept until someone deletes it; its container goes at
 * $retireAt (a major upgrade, once the new one is healthy) or stays (null: a swapped-out instance keeps everything).
 */
final class TakeOverInstance
{
    public function __construct(
        private readonly ApplyInstance $apply,
        private readonly ServiceVolumes $volumes,
        private readonly ManagedResources $managed,
    ) {}

    /**
     * @param  array<string, mixed>  $targetAttributes  more of the old one the new one takes (a swap: name, environment, access, PITR)
     */
    public function __invoke(DatabaseInstance $source, DatabaseInstance $target, string $retiredMessage, ?Carbon $retireAt, array $targetAttributes = []): void
    {
        DB::transaction(function () use ($source, $target, $retiredMessage, $retireAt, $targetAttributes) {
            $hostPort = $source->host_port;
            $hostname = $source->hostname;

            $source->forceFill([
                'status' => InstanceStatus::Retired,
                'status_message' => $retiredMessage,
                'replaced_by' => $target->id,
                'host_port' => null,
                'hostname' => "falak-db-{$source->id}",
                'retire_at' => $retireAt,
            ])->save();

            Database::query()->where('database_instance_id', $source->id)->update(['database_instance_id' => $target->id, 'status' => ResourceStatus::Active]);
            DatabaseUser::query()->where('database_instance_id', $source->id)->update(['database_instance_id' => $target->id, 'status' => ResourceStatus::Active]);
            BackupSchedule::query()->where('database_instance_id', $source->id)->update(['database_instance_id' => $target->id]);

            $target->forceFill([
                ...$targetAttributes,
                'status' => InstanceStatus::Active,
                'status_message' => null,
                'hostname' => $hostname,
                'host_port' => $hostPort,
                'published_addresses' => $source->published_addresses,
                'upgrade_of' => null,
                'restored_from' => null,
            ])->save();

            foreach ($target->databases()->get() as $database) {
                $this->volumes->releaseDatabase($database->id);
            }

            if (($primary = $target->databases()->reorder()->orderBy('created_at')->first()) !== null && $target->volume_id !== null) {
                $this->volumes->attach($target->volume_id, AttachableType::Database, $primary->id, '/var/lib/falak/db');
            }

            // A falak.yaml that managed the old container manages the new one (its unit keeps mapping to it).
            $this->managed->move('database_instance', $source->id, $target->id);

            // Publish on the old host port, keep the DNS name across recreations, and a certificate valid for that name.
            ($this->apply)($target, background: true, renewCertificate: true);
        });
    }
}
