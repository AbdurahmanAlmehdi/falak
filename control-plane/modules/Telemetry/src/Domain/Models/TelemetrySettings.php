<?php

namespace Falak\Telemetry\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-organization telemetry overrides (null columns fall back to config('telemetry')).
 *
 * @property string $organization_id
 * @property ?string $otlp_endpoint
 * @property ?string $otlp_token
 * @property ?string $environment
 * @property ?float $traces_ratio
 * @property ?int $metrics_interval_s
 * @property bool $request_id_linking
 * @property Carbon $updated_at
 */
class TelemetrySettings extends Model
{
    protected $table = 'telemetry_settings';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['otlp_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'otlp_token' => Sealed::class,
            'traces_ratio' => 'float',
            'metrics_interval_s' => 'integer',
            'request_id_linking' => 'boolean',
        ];
    }

    public static function for(string $organizationId): self
    {
        return self::query()->find($organizationId) ?? new self(['organization_id' => $organizationId]);
    }

    public function endpoint(): string
    {
        return $this->otlp_endpoint ?: (string) config('telemetry.otlp.endpoint');
    }

    public function token(): ?string
    {
        return $this->otlp_token ?: (config('telemetry.otlp.token') ?: null);
    }

    public function environmentName(): string
    {
        return $this->environment ?: (string) config('telemetry.defaults.environment', 'production');
    }

    public function tracesRatio(): float
    {
        return $this->traces_ratio ?? (float) config('telemetry.defaults.traces_ratio', 1.0);
    }

    public function metricsInterval(): int
    {
        return $this->metrics_interval_s ?? (int) config('telemetry.defaults.metrics_interval_s', 15);
    }
}
