<?php

namespace Falak\Network\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Insights\Contracts\SecurityIssues;
use Falak\Network\Application\ExposedPorts;
use Falak\Network\Application\Jobs\ResolvePeerNames;
use Falak\Network\Domain\Models\NetworkObservation;
use Falak\Servers\Contracts\Data\ServerData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A server's net.observed report (docs/plans/network/connections.md): kept as the server's latest (the tables), audited
 * for exposed ports (security Issues), and turned into alerts: conntrack near full, a port newly exposed, a WireGuard
 * peer whose handshake is stale, a probe failing or its latency spiking. Unnamed public peers get a reverse DNS
 * lookup, queued.
 */
final class RecordNetworkObservation
{
    public const ALERT_CONNTRACK = 'network.conntrack_full';

    public const ALERT_EXPOSED = 'network.exposed_port';

    public const ALERT_WIREGUARD = 'network.wireguard_stale';

    public const ALERT_LATENCY = 'network.latency_spike';

    public const ISSUE_SOURCE = 'network.exposed_port';

    public function __construct(
        private readonly ExposedPorts $exposed,
        private readonly SecurityIssues $issues,
        private readonly AlertConditions $conditions,
    ) {}

    /**
     * @param  array<string, mixed>  $report  schema-validated
     */
    public function __invoke(ServerData $server, array $report): void
    {
        $at = CarbonImmutable::parse((string) $report['at'])->utc();

        if ($at->isAfter(now()->addMinutes(5))) {
            $at = CarbonImmutable::now();
        }

        $observation = NetworkObservation::query()->firstOrNew(['server_id' => $server->id], ['organization_id' => $server->organizationId]);

        // A replayed (older) report never replaces a newer one.
        if ($observation->observed_at !== null && $at->lessThan($observation->observed_at)) {
            return;
        }

        $baselines = $this->probes($server, (array) $report['probes'], (array) ($observation->baselines ?? []));
        $observation->forceFill(['report' => $report, 'observed_at' => $at, 'baselines' => $baselines])->save();

        try {
            $this->exposedPorts($server, $report, $at);
        } catch (Throwable $e) {
            Log::warning('Exposed ports could not be audited.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
        }

        $this->conntrack($server, (array) $report['conntrack']);
        $this->wireguard($server, (array) $report['wireguard']);
        $this->resolveNames($server, $report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exposedPorts(ServerData $server, array $report, CarbonImmutable $at): void
    {
        $found = $this->exposed->find($server->id, $report);
        $keys = [];

        foreach ($found as $port) {
            $keys[] = $port['key'];
            $this->issues->record($server->organizationId, $server->id, self::ISSUE_SOURCE, $port['key'], "{$port['title']} on {$server->name}",
                $port['owner'], $at, 1, ['address' => $port['address'], 'owner' => $port['owner']], ['proto' => $port['proto'], 'port' => $port['port']]);
            $this->conditions->observe($server->organizationId, "network.exposed:{$server->id}:{$port['key']}", true, fn () => new AlertData(
                $server->organizationId, self::ALERT_EXPOSED, Severity::Warning,
                "{$server->name}: {$port['title']}",
                ($port['owner'] ?? 'A process')." listens on {$port['address']}:{$port['port']} and the firewall lets it through. Bind it to 127.0.0.1 or a private address, or close the port in the firewall.",
                "/servers/{$server->id}/network", context: ['server_id' => $server->id, 'port' => $port['key']], action: 'Review open ports',
            ));
        }

        // A cut listener list says nothing about the ports it left out: they stay as they are.
        if (ExposedPorts::truncated($report)) {
            return;
        }

        $this->issues->resolveExcept($server->organizationId, $server->id, self::ISSUE_SOURCE, $keys);
        $this->conditions->clearExcept($server->organizationId, "network.exposed:{$server->id}:", array_map(fn ($k) => "network.exposed:{$server->id}:{$k}", $keys));
    }

    /**
     * @param  array<string, mixed>  $conntrack
     */
    private function conntrack(ServerData $server, array $conntrack): void
    {
        $limit = (int) ($conntrack['limit'] ?? 0);
        $percent = $limit > 0 ? round((int) ($conntrack['entries'] ?? 0) * 100 / $limit, 1) : null;
        $threshold = (int) config('network.connections.conntrack_percent', 85);

        $this->conditions->observe($server->organizationId, "network.conntrack:{$server->id}", $percent === null ? false : $percent >= $threshold, fn () => new AlertData(
            $server->organizationId, self::ALERT_CONNTRACK, Severity::Warning,
            "{$server->name}: the connection tracking table is {$percent}% full",
            "When it is full the kernel drops new connections (\"nf_conntrack: table full\"). Raise net.netfilter.nf_conntrack_max or find who opens so many connections in the server's Network tab.",
            "/servers/{$server->id}/network", context: ['server_id' => $server->id, 'percent' => $percent], action: 'Review connections',
        ), forSeconds: (int) config('network.connections.alert_seconds', 300));
    }

    /**
     * @param  list<array<string, mixed>>  $peers
     */
    private function wireguard(ServerData $server, array $peers): void
    {
        $stale = (int) config('network.connections.handshake_stale_seconds', 300);
        $keys = [];

        foreach ($peers as $peer) {
            $key = "network.wireguard:{$server->id}:{$peer['public_key']}";
            $keys[] = $key;
            $age = $peer['handshake_age_s'];
            $label = $peer['name'] ?? substr((string) $peer['public_key'], 0, 8);

            $this->conditions->observe($server->organizationId, $key, $age === null || $age >= $stale, fn () => new AlertData(
                $server->organizationId, self::ALERT_WIREGUARD, Severity::Warning,
                "{$server->name}: WireGuard peer {$label} is not connected",
                $age === null ? 'The peer never completed a handshake.' : "Its latest handshake is {$age} s old: the tunnel is down, or the peer is unreachable on its WireGuard port.",
                "/servers/{$server->id}/network", context: ['server_id' => $server->id, 'peer' => $peer['name'] ?? null, 'interface' => $peer['interface']], action: 'Check the private network',
            ), forSeconds: (int) config('network.connections.alert_seconds', 300));
        }

        $this->conditions->clearExcept($server->organizationId, "network.wireguard:{$server->id}:", $keys);
    }

    /**
     * Latency alerts against each probe's baseline (an exponentially weighted mean, not moved while the probe is
     * failing or spiking). Returns the new baselines.
     *
     * @param  list<array<string, mixed>>  $probes
     * @param  array<string, float>  $baselines
     * @return array<string, float>
     */
    private function probes(ServerData $server, array $probes, array $baselines): array
    {
        $factor = (float) config('network.connections.latency_factor', 3);
        $min = (float) config('network.connections.latency_min_ms', 50);
        $next = [];
        $keys = [];

        foreach ($probes as $probe) {
            $name = (string) $probe['name'];
            $base = isset($baselines[$name]) ? (float) $baselines[$name] : null;
            $ms = isset($probe['connect_ms']) ? (float) $probe['connect_ms'] : null;
            $failing = ! $probe['ok'];
            $spike = ! $failing && $ms !== null && $base !== null && $ms >= max($factor * $base, $base + $min);

            if (! $failing && ! $spike && $ms !== null) {
                $base = $base === null ? $ms : round($base + 0.05 * ($ms - $base), 3);
            }

            if ($base !== null) {
                $next[$name] = $base;
            }

            $key = "network.latency:{$server->id}:{$name}";
            $keys[] = $key;
            $target = "{$probe['host']}:{$probe['port']}";

            $this->conditions->observe($server->organizationId, $key, $failing || $spike, fn () => new AlertData(
                $server->organizationId, self::ALERT_LATENCY, Severity::Warning,
                $failing ? "{$server->name} cannot reach {$target}" : "{$server->name}: connecting to {$target} takes ".round((float) $ms).' ms',
                $failing ? 'The connection test failed ('.($probe['error'] ?? 'error').').'
                    : 'Usually '.round((float) $base, 1).' ms. Requests between the two are slower until it recovers.',
                "/servers/{$server->id}/network", context: ['server_id' => $server->id, 'target' => $name, 'connect_ms' => $ms, 'baseline_ms' => $base],
                action: 'Review latency',
            ), forSeconds: (int) config('network.connections.alert_seconds', 300));
        }

        $this->conditions->clearExcept($server->organizationId, "network.latency:{$server->id}:", $keys);

        return $next;
    }

    /**
     * Queues reverse DNS for unnamed public peers not looked up recently.
     *
     * @param  array<string, mixed>  $report
     */
    private function resolveNames(ServerData $server, array $report): void
    {
        $addresses = [];

        foreach ((array) $report['workloads'] as $workload) {
            foreach ((array) ($workload['peers'] ?? []) as $peer) {
                $address = (string) $peer['address'];

                if (($peer['name'] ?? '') === '' && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    $addresses[$address] = true;
                }
            }
        }

        if ($addresses === []) {
            return;
        }

        $known = DB::table('network_peer_names')->whereIn('address', array_keys($addresses))
            ->where('resolved_at', '>=', now()->subHours((int) config('network.connections.rdns_ttl_hours', 24)))
            ->pluck('address')->all();
        $todo = array_slice(array_values(array_diff(array_keys($addresses), $known)), 0, (int) config('network.connections.rdns_per_report', 20));

        if ($todo !== []) {
            // Placeholders first: the next reports see these addresses as asked, whatever the job's fate.
            DB::table('network_peer_names')->upsert(
                array_map(fn (string $address) => ['address' => $address, 'name' => null, 'resolved_at' => now()], $todo),
                ['address'],
                ['resolved_at'],
            );
            ResolvePeerNames::dispatch($server->id, $todo);
        }
    }
}
