<?php

namespace Falak\Uptime\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One check of a monitor (kept uptime.retention.checks_days).
 *
 * @property int $id
 * @property string $monitor_id
 * @property CarbonImmutable $checked_at
 * @property bool $ok
 * @property bool $down taken while the monitor was down (counts against uptime)
 * @property ?int $status_code
 * @property ?string $error_kind
 * @property ?string $error
 * @property ?string $ip
 * @property ?int $dns_ms
 * @property ?int $connect_ms
 * @property ?int $tls_ms
 * @property ?int $ttfb_ms
 * @property ?int $total_ms
 * @property int $attempts
 */
class Check extends Model
{
    public $timestamps = false;

    protected $table = 'uptime_checks';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'ok' => 'boolean',
            'down' => 'boolean',
            'status_code' => 'integer',
            'dns_ms' => 'integer',
            'connect_ms' => 'integer',
            'tls_ms' => 'integer',
            'ttfb_ms' => 'integer',
            'total_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
