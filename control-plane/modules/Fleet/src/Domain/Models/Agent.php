<?php

namespace Falak\Fleet\Domain\Models;

use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An enrolled falak-agent. Its id is the CN of its client certificates.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $server_id
 * @property AgentStatus $status
 * @property ?string $hostname
 * @property ?string $arch
 * @property ?string $agent_version
 * @property ?array<string, mixed> $facts
 * @property ?array<string, mixed> $metrics
 * @property Carbon $enrolled_at
 * @property ?Carbon $last_heartbeat_at
 * @property ?string $last_ip
 * @property ?Carbon $revoked_at
 * @property ?string $revocation_reason
 * @property ?string $session_id
 * @property ?Carbon $session_started_at
 */
#[UseFactory(AgentFactory::class)]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'fleet_agents';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'facts' => 'array',
            'metrics' => 'array',
            'enrolled_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'revoked_at' => 'datetime',
            'session_started_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<Command, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(Command::class);
    }

    public function isRevoked(): bool
    {
        return $this->status === AgentStatus::Revoked;
    }

    /**
     * Protocol features the agent reported in its facts (see facts.schema.json `features`).
     *
     * @return list<string>
     */
    public function features(): array
    {
        return array_values(array_filter((array) (($this->facts ?? [])['features'] ?? []), 'is_string'));
    }

    public function toInfo(): AgentInfo
    {
        $certificates = $this->certificates()->whereNull('revoked_at')->get(['fingerprint', 'not_before', 'not_after', 'superseded_at']);
        $expires = $certificates->max('not_after');
        $current = $certificates
            ->filter(fn (Certificate $certificate) => $certificate->superseded_at === null && $certificate->not_after->isFuture())
            ->sortByDesc('not_before')
            ->first();
        $metrics = $this->metrics ?? [];

        return new AgentInfo(
            id: $this->id,
            serverId: (string) $this->server_id,
            status: $this->status,
            version: $this->agent_version,
            hostname: $this->hostname,
            arch: $this->arch,
            facts: $this->facts ?? [],
            metrics: $metrics,
            enrolledAt: $this->enrolled_at->toDateTimeImmutable(),
            lastHeartbeatAt: $this->last_heartbeat_at?->toDateTimeImmutable(),
            certificateExpiresAt: $expires instanceof Carbon ? $expires->toDateTimeImmutable() : null,
            runningCommands: array_values(array_map('strval', (array) ($metrics['running_commands'] ?? []))),
            certificateFingerprint: $current !== null ? strtolower($current->fingerprint) : null,
        );
    }
}
