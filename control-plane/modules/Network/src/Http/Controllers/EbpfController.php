<?php

namespace Falak\Network\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Network\Application\ConfigureEbpf;
use Falak\Network\Application\Queries\ConnectionQueries;
use Falak\Network\Application\Queries\EbpfQueries;
use Falak\Network\Domain\Models\EbpfSetting;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\MetricsBackend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The eBPF setting and the TCP & DNS sections of the Network tabs (docs/plans/network/ebpf.md): per server the setting
 * and the agent's facts.ebpf, the charts from VictoriaMetrics, and the most queried DNS names.
 */
final class EbpfController extends Controller
{
    use ResolvesServers;

    /** Turning the probe on or off (and its options): Admin only. */
    private const MANAGE = 'network.ebpf';

    /** The server workload's DNS names (every process outside a site): the network managers only. */
    private const HOST_NAMES = 'network.manage';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentDirectory $agents,
        private readonly MetricsBackend $metrics,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * PUT /network/servers/{server}/ebpf {enabled, full_dns_names?, reenable?}: Admin only (`network.ebpf`). `reenable`
     * restarts a probe the agent stopped over its CPU budget; nothing else does.
     */
    public function settings(Request $request, string $server, ConfigureEbpf $configure, AuditLog $audit): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $this->server($request->user(), $server, self::MANAGE);
        abort_if($data->organizationId !== $organizationId, 404);
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'full_dns_names' => ['sometimes', 'boolean'], 'reenable' => ['sometimes', 'boolean']]);

        $setting = EbpfSetting::query()->find($data->id) ?? new EbpfSetting(['server_id' => $data->id, 'organization_id' => $data->organizationId]);
        $changes = ['enabled' => (bool) $input['enabled'], 'full_dns_names' => (bool) ($input['full_dns_names'] ?? $setting->full_dns_names),
            'reenable' => (bool) $input['enabled'] && (bool) ($input['reenable'] ?? false)];
        // A new intent supersedes whatever is in flight (its late outcome no longer matches the command id).
        $setting->forceFill([...$changes, 'status' => 'pending', 'command_id' => null, 'error' => null])->save();
        $audit->record('network.ebpf_updated', 'server', $data->id, $changes, $data->organizationId);
        $configure($data->id);

        return response()->json(['servers' => $this->status([$data->id], $request)]);
    }

    /** GET /network/servers/{server}/ebpf?range= */
    public function forServer(Request $request, string $server): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $this->server($request->user(), $server);
        abort_if($data->organizationId !== $organizationId, 404);

        return $this->respond($request, [], [$data->id], server: true,
            hostNames: $this->access->can($request->user(), $data->organizationId, self::HOST_NAMES));
    }

    /** GET /network/sites/{site}/ebpf?range=&server=&service= */
    public function site(Request $request, string $site, SiteDirectory $sites): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'telemetry.view');
        $data = $sites->find(strtolower($site));
        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        $input = $request->validate(['server' => ['nullable', 'string', 'max:26'], 'service' => ['nullable', 'string', 'max:255']]);
        $serverIds = array_map('strtolower', $data->serverIds());

        if (isset($input['server'])) {
            abort_unless(in_array(strtolower($input['server']), $serverIds, true), 422, 'The site does not run on this server.');
            $serverIds = [strtolower($input['server'])];
        }

        $workload = ['falak_site_id' => strtoupper($data->id)] + (isset($input['service']) ? ['falak_compose_service' => $input['service']] : []);

        return $this->respond($request, $workload, $serverIds);
    }

    /** GET /network/databases/{instance}/ebpf?range=: the instance's container (the instance's id or a database's on it). */
    public function database(Request $request, string $instance, DatabaseDirectory $databases): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $databases->instance(strtolower($instance))
            ?? (($database = $databases->find(strtolower($instance)))?->instanceId !== null ? $databases->instance($database->instanceId) : null);
        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, 'databases.view');

        return $this->respond($request, ['falak_db_instance_id' => $data->id], [$data->serverId]);
    }

    /**
     * @param  array<string, string>  $workload
     * @param  list<string>  $serverIds
     */
    private function respond(Request $request, array $workload, array $serverIds, bool $server = false, bool $hostNames = true): JsonResponse
    {
        $range = (string) ($request->validate(['range' => ['nullable', Rule::in(array_keys(ConnectionQueries::RANGES))]])['range'] ?? '1h');
        [$seconds, $step] = ConnectionQueries::RANGES[$range];
        $status = $this->status($serverIds, $request);
        $running = collect($status)->contains(fn ($s) => $s['enabled'] || in_array($s['probe']['state'] ?? 'off', ['running', 'degraded'], true));

        $charts = $errors = [];
        $names = [];

        if ($running) {
            if ((string) config('telemetry.metrics.query_url') === '') {
                $errors['metrics'] = 'not_configured';
            } else {
                $end = CarbonImmutable::now();

                try {
                    foreach (EbpfQueries::charts($workload, $serverIds, $step, $server) as $key => $promql) {
                        try {
                            $charts[$key] = array_map(fn (MetricSeries $s) => $s->toArray(), $this->metrics->queryRange($promql, $end->subSeconds($seconds), $end, $step));
                        } catch (TelemetryQueryFailed $e) {
                            $charts[$key] = [];
                            $errors[$key] = $e->getMessage();
                        }
                    }

                    foreach ($this->metrics->query(EbpfQueries::topNames($workload, $serverIds, $seconds, $hostNames)['names'], $end) as $s) {
                        $names[] = ['name' => $s->labels['falak_net_dns_name'] ?? '', 'queries' => (int) round($s->last() ?? 0)];
                    }

                    usort($names, fn ($a, $b) => [$b['queries'], $a['name']] <=> [$a['queries'], $b['name']]);
                } catch (TelemetryUnavailable $e) {
                    $errors['metrics'] = $e->getMessage();
                } catch (TelemetryQueryFailed $e) {
                    $errors['names'] = $e->getMessage();
                }
            }
        }

        return response()->json([
            'range' => $range,
            'step' => $step,
            'servers' => $status,
            'charts' => (object) $charts,
            'names' => $names,
            'names_scope' => $hostNames ? 'all' : 'workloads',
            'errors' => (object) $errors,
        ]);
    }

    /**
     * Per server: the setting, its delivery, whether the agent can take it, and the agent's own report (facts.ebpf).
     *
     * @param  list<string>  $serverIds
     * @return list<array<string, mixed>>
     */
    private function status(array $serverIds, Request $request): array
    {
        $agents = $this->agents->forServers($serverIds);
        $settings = EbpfSetting::query()->whereIn('server_id', $serverIds)->get()->keyBy('server_id');

        return array_values(array_map(function (string $id) use ($agents, $settings, $request) {
            $agent = $agents[$id] ?? null;
            $setting = $settings->get($id);
            $server = $this->servers->find($id);
            $probe = $agent?->facts['ebpf'] ?? null;

            return [
                'server_id' => $id,
                'name' => $server?->name,
                'enabled' => (bool) $setting?->enabled,
                'full_dns_names' => (bool) $setting?->full_dns_names,
                'delivery' => $setting?->status,
                'error' => $setting?->error,
                'agent_outdated' => ! ($agent?->supports(ConfigureEbpf::FEATURE) ?? false),
                'probe' => is_array($probe) ? $probe : null,
                'can_manage' => $server !== null && $this->access->can($request->user(), $server->organizationId, self::MANAGE),
            ];
        }, $serverIds));
    }
}
