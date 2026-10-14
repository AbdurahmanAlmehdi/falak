<?php

namespace Falak\Telemetry\Infrastructure;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\LogMetrics;
use Falak\Telemetry\Infrastructure\Metrics\PrometheusHttpBackend;

/**
 * Loki query_range for metric queries: the result is a Prometheus-style matrix, parsed like the metrics backend's.
 */
final class LokiLogMetrics implements LogMetrics
{
    public function __construct(private readonly HttpClient $http) {}

    public static function fromConfig(): self
    {
        $tenant = config('telemetry.loki.tenant');

        return new self(new HttpClient('Loki', config('telemetry.loki.url'), $tenant ? ['X-Scope-OrgID' => (string) $tenant] : []));
    }

    public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $stepSeconds): array
    {
        $body = $this->http->ensureSuccessful($this->http->get('/loki/api/v1/query_range', [
            'query' => $logql,
            'start' => LokiLogsQuery::nanos($start),
            'end' => LokiLogsQuery::nanos($end),
            'step' => max(1, $stepSeconds),
        ]))->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            throw new TelemetryQueryFailed('Loki', is_array($body) ? (string) ($body['error'] ?? 'unexpected response') : 'invalid JSON');
        }

        if (($body['data']['resultType'] ?? null) !== 'matrix') {
            throw new TelemetryQueryFailed('Loki', 'Only metric queries are supported.');
        }

        return PrometheusHttpBackend::parse((array) $body['data']);
    }
}
