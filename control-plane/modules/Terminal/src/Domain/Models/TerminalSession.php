<?php

namespace Falak\Terminal\Domain\Models;

use Falak\Terminal\Domain\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id also the agent-side session_id
 * @property string $organization_id
 * @property string $server_id
 * @property string $server_name
 * @property ?string $target_type server|site|database (null: rows from before consoles, servers)
 * @property ?string $target_id the site or database instance id
 * @property ?string $target_name the site or instance name at open time
 * @property ?string $container docker:<name> | compose:<service> (null: a host shell)
 * @property ?bool $relayed the agent was given a relay token
 * @property string $user_id owner
 * @property string $unix_user empty for container consoles (docker exec runs as the image's user)
 * @property SessionStatus $status
 * @property ?string $command_id terminal.open command
 * @property int $cols
 * @property int $rows
 * @property int $initial_cols terminal size at open (asciicast header)
 * @property int $initial_rows
 * @property int $idle_timeout_s
 * @property bool $shared
 * @property int $channel_epoch live channel generation (rotated on unshare)
 * @property ?Carbon $started_at agent clock of the first started/output event
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $closed_at
 * @property ?string $close_reason exited|closed|idle|timeout|failed|server_deleted
 * @property ?int $exit_code
 * @property ?string $error
 * @property int $recording_bytes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TerminalSession extends Model
{
    use HasUlids;

    public const TARGET_SERVER = 'server';

    public const TARGET_SITE = 'site';

    public const TARGET_DATABASE = 'database';

    protected $table = 'terminal_sessions';

    /** Millisecond precision: recording offsets are computed against started_at. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'cols' => 'integer',
            'rows' => 'integer',
            'initial_cols' => 'integer',
            'initial_rows' => 'integer',
            'idle_timeout_s' => 'integer',
            'shared' => 'boolean',
            'relayed' => 'boolean',
            'channel_epoch' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'exit_code' => 'integer',
            'recording_bytes' => 'integer',
        ];
    }

    /**
     * @return HasMany<TerminalFrame, $this>
     */
    public function frames(): HasMany
    {
        return $this->hasMany(TerminalFrame::class, 'session_id')->orderBy('offset_ms')->orderBy('id');
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function targetType(): string
    {
        return $this->target_type ?? self::TARGET_SERVER;
    }

    /** A relay session clients can still connect to. */
    public function isRelayed(): bool
    {
        return (bool) $this->relayed;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->user_id === $userId;
    }

    /** Milliseconds between the session start (agent clock) and $at, clamped to ≥ 0. */
    public function offsetFor(Carbon $at): int
    {
        $start = $this->started_at ?? $this->created_at;

        return max(0, (int) round(($at->getPreciseTimestamp(3) - $start->getPreciseTimestamp(3))));
    }
}
