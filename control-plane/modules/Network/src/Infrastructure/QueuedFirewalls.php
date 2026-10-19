<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Application\Actions\DeleteFirewallRule;
use Falak\Network\Application\Actions\SaveFirewallRule;
use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerSshKeys;
use InvalidArgumentException;

final class QueuedFirewalls implements Firewalls
{
    public function __construct(
        private readonly ApplyFirewall $apply,
        private readonly FirewallCompiler $compiler,
        private readonly ServerDirectory $servers,
        private readonly ServerSshKeys $keys,
    ) {}

    public function converge(string $serverId): void
    {
        ($this->apply)(strtolower($serverId));
    }

    public function reapply(string $serverId): void
    {
        ($this->apply)(strtolower($serverId), force: true);
    }

    public function expectedPorts(string $serverId): array
    {
        $payload = $this->compiler->compile(strtolower($serverId));
        $ports = ["tcp/{$payload['ssh_port']}"];

        foreach ($payload['rules'] as $rule) {
            if ($rule['action'] !== 'accept' || isset($rule['interface'])) {
                continue;
            }

            foreach ($rule['ports'] ?? ['*'] as $port) {
                $ports[] = "{$rule['protocol']}/{$port}";
            }
        }

        return array_values(array_unique($ports));
    }

    public function deniedPorts(string $serverId): array
    {
        $ports = [];

        $rules = FirewallRule::query()
            ->where('server_id', strtolower($serverId))
            ->where('action', RuleAction::Deny->value)
            ->whereNull('source')
            ->whereNotNull('port')
            ->get();

        foreach ($rules as $rule) {
            foreach ($rule->protocol === RuleProtocol::Any ? ['tcp', 'udp'] : [$rule->protocol->value] as $protocol) {
                $ports[] = "{$protocol}/{$rule->port}";
            }
        }

        return array_values(array_unique($ports));
    }

    public function denyPort(string $serverId, string $protocol, int $port, string $name): string
    {
        $server = $this->servers->find(strtolower($serverId)) ?? throw new InvalidArgumentException("Unknown server {$serverId}.");
        $proto = RuleProtocol::from($protocol);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port {$port}.");
        }

        if ($server->controlPlaneHost && SaveFirewallRule::coversWebPorts((string) $port)) {
            throw new InvalidArgumentException(SaveFirewallRule::CONTROL_PLANE_DENY);
        }

        if ($reason = $this->protectedPort($server->id, $proto, $port)) {
            throw new InvalidArgumentException("Port {$proto->value}/{$port} is not closed: {$reason}");
        }

        $existing = FirewallRule::query()
            ->where('server_id', $server->id)
            ->where('action', RuleAction::Deny->value)
            ->where('protocol', $proto->value)
            ->where('port', (string) $port)
            ->whereNull('source')
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        return app(SaveFirewallRule::class)($server, ['name' => mb_substr($name, 0, 120), 'action' => RuleAction::Deny->value, 'protocol' => $proto->value, 'port' => (string) $port])->id;
    }

    /**
     * Why a port must never get a deny rule from here (a deny wins over every accept): SSH, the web ports the edge
     * serves, private networks' WireGuard ports, Tailscale's port, and ports an allow rule opens on purpose. null when it may be closed.
     */
    private function protectedPort(string $serverId, RuleProtocol $protocol, int $port): ?string
    {
        $payload = $this->compiler->compile($serverId);
        $ssh = array_unique([(int) $payload['ssh_port'], $this->keys->sshPort($serverId), 22]);

        if ($protocol !== RuleProtocol::Udp && in_array($port, $ssh, true)) {
            return 'it is the SSH port.';
        }

        if ($protocol !== RuleProtocol::Udp && in_array($port, [80, 443], true)) {
            return 'the edge serves sites on it.';
        }

        foreach ($payload['rules'] as $rule) {
            if (str_starts_with((string) $rule['id'], 'wg-') && $protocol !== RuleProtocol::Tcp && in_array((string) $port, $rule['ports'] ?? [], true)) {
                return 'a private network (WireGuard) uses it.';
            }

            if ($rule['id'] === 'tailscale-direct' && $protocol !== RuleProtocol::Tcp && in_array((string) $port, $rule['ports'], true)) {
                return 'Tailscale uses it.';
            }
        }

        $allowed = FirewallRule::query()
            ->where('server_id', $serverId)
            ->where('action', RuleAction::Allow->value)
            ->whereNotNull('port')
            ->get()
            ->first(function (FirewallRule $rule) use ($protocol, $port) {
                [$from, $to] = array_pad(explode('-', (string) $rule->port, 2), 2, $rule->port);
                $sameProtocol = $rule->protocol === RuleProtocol::Any || $protocol === RuleProtocol::Any || $rule->protocol === $protocol;

                return $sameProtocol && $port >= (int) $from && $port <= (int) $to;
            });

        return $allowed !== null ? "the allow rule \"{$allowed->name}\" opens it." : null;
    }

    public function deleteRule(string $serverId, string $ruleId): bool
    {
        $rule = FirewallRule::query()->where('server_id', strtolower($serverId))->whereKey($ruleId)->first();

        if ($rule === null) {
            return false;
        }

        app(DeleteFirewallRule::class)($rule);

        return true;
    }
}
