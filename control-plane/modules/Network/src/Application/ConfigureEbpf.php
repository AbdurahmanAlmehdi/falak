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
 * whole command is gated) and only when the agent has not applied this value yet, unless forced (an agent upgrade).
 */
final class ConfigureEbpf
{
    public const FEATURE = 'net.ebpf';

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

        if (! $force && $setting->status === 'applied' && $setting->applied_enabled === $setting->enabled) {
            return null;
        }

        try {
            $id = $this->agents->dispatch($server->id, 'net.ebpf.configure', ['enabled' => $setting->enabled], 60,
                "net.ebpf.configure:{$server->id}:".Str::ulid())->id;
        } catch (AgentUnavailable) {
            return null;
        }

        $setting->forceFill(['status' => 'pending', 'command_id' => $id, 'error' => null])->save();

        return $id;
    }

    /**
     * The command finished (only the latest one counts): what it carried is applied, or the error is kept.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function settled(string $commandId, bool $succeeded, ?string $error = null, ?array $result = null): void
    {
        $setting = EbpfSetting::query()->where('command_id', $commandId)->first();

        if ($setting === null) {
            return;
        }

        $setting->forceFill($succeeded
            ? ['status' => 'applied', 'applied_enabled' => (bool) ($result['enabled'] ?? $setting->enabled), 'applied_at' => now(), 'error' => null, 'command_id' => null]
            : ['status' => 'failed', 'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'command_id' => null])->save();
    }
}
