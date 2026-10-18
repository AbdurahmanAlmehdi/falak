<?php

namespace Falak\Servers\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Database\Factories\ServerFactory;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Stack\Stack;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ServerType $type
 * @property ServerStatus $status
 * @property ?string $status_message
 * @property string $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_server_id
 * @property ?string $region
 * @property ?string $size
 * @property ?string $image
 * @property ?string $ipv4
 * @property ?string $ipv6
 * @property ?string $private_ipv4
 * @property int $ssh_port
 * @property string $timezone
 * @property Stack $stack
 * @property ?array<string, mixed> $facts
 * @property ?string $os
 * @property ?string $arch
 * @property ?int $cpus
 * @property ?int $memory_bytes
 * @property ?int $disk_bytes
 * @property ?string $install_command
 * @property ?string $provision_command_id
 * @property int $provision_attempts
 * @property ?string $ssh_sync_command_id
 * @property ?Carbon $provisioned_at
 * @property ?string $created_by
 * @property bool $sandboxes_enabled
 * @property bool $control_plane_host
 * @property Carbon $created_at
 */
#[UseFactory(ServerFactory::class)]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'servers_servers';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['install_command'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'status' => ServerStatus::class,
            'facts' => 'array',
            'sandboxes_enabled' => 'boolean',
            'control_plane_host' => 'boolean',
            'install_command' => Sealed::class,
            'provisioned_at' => 'datetime',
            'ssh_port' => 'integer',
        ];
    }

    /**
     * @return Attribute<Stack, Stack|array<string, mixed>>
     */
    protected function stack(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => Stack::fromArray($value ? (array) json_decode($value, true) : []),
            set: fn (Stack|array $value) => json_encode($value instanceof Stack ? $value->toArray() : $value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return HasMany<PhpVersion, $this>
     */
    public function phpVersions(): HasMany
    {
        return $this->hasMany(PhpVersion::class)->orderBy('version');
    }

    /**
     * @return HasOne<MachineInspection, $this>
     */
    public function machineInspection(): HasOne
    {
        return $this->hasOne(MachineInspection::class);
    }

    /**
     * @return BelongsToMany<SshKey, $this>
     */
    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'servers_server_ssh_keys')->withPivot('unix_user')->withTimestamps();
    }

    public function isCustom(): bool
    {
        return $this->provider === 'custom';
    }

    /**
     * PHP versions that can be installed on this server's OS (servers.php_versions_by_os narrows the offer).
     *
     * @return list<string>
     */
    public function installablePhpVersions(): array
    {
        $offered = array_values(array_map('strval', (array) config('servers.php_versions')));
        $byOs = (array) config('servers.php_versions_by_os', []);
        $os = strtolower(trim((string) $this->os));

        if ($os === '' || ! isset($byOs[$os])) {
            return $offered;
        }

        return array_values(array_intersect($offered, array_map('strval', (array) $byOs[$os])));
    }

    /** "Ubuntu 26.04" from the reported OS ("ubuntu 26.04"). */
    public function osLabel(): string
    {
        [$id, $version] = array_pad(explode(' ', (string) $this->os, 2), 2, '');

        return trim(ucfirst($id).' '.$version) ?: 'this server';
    }

    public function defaultPhp(): ?PhpVersion
    {
        return $this->phpVersions->firstWhere('is_default', true);
    }

    /**
     * PHP versions that should exist on the host (converged by provision.apply).
     *
     * @return list<string>
     */
    public function desiredPhpVersions(): array
    {
        return $this->phpVersions()
            ->whereIn('status', [PhpVersionStatus::Installing, PhpVersionStatus::Installed])
            ->pluck('version')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    /**
     * The port sshd listens on per the last machine check (its first, when several), else ssh_port.
     */
    public function sshPortInUse(): ?int
    {
        $ports = (string) ($this->machineInspection()->value('report')['ssh']['effective']['port'] ?? '');
        $first = (int) strtok($ports, ' ');

        return $first > 0 && $first <= 65535 ? $first : $this->ssh_port;
    }

    public function toData(): ServerData
    {
        $installed = $this->phpVersions->where('status', PhpVersionStatus::Installed);

        return new ServerData(
            id: $this->id,
            organizationId: $this->organization_id,
            name: $this->name,
            type: $this->type,
            status: $this->status,
            provider: $this->provider,
            ipv4: $this->ipv4,
            ipv6: $this->ipv6,
            privateIpv4: $this->private_ipv4,
            arch: $this->arch,
            phpVersions: $installed->pluck('version')->map(fn ($v) => (string) $v)->values()->all(),
            defaultPhpVersion: $installed->firstWhere('is_default', true)?->version,
            phpRuntime: $this->stack->phpRuntime,
            nodeVersion: $this->stack->node,
            // Every server runs Docker (provisioning installs it).
            docker: true,
            unixUser: (string) config('servers.unix_user', 'falak'),
            providerCredentialId: $this->provider_credential_id,
            region: $this->region,
            sandboxesEnabled: (bool) ($this->sandboxes_enabled ?? true),
            controlPlaneHost: (bool) $this->control_plane_host,
            reservedMemoryMb: $this->control_plane_host ? (int) config('servers.control_plane_reserve_mb', 1536) : 0,
            sshPort: $this->control_plane_host ? $this->sshPortInUse() : $this->ssh_port,
        );
    }
}
