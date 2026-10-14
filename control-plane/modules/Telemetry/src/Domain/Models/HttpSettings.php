<?php

namespace Falak\Telemetry\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A site's HTTP analytics thresholds (docs/plans/network/http.md); null columns fall back to config('telemetry.http').
 *
 * @property string $site_id
 * @property string $organization_id
 * @property ?int $slow_ms
 * @property ?int $slow_min_requests
 * @property ?int $bandwidth_alert_bytes
 */
class HttpSettings extends Model
{
    protected $table = 'telemetry_http_settings';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['slow_ms' => 'integer', 'slow_min_requests' => 'integer', 'bandwidth_alert_bytes' => 'integer'];
    }

    public static function for(string $organizationId, string $siteId): self
    {
        return self::query()->find(strtolower($siteId))
            ?? new self(['site_id' => strtolower($siteId), 'organization_id' => strtolower($organizationId)]);
    }

    public function slowMs(): int
    {
        return $this->slow_ms ?? (int) config('telemetry.http.slow_ms', 1000);
    }

    public function slowMinRequests(): int
    {
        return $this->slow_min_requests ?? (int) config('telemetry.http.slow_min_requests', 20);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'slow_ms' => $this->slowMs(),
            'slow_min_requests' => $this->slowMinRequests(),
            'bandwidth_alert_bytes' => $this->bandwidth_alert_bytes,
            'defaults' => [
                'slow_ms' => (int) config('telemetry.http.slow_ms', 1000),
                'slow_min_requests' => (int) config('telemetry.http.slow_min_requests', 20),
            ],
        ];
    }
}
