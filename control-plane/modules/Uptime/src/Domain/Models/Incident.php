<?php

namespace Falak\Uptime\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A confirmed outage of a monitor: opened when the state machine goes down, closed on recovery.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $monitor_id
 * @property CarbonImmutable $started_at the first failed check of the streak
 * @property CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $resolved_at
 * @property ?int $duration_seconds
 * @property ?string $cause_kind
 * @property ?string $cause
 * @property ?int $cause_status_code
 * @property ?string $last_error
 * @property ?string $resolution recovered | paused | deleted
 * @property ?string $issue_id
 * @property ?Monitor $monitor
 */
class Incident extends Model
{
    use HasUlids;

    protected $table = 'uptime_incidents';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'cause_status_code' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'monitor_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** Seconds down so far (open) or in total (closed). */
    public function seconds(?CarbonImmutable $now = null): int
    {
        return $this->duration_seconds ?? max(0, (int) $this->started_at->diffInSeconds($now ?? CarbonImmutable::now(), true));
    }
}
