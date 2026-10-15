<?php

namespace Falak\Network\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Domain\Models\EbpfSetting;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Str;

/**
 * Sends net.ebpf.configure (docs/plans/network/ebpf.md): the server's eBPF setting. Only to agents with `net.ebpf` (the
 * whole command is gated); only while the agent has not applied this value, nothing is in flight, or a re-enable waits;
 * forced on an agent upgrade. `reenable` is sent only after the panel's explicit action: a re-send never restarts a
 * probe the agent stopped over its budget.
 */
final class ConfigureEbpf
{
    public const FEATURE = 'net.ebpf';

    /** A command in flight longer than this is taken as lost. */
    private const IN_FLIGHT_SECONDS = 1800;

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
    ) {}

    /** Whether the server's agent understands the command. */
    public function supported(string $serverId): bool
    {
        return $this->directory->forServer(strtolower($serverId))?->supports(self::FEATURE) ?? false;
    }

    /** Returns the command id, or null when nothing was sent. */
    public function __invoke(string $serverId, bool $force = false): ?string
    {
        $setting = EbpfSetting::query()->find(strtolower($serverId));
        $server = $this->servers->find(strtolower($serverId));

        if ($setting === null || $server === null || ! $server->isActive() || ! $this->supported($server->id)) {
            return null;
        }

        $hash = self::hash($setting);

        if (! $force) {
            $inFlight = $setting->command_id !== null && $setting->updated_at?->gt(now()->subSeconds(self::IN_FLIGHT_SECONDS));
            $settled = $setting->status === 'applied' && $setting->applied_hash === $hash && ! $setting->reenable;

            if ($inFlight || $settled) {
                return null;
            }
        }

        $payload = ['enabled' => $setting->enabled] + ($setting->full_dns_names ? ['full_dns_names' => true] : []) + ($setting->reenable ? ['reenable' => true] : []);

        try {
            $id = $this->agents->dispatch($server->id, 'net.ebpf.configure', $payload, 60, "net.ebpf.configure:{$server->id}:".Str::ulid())->id;
        } catch (AgentUnavailable) {
            return null;
        }

        $setting->forceFill(['status' => 'pending', 'command_id' => $id, 'pending_hash' => $hash, 'error' => null])->save();

        return $id;
    }

    /**
     * Sends what has not reached its agent (an agent that was offline, a failed or lost delivery, a re-enable): on
     * AgentCameOnline for that server, and every few minutes for all.
     */
    public function converge(?string $serverId = null): void
    {
        EbpfSetting::query()->when($serverId !== null, fn ($q) => $q->where('server_id', strtolower((string) $serverId)))
            ->pluck('server_id')->each(fn (string $id) => $this($id));
    }

    /** The command finished (only the latest one counts): what it carried is applied, or the error is kept. */
    public function settled(string $commandId, bool $succeeded, ?string $error = null): void
    {
        $setting = EbpfSetting::query()->where('command_id', $commandId)->first();

        if ($setting === null) {
            return;
        }

        $setting->forceFill($succeeded
            ? ['status' => 'applied', 'applied_hash' => $setting->pending_hash, 'applied_at' => now(), 'error' => null, 'command_id' => null, 'reenable' => false]
            : ['status' => 'failed', 'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'command_id' => null])->save();
    }

    private static function hash(EbpfSetting $setting): string
    {
        return hash('sha256', (string) json_encode([$setting->enabled, $setting->full_dns_names]));
    }
}
