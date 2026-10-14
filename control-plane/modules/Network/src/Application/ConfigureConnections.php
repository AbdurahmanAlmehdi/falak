<?php

namespace Falak\Network\Application;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Domain\Models\NetworkObservation;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Str;

/**
 * Sends net.connections.configure (docs/plans/network/connections.md): what only the control plane knows, whom the
 * agent probes (its private-network peers, on a closed port of their private address: the peer's kernel answers with a
 * RST, so no service sees a connection; database ports are never probed, MySQL / MariaDB count a connection closed
 * before its handshake as an aborted connect) and what to call the addresses it sees (server:<id>, db:<id>). Only to
 * agents with `net.connections` (the whole command is gated), and only when the payload differs from the last one the
 * agent applied (`config_hash`, set when the command succeeds) or is waiting for (`config_pending_hash`), unless forced.
 */
final class ConfigureConnections
{
    public const FEATURE = 'net.connections';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
        private readonly DatabaseDirectory $databases,
    ) {}

    /** Returns the command id, or null when nothing was sent. */
    public function __invoke(string $serverId, bool $force = false): ?string
    {
        $server = $this->servers->find(strtolower($serverId));

        if ($server === null || ! $server->isActive() || ! ($this->directory->forServer($server->id)?->supports(self::FEATURE) ?? false)) {
            return null;
        }

        $payload = $this->payload($server->id, $server->organizationId);
        $hash = hash('sha256', (string) json_encode($payload));
        $observation = NetworkObservation::query()->firstOrNew(['server_id' => $server->id], ['organization_id' => $server->organizationId]);

        if (! $force && in_array($hash, [$observation->config_hash, $observation->config_pending_hash], true)) {
            return null;
        }

        try {
            $id = $this->agents->dispatch($server->id, 'net.connections.configure', $payload, 60, "net.connections.configure:{$server->id}:".Str::ulid())->id;
        } catch (AgentUnavailable) {
            return null;
        }

        $observation->forceFill(['config_pending_hash' => $hash, 'config_command_id' => $id])->save();

        return $id;
    }

    /** The command finished: what it carried is applied (only the latest command counts). */
    public function applied(string $commandId, bool $succeeded): void
    {
        $observation = NetworkObservation::query()->where('config_command_id', $commandId)->first();

        if ($observation === null) {
            return;
        }

        $observation->forceFill([
            'config_hash' => $succeeded ? $observation->config_pending_hash : $observation->config_hash,
            'config_pending_hash' => null,
            'config_command_id' => null,
        ])->save();
    }

    /**
     * @return array{probes: list<array<string, mixed>>, names: list<array<string, mixed>>, wireguard: list<array<string, mixed>>, long_lived_after_s: int}
     */
    public function payload(string $serverId, string $organizationId): array
    {
        $probes = $names = $wireguard = [];
        $networks = PrivateNetworkMember::query()->where('server_id', $serverId)->pluck('network_id');
        $peers = PrivateNetworkMember::query()->whereIn('network_id', $networks)->where('server_id', '!=', $serverId)
            ->orderBy('created_at')->orderBy('id')->get();

        foreach ($peers as $member) {
            $name = "server:{$member->server_id}";
            $peer = $this->servers->find($member->server_id);
            $probes[$name] ??= ['name' => $name, 'kind' => 'server', 'host' => $member->address, 'port' => (int) config('network.connections.probe_port', 1)];

            foreach (array_filter([$member->address, $peer?->ipv4, $peer?->privateIpv4, $peer?->ipv6]) as $address) {
                $names["{$address}|0"] = ['address' => $address, 'port' => 0, 'name' => $name];
            }

            if ($member->public_key !== '') {
                $wireguard[$member->public_key] = ['public_key' => $member->public_key, 'name' => $name];
            }
        }

        foreach ($this->databases->instancesForOrganization($organizationId) as $instance) {
            if ($instance->hostPort === null || ! $instance->isRunning()) {
                continue;
            }

            $name = "db:{$instance->id}";
            $port = $instance->hostPort;

            // The agent matches loopback names in the host's namespace only.
            if ($instance->serverId === $serverId) {
                $names["127.0.0.1|{$port}"] = ['address' => '127.0.0.1', 'port' => $port, 'name' => $name];
            }

            foreach ($instance->publishedAddresses as $address) {
                $names["{$address}|{$port}"] = ['address' => $address, 'port' => $port, 'name' => $name];
            }
        }

        return [
            'probes' => array_slice(array_values($probes), 0, 64),
            'names' => array_slice(array_values($names), 0, 512),
            'wireguard' => array_slice(array_values($wireguard), 0, 128),
            'long_lived_after_s' => (int) config('network.connections.long_lived_after_s', 60),
        ];
    }
}
