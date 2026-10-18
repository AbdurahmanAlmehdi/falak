<?php

namespace Falak\Servers\Contracts\Data;

use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;

final readonly class ServerData
{
    /**
     * @param  list<string>  $phpVersions  installed PHP versions
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ServerType $type,
        public ServerStatus $status,
        public string $provider,
        public ?string $ipv4,
        public ?string $ipv6,
        public ?string $privateIpv4,
        public ?string $arch,
        public array $phpVersions,
        public ?string $defaultPhpVersion,
        public ?string $phpRuntime,
        public ?string $nodeVersion,
        /** Always true: every server runs Docker */
        public bool $docker,
        public string $unixUser,
        // Provider servers: the credential (account) Falak created it with, and its region.
        public ?string $providerCredentialId = null,
        public ?string $region = null,
        /** Sandboxes may run here (a server can opt out). */
        public bool $sandboxesEnabled = true,
        /** The control plane's own machine ("This machine"). */
        public bool $controlPlaneHost = false,
        /** Memory kept for the control plane on this machine (FALAK_CP_RESERVE_MB): not allocatable to workloads. */
        public int $reservedMemoryMb = 0,
    ) {}

    public function isActive(): bool
    {
        return $this->status === ServerStatus::Active;
    }
}
