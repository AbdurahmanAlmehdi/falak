<?php

namespace Falak\Telemetry\Contracts;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * LogQL metric queries (`count_over_time`, `quantile_over_time` …) over a time range, as series. Callers must include
 * an organization matcher (`falak_org_id="…"`) in every stream selector — build them with {@see PromQl::label()}.
 */
interface LogMetrics
{
    /**
     * @return list<MetricSeries>
     *
     * @throws TelemetryUnavailable when no Loki URL is configured or Loki is unreachable
     * @throws TelemetryQueryFailed when Loki rejects the query
     */
    public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $stepSeconds): array;
}
