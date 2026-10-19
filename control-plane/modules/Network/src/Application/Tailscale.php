<?php

namespace Falak\Network\Application;

use Falak\Edge\Contracts\TailnetSites;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Contracts\Tailnet;
use Falak\Network\Domain\Models\TailscaleNode;
use Falak\Network\Domain\Models\TailscaleSetting;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Servers on the organization's tailnet (docs/plans/TAILSCALE.md). A joined server advertises its own public IPs as
 * subnet routes, so tailnet devices that accept routes reach it through the tunnel (arriving on tailscale0 from the
 * tailnet ranges); everything else keeps using the public path.
 *
 * The auth key is only ever in the `net.tailscale.apply` payload: never in audit entries, errors or logs.
 */
final class Tailscale implements Tailnet
{
    public const FEATURE = 'net.tailscale';

    public const INTERFACE = 'tailscale0';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $directory,
        private readonly ServerDirectory $servers,
        private readonly Firewalls $firewalls,
        private readonly AuditLog $audit,
        private readonly TailnetSites $tailnetSites,
    ) {}

    public function saveKey(string $organizationId, string $authKey): TailscaleSetting
    {
        $setting = TailscaleSetting::query()->find($organizationId) ?? new TailscaleSetting(['organization_id' => $organizationId]);
        $replaced = $setting->exists;
        $setting->forceFill(['auth_key' => $authKey])->save();

        $this->audit->record($replaced ? 'network.tailscale_key_replaced' : 'network.tailscale_key_saved', 'organization', $organizationId, [], $organizationId);

        return $setting;
    }

    /** Refused while servers are on the tailnet: they would have no key to refresh with. */
    public function removeKey(string $organizationId): void
    {
        $joined = TailscaleNode::query()->where('organization_id', $organizationId)->count();

        if ($joined > 0) {
            throw ValidationException::withMessages(['auth_key' => "Take the {$joined} ".Str::plural('server', $joined).' off the tailnet first.']);
        }

        if (TailscaleSetting::query()->whereKey($organizationId)->delete() > 0) {
            $this->audit->record('network.tailscale_key_removed', 'organization', $organizationId, [], $organizationId);
        }
    }

    /** Join the server, or re-send its state (status, route approval): the agent is idempotent. */
    public function join(ServerData $server): TailscaleNode
    {
        $setting = TailscaleSetting::query()->find($server->organizationId)
            ?? throw ValidationException::withMessages(['server' => 'Save a Tailscale auth key first (Settings → Tailscale).']);

        if (! ($this->directory->forServer($server->id)?->supports(self::FEATURE) ?? false)) {
            throw ValidationException::withMessages(['server' => "The agent on {$server->name} does not support Tailscale yet: upgrade the agent first."]);
        }

        $routes = self::routes($server);

        if ($routes === []) {
            throw ValidationException::withMessages(['server' => "{$server->name} has no public IP to advertise."]);
        }

        $node = TailscaleNode::query()->find($server->id) ?? new TailscaleNode(['server_id' => $server->id, 'organization_id' => $server->organizationId]);
        $joining = ! $node->exists;
        $node->hostname = self::hostname($server);
        $arch = $server->arch === 'arm64' ? 'arm64' : 'amd64';
        $version = (string) config('network.tailscale.version');
        $payload = [
            'state' => 'present',
            'version' => $version,
            'url' => strtr((string) config('network.tailscale.url'), ['{version}' => $version, '{arch}' => $arch]),
            'sha256' => (string) config("network.tailscale.sha256.{$arch}"),
            'auth_key' => $setting->auth_key,
            'hostname' => $node->hostname,
            'advertise_routes' => $routes,
        ];

        try {
            $handle = $this->agents->dispatch($server->id, 'net.tailscale.apply', $payload, (int) config('network.tailscale.apply_timeout', 300),
                "net.tailscale.apply:{$server->id}:".Str::ulid());
            $node->forceFill(['status' => TailscaleNode::INSTALLING, 'error' => null, 'command_id' => $handle->id])->save();
        } catch (AgentUnavailable) {
            $node->forceFill(['status' => TailscaleNode::ERROR, 'error' => 'The server agent is not connected.', 'command_id' => null])->save();
        }

        if ($joining) {
            // tailscale0 and the direct-connection port open while the server has a node.
            $this->firewalls->converge($server->id);
            $this->audit->record('network.tailscale_joined', 'server', $server->id, ['hostname' => $node->hostname, 'routes' => $routes], $server->organizationId);
        }

        return $node;
    }

    /** Off the tailnet: logged out and removed from the server, its node forgotten. Refused while it serves a tailnet-only site. */
    public function leave(TailscaleNode $node): void
    {
        $sites = $this->tailnetSites->onServer($node->server_id);

        if ($sites !== []) {
            throw ValidationException::withMessages(['server' => 'Turn off "Tailscale only" on '.implode(', ', $sites).' first: nobody could reach '.(count($sites) === 1 ? 'it' : 'them').'.']);
        }

        try {
            $this->agents->dispatch($node->server_id, 'net.tailscale.apply', ['state' => 'absent'], (int) config('network.tailscale.apply_timeout', 300),
                "net.tailscale.remove:{$node->server_id}:".Str::ulid());
        } catch (AgentUnavailable) {
            // The server is offline: the device stays in the tailnet until removed in the admin console.
        }

        $node->delete();
        $this->firewalls->converge($node->server_id);
        $this->audit->record('network.tailscale_left', 'server', $node->server_id, ['hostname' => $node->hostname], $node->organization_id);
    }

    public function joined(string $serverId): bool
    {
        return TailscaleNode::query()->whereKey(strtolower($serverId))->where('status', TailscaleNode::ACTIVE)->where('routes_approved', true)->exists();
    }

    public function routesPending(string $serverId): bool
    {
        return TailscaleNode::query()->whereKey(strtolower($serverId))->where('status', TailscaleNode::ACTIVE)->where('routes_approved', false)->exists();
    }

    public function configured(string $organizationId): bool
    {
        return TailscaleSetting::query()->whereKey($organizationId)->exists();
    }

    public function controlPlaneAddresses(string $organizationId): array
    {
        // ponytail: the control-plane machine's own IPs only, so its checks get in; the ceiling is that the org's
        // workloads running on that machine reach its tailnet-only sites too (a dedicated checker source would fix it).
        $out = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            if ($server->controlPlaneHost) {
                array_push($out, ...array_filter([$server->ipv4, $server->ipv6]));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The server's own public IPs as host routes.
     *
     * @return list<string>
     */
    public static function routes(ServerData $server): array
    {
        return array_values(array_filter([$server->ipv4 ? "{$server->ipv4}/32" : null, $server->ipv6 ? "{$server->ipv6}/128" : null]));
    }

    /** falak-<server name>, cut to one DNS label. */
    public static function hostname(ServerData $server): string
    {
        $slug = Str::slug($server->name) ?: strtolower(substr($server->id, -6));

        return rtrim(substr("falak-{$slug}", 0, 63), '-');
    }
}
