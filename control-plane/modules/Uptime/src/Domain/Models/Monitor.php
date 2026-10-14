<?php

namespace Falak\Uptime\Domain\Models;

use Carbon\CarbonImmutable;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\MonitorState;
use Falak\Uptime\Domain\Probing\ProbeTarget;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property MonitorSource $source
 * @property ?string $auto_key
 * @property MonitorType $type
 * @property string $name
 * @property ?string $url
 * @property ?string $host
 * @property ?int $port
 * @property string $expected_status
 * @property ?string $keyword
 * @property bool $keyword_absent
 * @property int $max_redirects
 * @property int $timeout_seconds
 * @property int $interval_seconds
 * @property ?int $slow_ms
 * @property MonitorStatus $status
 * @property bool $armed
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property ?CarbonImmutable $paused_at
 * @property ?CarbonImmutable $next_check_at
 * @property ?CarbonImmutable $last_checked_at
 * @property ?CarbonImmutable $status_changed_at
 * @property ?int $last_total_ms
 * @property ?int $last_status_code
 * @property ?string $last_error_kind
 * @property ?string $last_error
 * @property ?array<string, mixed> $tls
 * @property ?CarbonImmutable $tls_checked_at
 * @property ?CarbonImmutable $flapping_until
 * @property CarbonImmutable $created_at
 */
class Monitor extends Model
{
    use HasUlids;

    protected $table = 'uptime_monitors';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => MonitorSource::class,
            'type' => MonitorType::class,
            'status' => MonitorStatus::class,
            'keyword_absent' => 'boolean',
            'armed' => 'boolean',
            'port' => 'integer',
            'max_redirects' => 'integer',
            'timeout_seconds' => 'integer',
            'interval_seconds' => 'integer',
            'slow_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_total_ms' => 'integer',
            'last_status_code' => 'integer',
            'tls' => 'array',
            'paused_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'tls_checked_at' => 'immutable_datetime',
            'flapping_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Check, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class, 'monitor_id');
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'monitor_id');
    }

    public function isAutomatic(): bool
    {
        return $this->source === MonitorSource::Auto;
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function state(): MonitorState
    {
        return new MonitorState($this->status, $this->armed, $this->consecutive_failures, $this->consecutive_successes);
    }

    public function slowThreshold(): int
    {
        return $this->slow_ms ?? (int) config('uptime.slow_ms', 3000);
    }

    /** What the check targets, for people: the URL, or host:port. */
    public function target(): string
    {
        return $this->type === MonitorType::Http ? (string) $this->url : (str_contains((string) $this->host, ':') ? "[{$this->host}]" : $this->host).":{$this->port}";
    }

    /** The host whose certificate the monitor sees (https URLs and tls monitors). */
    public function tlsHost(): ?string
    {
        if ($this->type === MonitorType::Tls) {
            return $this->host;
        }

        if ($this->type === MonitorType::Http && str_starts_with(strtolower((string) $this->url), 'https://')) {
            return parse_url((string) $this->url, PHP_URL_HOST) ?: null;
        }

        return null;
    }

    public function probeTarget(bool $inspectTls): ProbeTarget
    {
        return new ProbeTarget(
            type: $this->type,
            url: $this->url,
            host: $this->host,
            port: $this->port,
            timeoutSeconds: $this->timeout_seconds,
            maxRedirects: $this->max_redirects,
            expectedStatus: $this->expected_status,
            keyword: $this->keyword,
            keywordAbsent: $this->keyword_absent,
            bodyBytes: (int) config('uptime.body_bytes', 65536),
            inspectTls: $inspectTls,
        );
    }

    /** Whether this check should inspect the certificate chain (tls: always; https: once per tls_check_minutes). */
    public function tlsDue(CarbonImmutable $now): bool
    {
        if ($this->type === MonitorType::Tls) {
            return true;
        }

        return $this->tlsHost() !== null
            && ($this->tls_checked_at === null || $this->tls_checked_at->lessThanOrEqualTo($now->subMinutes((int) config('uptime.tls_check_minutes', 60))));
    }
}
