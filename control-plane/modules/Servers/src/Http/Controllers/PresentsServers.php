<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Contracts\Data\AgentVersionInfo;
use Falak\Providers\Contracts\ProviderType;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;

trait PresentsServers
{
    /**
     * @return array<string, mixed>
     */
    protected function summary(Server $server, ?AgentInfo $agent, ?AgentVersionInfo $version = null): array
    {
        $metrics = $agent?->metrics ?? [];

        return [
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type->value,
            'type_label' => $server->type->label(),
            'status' => $server->status->value,
            'status_message' => $server->status_message,
            'provider' => $server->provider,
            'provider_label' => ProviderType::tryFrom($server->provider)?->label() ?? $server->provider,
            'region' => $server->region,
            'ipv4' => $server->ipv4,
            'private_ipv4' => $server->private_ipv4,
            'ssh_port' => $server->ssh_port,
            'php' => $server->phpVersions->firstWhere('is_default', true)?->version,
            'agent' => $agent ? [
                'status' => $agent->status->value,
                'last_heartbeat_at' => $agent->lastHeartbeatAt?->format(DATE_ATOM),
                'version' => $agent->version,
                // The build this control plane ships; update_available when the server runs an older one.
                'available_version' => $version?->availableVersion,
                'update_available' => $version->updateAvailable ?? false,
                'upgrade' => $version?->upgrade?->toArray(),
            ] : null,
            'load1' => isset($metrics['load'][0]) ? (float) $metrics['load'][0] : null,
            'cpu_percent' => is_numeric($metrics['cpu_percent'] ?? null) ? round((float) $metrics['cpu_percent'], 1) : null,
            'memory_percent' => $this->percent($metrics['memory_used_bytes'] ?? null, $server->memory_bytes),
            'disk_percent' => $this->percent($metrics['disk_used_bytes'] ?? null, $server->disk_bytes),
            'created_at' => $server->created_at->toIso8601String(),
            'control_plane_host' => (bool) $server->control_plane_host,
            'reserved_memory_mb' => $server->control_plane_host ? (int) config('servers.control_plane_reserve_mb', 1536) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function phpVersion(PhpVersion $php): array
    {
        return [
            'id' => $php->id,
            'version' => $php->version,
            'status' => $php->status->value,
            'status_message' => $php->status_message,
            'is_default' => $php->is_default,
            'ini' => (object) $php->ini,
            'fpm' => $php->fpm,
            'command_id' => $php->command_id,
        ];
    }

    /**
     * The latest machine check: its state and the decisions for the server's current stack (what Provision would do).
     *
     * @return array<string, mixed>
     */
    protected function machineCheck(Server $server, MachineChecks $checks, bool $withReport = false): array
    {
        $inspection = $server->machineInspection()->first();
        $supported = $checks->supported($server);
        $check = $inspection?->report !== null ? $checks->decide($server, $inspection->report) : $inspection?->check();

        return [
            'supported' => $supported,
            'status' => $inspection?->status,
            'purpose' => $inspection?->purpose,
            'checked_at' => $inspection?->checked_at?->toIso8601String(),
            'agent_version' => $inspection?->agent_version,
            'error' => $inspection?->error,
            'command_id' => $inspection?->command_id,
            'blocking' => $check?->blocking() ?? false,
            'summary' => $check?->blocking() ? $check->summary() : null,
            'components' => $check?->toArray() ?? [],
            ...($withReport ? ['report' => $inspection?->report] : []),
        ];
    }

    private function percent(mixed $used, ?int $total): ?float
    {
        return is_numeric($used) && $total ? round(((float) $used / $total) * 100, 1) : null;
    }
}
