<?php

namespace Falak\Limits\Contracts\Data;

/**
 * One service in a server's capacity view, with the limits enforced on it (memory in MB; null = unlimited).
 */
final readonly class CapacityItem
{
    /**
     * @param  string  $kind  site | compose_service | worker | daemon | database | function | sandbox
     * @param  string  $id  the service's id (a compose service: "<site id>:<service>")
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
        public ?int $memoryLimitMb,
        public ?int $memoryReservationMb,
        public ?float $cpus,
        public ?string $url = null,
    ) {}

    /**
     * @return array{kind: string, id: string, name: string, memory_limit_mb: ?int, memory_reservation_mb: ?int, cpus: ?float, url: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'name' => $this->name,
            'memory_limit_mb' => $this->memoryLimitMb,
            'memory_reservation_mb' => $this->memoryReservationMb,
            'cpus' => $this->cpus,
            'url' => $this->url,
        ];
    }
}
