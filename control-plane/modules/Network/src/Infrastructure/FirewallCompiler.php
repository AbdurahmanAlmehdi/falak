<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Contracts\WebOriginPolicy;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Compiles a server's firewall rules (plus rules implied by its private networks) into the FULL
 * `net.firewall.apply` desired state.
 *
 * Order: private-network rules first (so user deny rules never cut the mesh), then deny rules,
 * then allow rules, each by position. Everything else is dropped (input_policy drop); the SSH
 * port is always accepted by the agent to avoid lock-out.
 */
final class FirewallCompiler
{
    private const WEB_PORTS = ['80', '443'];

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly WebOriginPolicy $origins,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compile(string $serverId): array
    {
        $rules = FirewallRule::query()
            ->where('server_id', $serverId)
            ->orderByRaw('CASE WHEN action = ? THEN 0 ELSE 1 END', [RuleAction::Deny->value])
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // The control plane's machine keeps its own SSH (the machine check adopts it): accept the port it really uses,
        // never lock its operator out.
        $server = $this->servers->find($serverId);
        $sshPort = $server?->controlPlaneHost && $server->sshPort !== null ? $server->sshPort : (int) config('network.ssh_port', 22);

        return [
            'input_policy' => 'drop',
            'ssh_port' => $sshPort,
            'allow_icmp' => true,
            // Rule counters (feature net.protection; stripped for older agents by PayloadCompatibility).
            'counters' => true,
            'rules' => [...$this->networkRules($serverId), ...$this->webOrigins($serverId, $rules->map(fn (FirewallRule $rule) => $this->rule($rule))->all())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(FirewallRule $rule): array
    {
        return array_filter([
            'id' => $rule->id,
            'action' => $rule->action->verdict(),
            'protocol' => $rule->protocol->value,
            'ports' => $rule->port !== null ? [$rule->port] : null,
            'sources' => $rule->source !== null ? [$rule->source] : null,
            'comment' => mb_substr($rule->name, 0, 120),
        ], fn ($value) => $value !== null);
    }

    /**
     * The server's {@see WebOriginPolicy} on top of its rules. Explicit web rules come first, so they hold whatever the
     * server's own rules say (all-port rules and port ranges included): accept TCP 80 / 443 from the allowed sources
     * (only), then drop TCP 80 / 443 from anywhere. Accept rules for exactly the web ports are then redundant and left
     * out; everything else is untouched.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function webOrigins(string $serverId, array $rules): array
    {
        $policy = $this->origins->for($serverId);

        if ($policy === null) {
            return $rules;
        }

        $explicit = [];

        if ($policy['mode'] === 'only') {
            $explicit[] = ['id' => 'web-origin-allow', 'action' => 'accept', 'protocol' => 'tcp', 'ports' => self::WEB_PORTS, 'sources' => $policy['sources'], 'comment' => 'Web ports: Cloudflare only'];
        }

        $explicit[] = ['id' => 'web-origin-drop', 'action' => 'drop', 'protocol' => 'tcp', 'ports' => self::WEB_PORTS, 'comment' => $policy['mode'] === 'only' ? 'Web ports: nobody else' : 'Web ports closed (Cloudflare Tunnel)'];

        $rest = array_values(array_filter($rules, fn (array $rule) => ! ($rule['action'] === 'accept'
            && in_array($rule['protocol'], ['tcp', 'any'], true)
            && ($rule['ports'] ?? []) !== []
            && array_diff((array) $rule['ports'], self::WEB_PORTS) === [])));

        return [...$explicit, ...$rest];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function networkRules(string $serverId): array
    {
        $memberships = PrivateNetworkMember::query()
            ->with('network')
            ->where('server_id', $serverId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rules = [];

        foreach ($memberships as $membership) {
            $network = $membership->network;

            $peerIps = PrivateNetworkMember::query()
                ->where('network_id', $network->id)
                ->where('server_id', '!=', $serverId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('server_id')
                ->map(fn (string $id) => $this->servers->find($id)?->ipv4)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($peerIps !== []) {
                $rules[] = [
                    'id' => "wg-{$network->id}-handshake",
                    'action' => 'accept',
                    'protocol' => 'udp',
                    'ports' => [(string) $network->listen_port],
                    'sources' => $peerIps,
                    'comment' => mb_substr("WireGuard peers of {$network->name}", 0, 120),
                ];
            }

            $rules[] = [
                'id' => "wg-{$network->id}-interface",
                'action' => 'accept',
                'protocol' => 'any',
                'interface' => $network->interface,
                'comment' => mb_substr("Private network {$network->name}", 0, 120),
            ];
        }

        return $rules;
    }
}
