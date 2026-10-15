<?php

namespace Falak\Network\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Contracts\SecurityIssues;
use Falak\Kernel\Http\Controller;
use Falak\Network\Application\Actions\RecordNetworkObservation;
use Falak\Network\Application\ConfigureConnections;
use Falak\Network\Application\Queries\ConnectionQueries;
use Falak\Network\Domain\Models\NetworkObservation;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\MetricsBackend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * JSON behind the Network tabs (docs/plans/network/connections.md, "UI"): a server's connections, listeners, talks-to,
 * conntrack, WireGuard peers and latency; a site's (or compose service's) and a database instance's connections. The
 * tables come from the agent's latest net.observed report, the charts from VictoriaMetrics.
 */
final class ConnectionsController extends Controller
{
    use ResolvesServers;

    /** @var array<string, array{label: string, url: ?string}> */
    private array $names = [];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
        private readonly DatabaseDirectory $databases,
        private readonly AgentDirectory $agents,
        private readonly MetricsBackend $metrics,
    ) {}

    /** GET /servers/{server}/network: the server's Network tab (its data comes from the JSON endpoints below). */
    public function page(Request $request, string $server): Response
    {
        $data = $this->server($request->user(), $server);

        return Inertia::render('Network/ServerNetwork', ['server' => app(ServerHeaders::class)->for($data->id)]);
    }

    /** GET /network/servers/{server}/connections?range= */
    public function forServer(Request $request, string $server, SecurityIssues $issues): JsonResponse
    {
        $data = $this->server($request->user(), $server);
        [$range, $step] = $this->range($request);
        $observation = NetworkObservation::query()->find($data->id);
        $report = (array) ($observation?->report ?? []);
        $exposed = collect($issues->open($data->organizationId, $data->id, RecordNetworkObservation::ISSUE_SOURCE));
        $workloads = (array) ($report['workloads'] ?? []);
        $listeners = [];

        foreach ($workloads as $w) {
            if (! in_array($w['kind'], ['host', 'processes'], true)) {
                continue;
            }

            foreach ((array) $w['listeners'] as $l) {
                $issue = $exposed->get("{$l['proto']}/{$l['port']}");
                $listeners[] = [...$l, 'owner' => $this->name($l['owner'] ?? ($w['kind'] === 'processes' ? "site:{$w['site']}" : null)),
                    'exposed' => $issue !== null && ($l['public'] ?? false), 'issue_url' => $issue?->url];
            }
        }

        usort($listeners, fn ($a, $b) => [$a['port'], $a['proto']] <=> [$b['port'], $b['proto']]);
        $probes = collect((array) ($report['probes'] ?? []))->keyBy('name');

        return response()->json([
            ...$this->frame($range, $step, [$data->id], $observation),
            'conntrack' => $report['conntrack'] ?? null,
            'listeners' => $listeners,
            'talks_to' => $this->peers($workloads, 'outbound'),
            'clients' => $this->peers($workloads, 'inbound'),
            'workloads' => array_map(fn ($w) => ['kind' => $w['kind'], 'name' => $w['name'], 'label' => $this->workloadLabel($w), 'connections' => $w['connections']], $workloads),
            'wireguard' => array_map(fn ($p) => [...$p, 'peer' => isset($p['name']) ? $this->name($p['name']) : null,
                'connect_ms' => isset($p['name']) ? ($probes[$p['name']]['connect_ms'] ?? null) : null], (array) ($report['wireguard'] ?? [])),
            'probes' => array_map(fn ($p) => [...$p, 'target' => $this->name($p['name'])], (array) ($report['probes'] ?? [])),
            ...$this->charts(ConnectionQueries::server($data->id, $step), $range, $step),
        ]);
    }

    /** GET /network/sites/{site}/connections?range=&server=&service= */
    public function site(Request $request, string $site, SiteDirectory $sites): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');
        $data = $sites->find(strtolower($site));
        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        $input = $request->validate(['server' => ['nullable', 'string', 'max:26'], 'service' => ['nullable', 'string', 'max:255']]);
        [$range, $step] = $this->range($request);
        $serverIds = array_map('strtolower', $data->serverIds());

        if (isset($input['server'])) {
            abort_unless(in_array(strtolower($input['server']), $serverIds, true), 422, 'The site does not run on this server.');
            $serverIds = [strtolower($input['server'])];
        }

        $service = $input['service'] ?? null;
        $observations = NetworkObservation::query()->whereIn('server_id', $serverIds)->get();
        $workloads = [];

        foreach ($observations as $observation) {
            foreach ((array) ($observation->report['workloads'] ?? []) as $w) {
                if (($w['site'] ?? null) === $data->slug && ($service === null || ($w['compose_service'] ?? null) === $service)) {
                    $workloads[] = [...$w, 'server_id' => $observation->server_id];
                }
            }
        }

        $selector = ['falak_site_id' => strtoupper($data->id)] + ($service !== null ? ['falak_compose_service' => $service] : []);

        return response()->json([
            ...$this->frame($range, $step, $serverIds, $observations->sortByDesc('observed_at')->first()),
            'services' => array_values(array_unique(array_filter(array_map(fn ($w) => $w['compose_service'] ?? null, $workloads)))),
            'workloads' => array_map(fn ($w) => ['kind' => $w['kind'], 'name' => $w['name'], 'label' => $this->workloadLabel($w), 'server_id' => $w['server_id'], 'connections' => $w['connections']], $workloads),
            'listeners' => array_merge(...array_map(fn ($w) => array_map(fn ($l) => [...$l, 'workload' => $w['name'], 'owner' => $this->name($l['owner'] ?? null)], (array) $w['listeners']), $workloads) ?: [[]]),
            'talks_to' => $this->peers($workloads, 'outbound'),
            'clients' => $this->peers($workloads, 'inbound'),
            ...$this->charts(ConnectionQueries::workload($selector, $serverIds, $step), $range, $step),
        ]);
    }

    /**
     * GET /network/databases/{instance}/connections?range=: the instance's connected clients. Takes the instance's id or
     * the id of a database on it (the canvas service).
     */
    public function database(Request $request, string $instance): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $this->databases->instance(strtolower($instance))
            ?? (($database = $this->databases->find(strtolower($instance)))?->instanceId !== null ? $this->databases->instance($database->instanceId) : null);
        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, 'databases.view');
        [$range, $step] = $this->range($request);
        $observation = NetworkObservation::query()->find($data->serverId);
        $workloads = array_values(array_filter((array) ($observation?->report['workloads'] ?? []), fn ($w) => ($w['db_instance'] ?? null) === $data->id));

        return response()->json([
            ...$this->frame($range, $step, [$data->serverId], $observation),
            'connections' => $workloads[0]['connections'] ?? null,
            'clients' => $this->peers($workloads, 'inbound'),
            ...$this->charts(ConnectionQueries::workload(['falak_db_instance_id' => $data->id], [$data->serverId], $step), $range, $step),
        ]);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function range(Request $request): array
    {
        $range = (string) ($request->validate(['range' => ['nullable', Rule::in(array_keys(ConnectionQueries::RANGES))]])['range'] ?? '1h');

        return [$range, ConnectionQueries::RANGES[$range][1]];
    }

    /**
     * Why the tables may be empty: `agent_outdated` (no net.connections on one of the servers), `waiting` (no report
     * yet), `stale` (the latest is old), `ok`.
     *
     * @param  list<string>  $serverIds
     * @return array<string, mixed>
     */
    private function frame(string $range, int $step, array $serverIds, ?NetworkObservation $observation): array
    {
        $outdated = collect($this->agents->forServers($serverIds))->contains(fn ($agent) => ! $agent->supports(ConfigureConnections::FEATURE));
        $stale = (int) config('network.connections.stale_seconds', 300);

        return [
            'range' => $range,
            'step' => $step,
            'observed_at' => $observation?->observed_at?->toIso8601String(),
            'reporting' => match (true) {
                $outdated => 'agent_outdated',
                $observation?->observed_at === null => 'waiting',
                $observation->observed_at->lt(now()->subSeconds($stale)) => 'stale',
                default => 'ok',
            },
        ];
    }

    /**
     * Every workload's peers of one direction, named, with the reverse DNS of unnamed addresses; most connections first.
     *
     * @param  list<array<string, mixed>>  $workloads
     * @return list<array<string, mixed>>
     */
    private function peers(array $workloads, string $direction): array
    {
        $rows = [];

        foreach ($workloads as $w) {
            foreach ((array) ($w['peers'] ?? []) as $p) {
                if ($p['direction'] === $direction) {
                    $rows[] = [...$p, 'workload' => $this->workloadLabel($w), 'peer' => isset($p['name']) ? $this->name($p['name']) : null];
                }
            }

            if (isset($w['other'][$direction])) {
                $rows[] = ['direction' => $direction, 'address' => null, 'port' => 0, 'other' => true, 'workload' => $this->workloadLabel($w), 'peer' => null, ...$w['other'][$direction]];
            }
        }

        $unnamed = array_values(array_unique(array_filter(array_map(fn ($r) => $r['peer'] === null ? $r['address'] : null, $rows))));
        $rdns = $unnamed === [] ? [] : DB::table('network_peer_names')->whereIn('address', $unnamed)->whereNotNull('name')->pluck('name', 'address')->all();
        usort($rows, fn ($a, $b) => [isset($a['other']), -$a['connections']] <=> [isset($b['other']), -$b['connections']]);

        return array_map(fn ($r) => [...$r, 'rdns' => $r['address'] !== null ? ($rdns[$r['address']] ?? null) : null], $rows);
    }

    /**
     * @param  array<string, mixed>  $w
     */
    private function workloadLabel(array $w): string
    {
        return match ($w['kind']) {
            'host' => 'Server',
            'processes' => (string) ($w['site'] ?? $w['name']),
            default => isset($w['db_instance']) ? ($this->name("db:{$w['db_instance']}")['label'] ?? $w['name']) : (string) $w['name'],
        };
    }

    /**
     * A peer name (server:<id>, db:<id>, site:<slug>[/<service>], host:<process>, host) as a label and a link; null when
     * it names nothing of this organization.
     *
     * @return array{label: string, url: ?string}|null
     */
    private function name(?string $name): ?array
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (array_key_exists($name, $this->names)) {
            return $this->names[$name];
        }

        $organizationId = $this->organization->requireId();
        [$kind, $ref] = array_pad(explode(':', $name, 2), 2, '');

        return $this->names[$name] = match ($kind) {
            'server' => ($s = $this->servers->find($ref)) && $s->organizationId === $organizationId ? ['label' => $s->name, 'url' => "/servers/{$s->id}"] : null,
            'db' => ($d = $this->databases->instance($ref)) && $d->organizationId === $organizationId ? ['label' => $d->name, 'url' => null] : null,
            'site' => ['label' => $ref, 'url' => null],
            'host' => ['label' => $ref === '' ? 'this server' : $ref, 'url' => null],
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $queries
     * @return array{charts: array<string, mixed>, errors: object}
     */
    private function charts(array $queries, string $range, int $step): array
    {
        $charts = [];
        $errors = [];

        if ((string) config('telemetry.metrics.query_url') === '') {
            return ['charts' => (object) [], 'errors' => (object) ['metrics' => 'not_configured']];
        }

        $end = CarbonImmutable::now();
        $start = $end->subSeconds(ConnectionQueries::RANGES[$range][0]);

        foreach ($queries as $key => $promql) {
            try {
                $charts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $this->metrics->queryRange($promql, $start, $end, $step));
            } catch (TelemetryQueryFailed $e) {
                $charts[$key] = [];
                $errors[$key] = $e->getMessage();
            } catch (TelemetryUnavailable $e) {
                return ['charts' => (object) $charts, 'errors' => (object) ['metrics' => $e->getMessage()]];
            }
        }

        return ['charts' => (object) $charts, 'errors' => (object) $errors];
    }
}
