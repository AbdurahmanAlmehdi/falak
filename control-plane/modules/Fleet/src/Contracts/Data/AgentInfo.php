<?php

namespace Falak\Fleet\Contracts\Data;

use DateTimeImmutable;
use Falak\Fleet\Contracts\AgentStatus;

final readonly class AgentInfo
{
    /**
     * @param  array<string, mixed>  $facts  latest facts (facts.schema.json)
     * @param  array<string, mixed>  $metrics  latest heartbeat summary: load, cpu_percent, memory_used_bytes, disk_used_bytes,
     *                                         disks (mount => [used, available, total] bytes), uptime_s, at
     * @param  list<string>  $runningCommands
     */
    public function __construct(
        public string $id,
        public string $serverId,
        public AgentStatus $status,
        public ?string $version,
        public ?string $hostname,
        public ?string $arch,
        public array $facts,
        public array $metrics,
        public DateTimeImmutable $enrolledAt,
        public ?DateTimeImmutable $lastHeartbeatAt,
        public ?DateTimeImmutable $certificateExpiresAt,
        public array $runningCommands = [],
        /**
         * SHA-256 of the client certificate the agent uses now (lowercase hex, the format of the edge's fingerprint
         * header): the newest one neither superseded, revoked nor expired; null when there is none
         */
        public ?string $certificateFingerprint = null,
    ) {}

    /** Whether the agent reported the protocol feature (facts `features`, e.g. "edge.access_log"). */
    public function supports(string $feature): bool
    {
        return in_array($feature, (array) ($this->facts['features'] ?? []), true);
    }

    public function isOnline(): bool
    {
        return $this->status === AgentStatus::Online;
    }
}
