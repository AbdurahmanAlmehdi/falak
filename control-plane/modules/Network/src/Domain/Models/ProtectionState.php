<?php

namespace Falak\Network\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-server protection: auto-ban settings and the convergence state of `net.protection.apply`.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property bool $auto_ban
 * @property int $failures
 * @property int $window_s
 * @property int $ban_s
 * @property int $revision
 * @property ?string $desired_hash
 * @property ?string $applied_hash
 * @property string $status idle (nothing to send) | pending | applying | applied | failed | unsupported
 * @property ?string $command_id
 * @property ?string $error
 * @property ?list<array{cidr: string, error: string}> $failed elements the agent refused
 * @property bool $set_counters
 * @property ?list<string> $released auto-bans lifted, until the agent confirmed
 * @property ?array<string, string> $release_times cidr => when it was released (ISO 8601, kept a week)
 * @property ?int $blocked_packets
 * @property ?Carbon $applied_at
 * @property ?Carbon $observed_at
 */
class ProtectionState extends Model
{
    protected $table = 'network_protection_states';

    protected $primaryKey = 'server_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['auto_ban' => false, 'failures' => 10, 'window_s' => 600, 'ban_s' => 3600, 'revision' => 0, 'status' => 'pending', 'set_counters' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_ban' => 'boolean',
            'failures' => 'integer',
            'window_s' => 'integer',
            'ban_s' => 'integer',
            'revision' => 'integer',
            'failed' => 'array',
            'released' => 'array',
            'release_times' => 'array',
            'set_counters' => 'boolean',
            'blocked_packets' => 'integer',
            'applied_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * Releases an auto-ban on the server: sent to the agent until it confirms, and remembered with its time so a report
     * built before the release never brings it back. Does not save.
     */
    public function release(string $cidr): void
    {
        $this->released = array_values(array_unique([...(array) $this->released, $cidr]));
        $times = array_filter((array) $this->release_times, fn ($at) => is_string($at) && Carbon::parse($at)->gt(now()->subWeek()));
        $times[$cidr] = now()->utc()->toIso8601ZuluString('microsecond');
        $this->release_times = $times;
    }

    /** Whether the ban was released after (or when) the report it comes in was built. */
    public function releasedSince(string $cidr, \DateTimeInterface $reportAt): bool
    {
        $at = ((array) $this->release_times)[$cidr] ?? null;

        return is_string($at) && Carbon::parse($at)->gte($reportAt);
    }
}
