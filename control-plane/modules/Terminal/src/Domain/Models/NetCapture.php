<?php

namespace Falak\Terminal\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One packet capture (docs/plans/network/capture.md): a net.capture command on a server or in a container, its progress,
 * and the encrypted pcap the agent uploaded (downloadable until expires_at).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $server_id
 * @property string $server_name
 * @property string $target_type server|site|database
 * @property ?string $target_id
 * @property ?string $target_name
 * @property ?string $container docker:<name> | compose:<service> (display)
 * @property ?string $interface default|wireguard|docker (host captures)
 * @property ?array{host?: string, port?: int, protocol?: string, direction?: string} $filter
 * @property int $snaplen
 * @property int $duration_s
 * @property int $max_bytes
 * @property ?string $command_id
 * @property string $status queued|capturing|uploading|ready|failed|cancelled|expired
 * @property ?Carbon $cancel_requested_at
 * @property int $bytes_captured
 * @property int $packets_captured
 * @property int $bytes_received
 * @property ?int $size_bytes
 * @property ?string $sha256
 * @property ?string $last_chunk_sha256
 * @property string $data_key the capture's data key, sealed under the organization's key
 * @property ?array<string, mixed> $result net.capture's result
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class NetCapture extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const CAPTURING = 'capturing';

    public const UPLOADING = 'uploading';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** In progress: counted against the organization's limit, and the only states that take progress and chunks. */
    public const ACTIVE = [self::QUEUED, self::CAPTURING, self::UPLOADING];

    protected $table = 'terminal_net_captures';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['data_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array', 'result' => 'array', 'snaplen' => 'integer', 'duration_s' => 'integer', 'max_bytes' => 'integer',
            'bytes_captured' => 'integer', 'packets_captured' => 'integer', 'bytes_received' => 'integer', 'size_bytes' => 'integer',
            'cancel_requested_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanel(): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_name' => $this->target_name,
            'server_id' => $this->server_id,
            'server_name' => $this->server_name,
            'container' => $this->container,
            'interface' => $this->interface,
            'filter' => $this->filter,
            'snaplen' => $this->snaplen,
            'duration_s' => $this->duration_s,
            'max_bytes' => $this->max_bytes,
            'command_id' => $this->command_id,
            'status' => $this->status,
            'cancel_requested' => $this->cancel_requested_at !== null,
            'bytes_captured' => $this->bytes_captured,
            'packets_captured' => $this->packets_captured,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'download_url' => $this->status === self::READY ? "/net-captures/{$this->id}/download" : null,
        ];
    }
}
