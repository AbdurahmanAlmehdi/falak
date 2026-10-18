<?php

namespace Falak\Recovery\Application;

use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Falak\Databases\Contracts\Data\StorageProviderData;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Volumes\Contracts\Data\VolumeRecoveryPoint;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeRecovery;
use Illuminate\Validation\ValidationException;

/**
 * The dry run of "Move to another server": what moves (the databases on the old server only this site uses, its
 * volumes there), what stays (shared databases), what happens to its domains, the expected downtime, and whether the
 * target can take it (runtime, Docker, memory, disk, object storage). Nothing is changed.
 */
final class SiteMovePlanner
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly SiteRelocation $relocation,
        private readonly DatabaseRecovery $databases,
        private readonly VolumeRecovery $volumes,
        private readonly ServiceVolumes $serviceVolumes,
        private readonly DomainRecords $domains,
        private readonly BackupStorage $storage,
        private readonly AgentDirectory $agents,
        private readonly CapacitySources $capacity,
        private readonly ManagedResources $managed,
    ) {}

    /**
     * @param  ?string  $fromServerId  the server it leaves (default: its leader)
     * @return array<string, mixed>
     *
     * @throws ValidationException when the servers are not this organization's or the same
     */
    public function plan(SiteData $site, ?string $targetServerId, ?string $fromServerId = null, ?string $storageProviderId = null): array
    {
        $fromServerId ??= $site->targets !== [] ? (collect($site->targets)->first(fn ($t) => $t->isLeader()) ?? $site->targets[0])->serverId : null;
        $from = $fromServerId !== null ? $this->servers->find(strtolower($fromServerId)) : null;
        $target = $targetServerId !== null ? $this->servers->find(strtolower($targetServerId)) : null;

        if ($from === null || $from->organizationId !== $site->organizationId || $site->target($from->id) === null) {
            throw ValidationException::withMessages(['from_server_id' => "{$site->name} does not run on that server."]);
        }

        if ($targetServerId !== null && ($target === null || $target->organizationId !== $site->organizationId)) {
            throw ValidationException::withMessages(['server_id' => 'Choose a server of this organization.']);
        }

        if ($target !== null && $target->id === $from->id) {
            throw ValidationException::withMessages(['server_id' => "Choose another server than {$from->name}."]);
        }

        $blocking = [];
        $problems = [];

        if ($this->managed->managed('site', $site->id)->covers('/servers')) {
            $blocking[] = "falak.yaml sets this site's servers: change them there instead.";
        }

        $databases = $this->databasesOf($site, $from);
        $volumes = array_values(array_filter($this->volumes->forSites([$site->id]), fn (VolumeRecoveryPoint $point) => $point->serverId === $from->id));
        $moving = array_filter($databases, fn (array $instance) => $instance['move']);
        $stateful = $moving !== [] || $volumes !== [];

        $providers = $this->storage->providers($site->organizationId);
        $provider = $storageProviderId !== null
            ? collect($providers)->first(fn (StorageProviderData $p) => $p->id === strtolower($storageProviderId))
            : ($providers[0] ?? null);

        if ($storageProviderId !== null && $provider === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if ($stateful && $provider === null) {
            $blocking[] = 'Its databases and volumes travel through object storage: add a backup storage provider (Settings → Backup storage) first.';
        }

        $capacity = null;

        if ($target !== null) {
            if (! $target->isActive()) {
                $blocking[] = "{$target->name} is {$target->status->label()}: wait until it is active.";
            }

            if (($problem = $this->relocation->problemOn($site->id, $target->id)) !== null) {
                $blocking[] = $problem;
            }

            if (! $target->docker && ($stateful || in_array($site->runtime, [SiteRuntime::Docker, SiteRuntime::Compose], true))) {
                $blocking[] = "{$target->name} has no Docker: its containers and volumes need it.";
            }

            $capacity = $this->capacity($site, $from, $target, array_map(fn (array $i) => $i['id'], $moving), $volumes);
            array_push($problems, ...$capacity['warnings']);
        }

        foreach ($databases as $instance) {
            if (! $instance['move']) {
                $problems[] = "{$instance['name']} is shared ({$instance['reason']}): it stays on {$from->name} — move it separately.";
            }
        }

        $domains = array_map(fn (array $domain) => [
            ...$domain,
            'kind' => match (true) {
                // In a managed zone but without a record Falak keeps: nothing follows on its own, the user moves it.
                $domain['managed'] && $domain['status'] !== null => 'managed',
                $from->ipv4 !== null && str_contains($domain['name'], '.'.str_replace('.', '-', $from->ipv4).'.') => 'generated',
                default => 'manual',
            },
        ], $this->domains->forSites([$site->id]));

        if (collect($domains)->contains('kind', 'generated')) {
            $problems[] = 'Its generated domains (sslip.io …) carry the old server\'s IP: they are replaced by names for the new IP at the cutover. Links to the old names stop working — add a test domain or your own domain to keep a stable address.';
        }

        $downtime = match (true) {
            $volumes !== [] && $moving !== [] => 'Services that mount its volumes are down from their snapshot until the cutover redeploy. Writes to the moved databases after their backup starts are not carried over: pause writes (e.g. maintenance mode) for the move.',
            $volumes !== [] => 'Services that mount its volumes are down from their snapshot until the redeploy on the new server (the time to copy them).',
            $moving !== [] => 'No downtime, but writes to the moved databases after their backup starts are not carried over: pause writes (e.g. maintenance mode) for the move.',
            default => 'None: the new server serves the site before the old one leaves. Visitors switch as DNS changes reach them.',
        };

        return [
            'site' => ['id' => $site->id, 'name' => $site->name, 'runtime' => $site->runtime->value],
            'from' => self::server($from),
            'target' => $target !== null ? self::server($target) : null,
            'blocking' => $blocking,
            'problems' => $problems,
            'databases' => $databases,
            'volumes' => array_map(fn (VolumeRecoveryPoint $point) => [
                'id' => $point->volumeId,
                'name' => $point->name,
                'kind' => $point->kind,
                'used_bytes' => $this->serviceVolumes->find($point->volumeId)?->usedBytes,
            ], $volumes),
            'domains' => $domains,
            'downtime' => $downtime,
            'stateful' => $stateful,
            'storage_provider_id' => $provider?->id,
            'storage_providers' => array_map(fn (StorageProviderData $p) => ['id' => $p->id, 'name' => $p->name], $providers),
            'capacity' => $capacity,
        ];
    }

    /**
     * The database containers on the old server holding this site's databases: moved when every database in them is
     * this site's, otherwise shared (left alone).
     *
     * @return list<array{id: string, name: string, engine: string, version: string, move: bool, reason: ?string, pitr: bool, databases: list<string>}>
     */
    private function databasesOf(SiteData $site, ServerData $from): array
    {
        $out = [];

        foreach ($this->databases->instancesOn($from->id) as $instance) {
            /** @var InstanceRecoveryPoint $instance */
            $owners = array_map(fn (DatabaseRecoveryPoint $point) => $point->siteId, $instance->databases);

            if (! in_array($site->id, $owners, true)) {
                continue;
            }

            $others = array_filter($owners, fn (?string $owner) => $owner !== $site->id);

            $out[] = [
                'id' => $instance->id,
                'name' => $instance->name,
                'engine' => $instance->engine,
                'version' => $instance->version,
                'move' => $others === [],
                'reason' => $others === [] ? null : (in_array(null, $others, true) ? 'it also holds databases not linked to this site' : 'other sites use it too'),
                'pitr' => $instance->pitrEnabled,
                'databases' => array_map(fn (DatabaseRecoveryPoint $point) => $point->name, $instance->databases),
            ];
        }

        return $out;
    }

    /**
     * What the site's services may use (their memory limits on the old server, the moving databases included) against
     * what the target has left (its RAM minus every limit already there), and the moving volumes against its disk.
     *
     * @param  list<string>  $instanceIds
     * @param  list<VolumeRecoveryPoint>  $volumes
     * @return array{needed_mb: int, free_mb: ?int, unlimited: bool, volume_bytes: int, disk_bytes: ?int, warnings: list<string>}
     */
    private function capacity(SiteData $site, ServerData $from, ServerData $target, array $instanceIds, array $volumes): array
    {
        $needed = 0;
        $unlimited = false;
        $used = 0;

        foreach ($this->capacity->all() as $source) {
            foreach ($source->onServer($site->organizationId, $from->id) as $item) {
                $mine = $item->id === $site->id || str_starts_with($item->id, "{$site->id}:") || in_array($item->id, $instanceIds, true);

                if ($mine) {
                    $needed += $item->memoryLimitMb ?? 0;
                    $unlimited = $unlimited || $item->memoryLimitMb === null;
                }
            }

            foreach ($source->onServer($site->organizationId, $target->id) as $item) {
                $used += $item->memoryLimitMb ?? 0;
            }
        }

        $facts = $this->agents->forServer($target->id)?->facts ?? [];
        $memoryMb = is_numeric($facts['memory_bytes'] ?? null) ? intdiv((int) $facts['memory_bytes'], 1024 ** 2) : null;
        $disk = is_numeric($facts['disk_bytes'] ?? null) ? (int) $facts['disk_bytes'] : null;
        // Memory kept for the control plane on its own machine is never the workloads'.
        $free = $memoryMb !== null ? $memoryMb - $used - $target->reservedMemoryMb : null;
        $volumeBytes = array_sum(array_map(fn (VolumeRecoveryPoint $point) => (int) ($this->serviceVolumes->find($point->volumeId)?->usedBytes ?? 0), $volumes));

        $warnings = array_values(array_filter([
            $free !== null && $needed > $free ? "{$target->name} has {$free} MB of memory not yet promised to other services; this site's limits add up to {$needed} MB." : null,
            $memoryMb === null ? "{$target->name} has not reported its memory yet: the capacity check is skipped." : null,
            // ponytail: total disk only (the agent reports no free space); the copy needs room for the volumes' data.
            $disk !== null && $volumeBytes > 0 && $volumeBytes > $disk * 0.8 ? 'Its volumes hold '.round($volumeBytes / 1024 ** 3, 1)." GB: {$target->name}'s disk (".round($disk / 1024 ** 3, 1).' GB) may not fit them.' : null,
        ]));

        return ['needed_mb' => $needed, 'free_mb' => $free, 'unlimited' => $unlimited, 'volume_bytes' => $volumeBytes, 'disk_bytes' => $disk, 'warnings' => $warnings];
    }

    /** @return array{id: string, name: string, ipv4: ?string, ipv6: ?string, status: string} */
    private static function server(ServerData $server): array
    {
        return ['id' => $server->id, 'name' => $server->name, 'ipv4' => $server->ipv4, 'ipv6' => $server->ipv6, 'status' => $server->status->value];
    }
}
