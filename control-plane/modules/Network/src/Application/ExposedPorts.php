<?php

namespace Falak\Network\Application;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Infrastructure\FirewallCompiler;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerSshKeys;

/**
 * The exposed-ports audit (docs/plans/network/connections.md): a public listener of the host is exposed when it is
 * reachable from anywhere (the firewall is not applied, the first rule that matches it from anywhere accepts it, or a
 * container publishes it: Docker's DNAT runs before the input chain) and Falak does not expose it on purpose (the edge,
 * SSH, private networks' WireGuard ports, a database published with public access).
 */
final class ExposedPorts
{
    /** Well-known services, for the Issue's title. */
    private const NAMES = [
        'tcp/21' => 'FTP', 'tcp/23' => 'Telnet', 'tcp/25' => 'SMTP', 'tcp/111' => 'rpcbind', 'tcp/445' => 'SMB',
        'tcp/1433' => 'SQL Server', 'tcp/2375' => 'Docker API', 'tcp/2376' => 'Docker API', 'tcp/2379' => 'etcd',
        'tcp/3306' => 'MySQL', 'tcp/5432' => 'PostgreSQL', 'tcp/5672' => 'RabbitMQ',
        'tcp/5984' => 'CouchDB', 'tcp/6379' => 'Redis', 'tcp/7700' => 'Meilisearch', 'tcp/8086' => 'InfluxDB',
        'tcp/9000' => 'PHP-FPM', 'tcp/9090' => 'Prometheus', 'tcp/9200' => 'Elasticsearch', 'tcp/11211' => 'Memcached',
        'udp/11211' => 'Memcached', 'tcp/15672' => 'RabbitMQ management', 'tcp/27017' => 'MongoDB', 'tcp/6443' => 'Kubernetes API',
    ];

    public function __construct(
        private readonly FirewallCompiler $compiler,
        private readonly ServerDirectory $servers,
        private readonly ServerSshKeys $keys,
        private readonly DatabaseDirectory $databases,
    ) {}

    /**
     * @param  array<string, mixed>  $report  a net.observed report
     * @return list<array{key: string, proto: string, port: int, address: string, owner: ?string, title: string}> by port
     */
    public function find(string $serverId, array $report): array
    {
        $listeners = [];

        // The host namespace: the host's own sockets and those of native sites (a Node app on 0.0.0.0:3000).
        foreach (self::hostWorkloads($report) as $w) {
            foreach ((array) ($w['listeners'] ?? []) as $l) {
                if (is_array($l) && ($l['public'] ?? false) === true) {
                    $listeners[] = $l;
                }
            }
        }

        if ($listeners === []) {
            return [];
        }

        $server = $this->servers->find($serverId);
        $payload = $this->compiler->compile($serverId);
        $state = FirewallState::query()->find($serverId);
        $applied = $state !== null && $state->status !== ApplyStatus::Failed && $state->applied_at !== null;
        $intended = $this->intended($serverId, $payload, $server?->organizationId);
        $found = [];

        foreach ($listeners as $l) {
            $proto = (string) $l['proto'];
            $port = (int) $l['port'];
            $key = "{$proto}/{$port}";

            if (isset($found[$key]) || isset($intended[$key]) || ($proto === 'udp' && in_array($port, [68, 546], true))) {
                continue;
            }

            $published = ($l['process'] ?? null) === 'docker-proxy' || ($l['container'] ?? '') !== '';

            if (! $published && $applied && ! self::accepted($payload['rules'], $proto, $port)) {
                continue;
            }

            $owner = match (true) {
                ($l['container'] ?? '') !== '' => "container {$l['container']}",
                ($l['process'] ?? '') !== '' => $l['process'],
                str_starts_with((string) ($l['owner'] ?? ''), 'site:') => 'site '.substr((string) $l['owner'], 5),
                default => null,
            };
            $found[$key] = [
                'key' => $key,
                'proto' => $proto,
                'port' => $port,
                'address' => (string) $l['address'],
                'owner' => $owner,
                'title' => (isset(self::NAMES[$key]) ? self::NAMES[$key]." {$port}" : "Port {$key}").' reachable from the internet',
            ];
        }

        ksort($found, SORT_NATURAL);

        return array_values($found);
    }

    /**
     * Whether the agent cut a host-namespace listener list (more than it sends): ports missing from the report may still
     * be open, so nothing is resolved from it.
     *
     * @param  array<string, mixed>  $report
     */
    public static function truncated(array $report): bool
    {
        foreach (self::hostWorkloads($report) as $w) {
            if (($w['listeners_truncated'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private static function hostWorkloads(array $report): array
    {
        return array_values(array_filter((array) ($report['workloads'] ?? []), fn ($w) => is_array($w) && in_array($w['kind'] ?? null, ['host', 'processes'], true)));
    }

    /**
     * Ports Falak exposes on purpose: the edge, SSH, the WireGuard ports of its private networks, databases published
     * with public access (behind their allowlist).
     *
     * @param  array<string, mixed>  $payload  the compiled firewall
     * @return array<string, true>
     */
    private function intended(string $serverId, array $payload, ?string $organizationId): array
    {
        $ports = ['tcp/80' => true, 'tcp/443' => true, 'udp/443' => true, 'tcp/22' => true, "tcp/{$payload['ssh_port']}" => true,
            'tcp/'.$this->keys->sshPort($serverId) => true];

        foreach ($payload['rules'] as $rule) {
            if (str_starts_with((string) $rule['id'], 'wg-')) {
                foreach ($rule['ports'] ?? [] as $port) {
                    $ports["udp/{$port}"] = true;
                }
            }
        }

        if ($organizationId !== null) {
            foreach ($this->databases->instancesForOrganization($organizationId) as $instance) {
                if ($instance->serverId === strtolower($serverId) && $instance->publicAccess && $instance->hostPort !== null) {
                    $ports["tcp/{$instance->hostPort}"] = true;
                }
            }
        }

        return $ports;
    }

    /**
     * Whether the first rule matching the port from anywhere (no interface, no sources) accepts it; no match: the
     * default drop. Rules with sources open or close the port for some addresses only and are skipped.
     *
     * @param  list<array<string, mixed>>  $rules  compiled, in order
     */
    public static function accepted(array $rules, string $proto, int $port): bool
    {
        foreach ($rules as $rule) {
            if (isset($rule['interface']) || ($rule['sources'] ?? []) !== []) {
                continue;
            }

            if ($rule['protocol'] !== 'any' && $rule['protocol'] !== $proto) {
                continue;
            }

            foreach ($rule['ports'] ?? ['1-65535'] as $range) {
                [$from, $to] = array_pad(explode('-', (string) $range, 2), 2, $range);

                if ($port >= (int) $from && $port <= (int) $to) {
                    return $rule['action'] === 'accept';
                }
            }
        }

        return false;
    }
}
