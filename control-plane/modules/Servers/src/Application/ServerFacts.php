<?php

namespace Falak\Servers\Application;

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Network\Contracts\Firewalls;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerUpdated;

/**
 * Copies agent-reported host facts (facts.schema.json) onto the server.
 */
final class ServerFacts
{
    public function __construct(
        private readonly EdgeRoutes $edge,
        private readonly Firewalls $firewalls,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     */
    public function record(Server $server, array $facts): void
    {
        $os = is_array($facts['os'] ?? null) ? trim(($facts['os']['id'] ?? '').' '.($facts['os']['version'] ?? '')) : null;
        $stored = $facts;
        $previous = is_array($server->facts) ? $server->facts : [];

        // The stored facts record where private_ipv4 came from (privateIpv4()): a report without the key keeps the
        // last one the agent sent, so a later null report can still clear it.
        if (! array_key_exists('private_ipv4', $facts) && array_key_exists('private_ipv4', $previous)) {
            $stored['private_ipv4'] = $previous['private_ipv4'];
        }

        $server->forceFill([
            'facts' => $stored,
            'os' => $os ?: $server->os,
            'arch' => $facts['arch'] ?? $server->arch,
            'cpus' => isset($facts['cpus']) ? (int) $facts['cpus'] : $server->cpus,
            'memory_bytes' => isset($facts['memory_bytes']) ? (int) $facts['memory_bytes'] : $server->memory_bytes,
            'disk_bytes' => isset($facts['disk_bytes']) ? (int) $facts['disk_bytes'] : $server->disk_bytes,
            'ipv4' => $server->ipv4 ?? ($facts['public_ipv4'] ?? null),
            'private_ipv4' => $this->privateIpv4($server, $facts),
        ])->save();

        ServerUpdated::dispatch($server->id, $server->status->value, $server->status_message, $server->provision_command_id);

        // The control plane's machine: the agent switches its edge (8080/8443 behind the control plane's edge) and
        // firewall to local mode only on the next edge.caddy.apply / net.firewall.apply. Provisioning sends both at its
        // end; an active server that just turned local_edge on gets them now.
        if ($server->control_plane_host && $server->status === ServerStatus::Active
            && ($facts['local_edge'] ?? false) === true && ($previous['local_edge'] ?? false) !== true) {
            $this->edge->apply($server->id, force: true);
            $this->firewalls->reapply($server->id);
        }
    }

    /**
     * The agent's private address wins. When it reports none on a custom server (where only the agent sets it), the
     * address it reported before is cleared: agents before v0.5.2 reported Docker's bridge, 172.17.0.1, which is not
     * reachable from other servers. A provider server keeps its provider's address.
     *
     * @param  array<string, mixed>  $facts
     */
    private function privateIpv4(Server $server, array $facts): ?string
    {
        if ($reported = ($facts['private_ipv4'] ?? null) ?: null) {
            return (string) $reported;
        }

        $previous = is_array($server->facts) ? ($server->facts['private_ipv4'] ?? null) : null;

        if ($server->isCustom() && array_key_exists('private_ipv4', $facts) && $server->private_ipv4 !== null && $server->private_ipv4 === $previous) {
            return null;
        }

        return $server->private_ipv4;
    }
}
