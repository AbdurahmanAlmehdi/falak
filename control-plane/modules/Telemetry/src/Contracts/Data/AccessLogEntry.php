<?php

namespace Falak\Telemetry\Contracts\Data;

use DateTimeImmutable;

/**
 * One HTTP request served by the edge for a site (from the agent's access log records).
 */
final readonly class AccessLogEntry
{
    public function __construct(
        public string $timestampNs,
        public string $method,
        public string $path,
        public ?string $query,
        public int $status,
        public ?float $durationMs,
        public ?int $bytes,
        public ?int $requestBytes,
        public ?string $clientIp,
        public ?string $userAgent,
        public ?string $host,
        public ?string $serverId,
        public ?string $deploymentId,
        public ?string $releaseId,
        public ?string $requestId = null,
        public ?string $traceId = null,
    ) {}

    /**
     * Built from a Loki line of an access record (structured metadata http_request_method, url_path, …).
     */
    public static function fromLogLine(LogLine $line): self
    {
        $m = $line->metadata + $line->labels;
        $int = fn (string $key): ?int => isset($m[$key]) && is_numeric($m[$key]) ? (int) $m[$key] : null;
        $str = fn (string $key): ?string => isset($m[$key]) && $m[$key] !== '' ? (string) $m[$key] : null;

        return new self(
            timestampNs: $line->timestampNs,
            method: $str('http_request_method') ?? '',
            path: $str('url_path') ?? '',
            query: $str('url_query'),
            status: $int('http_response_status_code') ?? 0,
            durationMs: isset($m['http_server_duration_ms']) && is_numeric($m['http_server_duration_ms']) ? (float) $m['http_server_duration_ms'] : null,
            bytes: $int('http_response_body_size'),
            requestBytes: $int('http_request_body_size'),
            clientIp: $str('client_address'),
            userAgent: $str('user_agent_original'),
            host: $str('server_address'),
            serverId: isset($m['falak_server_id']) ? strtolower((string) $m['falak_server_id']) : null,
            deploymentId: isset($m['falak_deployment_id']) ? strtolower((string) $m['falak_deployment_id']) : null,
            releaseId: isset($m['falak_release_id']) ? strtolower((string) $m['falak_release_id']) : null,
            requestId: $str('http_request_id'),
            traceId: $line->traceId(),
        );
    }

    public function at(): DateTimeImmutable
    {
        return (new LogLine($this->timestampNs, '', []))->at();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->timestampNs,
            'at' => $this->at()->format('Y-m-d\TH:i:s.uP'),
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'bytes' => $this->bytes,
            'request_bytes' => $this->requestBytes,
            'client_ip' => $this->clientIp,
            'user_agent' => $this->userAgent,
            'host' => $this->host,
            'server_id' => $this->serverId,
            'deployment_id' => $this->deploymentId,
            'release_id' => $this->releaseId,
            'request_id' => $this->requestId,
            'trace_id' => $this->traceId,
        ];
    }
}
