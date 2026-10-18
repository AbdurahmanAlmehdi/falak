<?php

namespace Falak\Limits\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Limits\Contracts\Data\CapacityItem;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\Data\ServerData;

/**
 * A server's capacity: what every service on it may use (memory limits and reservations, CPU limits) against what
 * the server has (the agent's facts). Limits summing past the RAM is overcommitment — fine while services stay under
 * their limits, an OOM risk when they don't; reservations past the RAM can't all be honoured at once.
 *
 * @phpstan-type Capacity array{server: array{id: string, name: string, memory_mb: ?int, cpus: ?int, total_memory_mb: ?int, reserved_memory_mb: int}, totals: array{memory_limit_mb: int, memory_reservation_mb: int, cpus: float}, unlimited: array{memory: int, cpus: int}, overcommitted: array{memory: bool, reservations: bool, cpus: bool}, items: list<array<string, mixed>>, warnings: list<string>}
 */
final class CapacityReport
{
    public function __construct(
        private readonly CapacitySources $sources,
        private readonly AgentDirectory $agents,
    ) {}

    /**
     * @return Capacity
     */
    public function for(ServerData $server): array
    {
        /** @var list<CapacityItem> $items */
        $items = [];

        foreach ($this->sources->all() as $source) {
            array_push($items, ...$source->onServer($server->organizationId, $server->id));
        }

        usort($items, fn (CapacityItem $a, CapacityItem $b) => [$b->memoryLimitMb ?? -1, $a->name] <=> [$a->memoryLimitMb ?? -1, $b->name]);

        $facts = $this->agents->forServer($server->id)?->facts ?? [];
        $totalMb = is_numeric($facts['memory_bytes'] ?? null) ? intdiv((int) $facts['memory_bytes'], 1024 ** 2) : null;
        // The control plane's machine keeps a share for the control plane: only the rest is allocatable.
        $memoryMb = $totalMb !== null ? max(0, $totalMb - $server->reservedMemoryMb) : null;
        $cores = is_numeric($facts['cpus'] ?? null) ? (int) $facts['cpus'] : null;

        $limit = array_sum(array_map(fn (CapacityItem $item) => $item->memoryLimitMb ?? 0, $items));
        $reserved = array_sum(array_map(fn (CapacityItem $item) => $item->memoryReservationMb ?? 0, $items));
        $cpus = round(array_sum(array_map(fn (CapacityItem $item) => $item->cpus ?? 0.0, $items)), 2);
        $unlimitedMemory = count(array_filter($items, fn (CapacityItem $item) => $item->memoryLimitMb === null));
        $unlimitedCpus = count(array_filter($items, fn (CapacityItem $item) => $item->cpus === null));

        $over = [
            'memory' => $memoryMb !== null && $limit > $memoryMb,
            'reservations' => $memoryMb !== null && $reserved > $memoryMb,
            'cpus' => $cores !== null && $cpus > $cores,
        ];

        $warnings = array_values(array_filter([
            $over['reservations'] ? 'Memory reservations ('.ResourceLimits::memoryLabel($reserved).') exceed the server\'s memory ('.ResourceLimits::memoryLabel((int) $memoryMb).'): they can\'t all be honoured under pressure.' : null,
            $over['memory'] ? 'Memory limits add up to '.ResourceLimits::memoryLabel($limit).', more than the server\'s '.ResourceLimits::memoryLabel((int) $memoryMb).': if services use what they may, the host runs out of memory.' : null,
            $over['cpus'] ? "CPU limits add up to {$cpus}, more than the server's {$cores} cores: busy services will slow each other down." : null,
            $unlimitedMemory > 0 && $items !== [] ? $unlimitedMemory.' '.($unlimitedMemory === 1 ? 'service has' : 'services have').' no memory limit and can use all of the server\'s memory.' : null,
        ]));

        return [
            'server' => ['id' => $server->id, 'name' => $server->name, 'memory_mb' => $memoryMb, 'cpus' => $cores, 'total_memory_mb' => $totalMb, 'reserved_memory_mb' => $server->reservedMemoryMb],
            'totals' => ['memory_limit_mb' => $limit, 'memory_reservation_mb' => $reserved, 'cpus' => $cpus],
            'unlimited' => ['memory' => $unlimitedMemory, 'cpus' => $unlimitedCpus],
            'overcommitted' => $over,
            'items' => array_map(fn (CapacityItem $item) => $item->toArray(), $items),
            'warnings' => $warnings,
        ];
    }
}
