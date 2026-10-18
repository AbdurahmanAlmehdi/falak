<?php

namespace Falak\Servers\Http\Controllers;

use Falak\ControlPlane\Contracts\HostChannel;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\Data\MetricSample;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Providers\Contracts\Data\CredentialSummary;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Providers\Contracts\ProviderType;
use Falak\Recovery\Contracts\OperatorOrganization;
use Falak\Servers\Application\Actions\ChangeServerTimezone;
use Falak\Servers\Application\Actions\CreateServer;
use Falak\Servers\Application\Actions\DeleteServer;
use Falak\Servers\Application\Actions\ProvisionServer;
use Falak\Servers\Application\Actions\RegenerateInstallCommand;
use Falak\Servers\Application\Actions\RenameServer;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Application\Queries\ServerServices;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Http\Requests\StoreServerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ServerController extends Controller
{
    use PresentsServers;

    public const METRIC_RANGES = ['1h' => 1, '6h' => 6, '24h' => 24];

    /** The ControlPlane module's operator permission: "This machine" is an instance-operator action. */
    private const CONTROL_PLANE_PERMISSION = 'controlplane.update';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentDirectory $agents,
        private readonly ServerServices $services,
        private readonly AgentUpgrades $upgrades,
        private readonly MachineChecks $checks,
        private readonly OperatorOrganization $operator,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ServerType::class)],
            'status' => ['nullable', Rule::enum(ServerStatus::class)],
        ]);

        $servers = Server::query()
            ->with('phpVersions')
            ->where('organization_id', $organizationId)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('ipv4', 'like', "%{$search}%")))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();

        $serverIds = $servers->pluck('id')->all();
        $agents = $this->agents->forServers($serverIds);
        $versions = $this->upgrades->versionsFor($serverIds);
        $services = $this->services->forServers($organizationId, $serverIds);

        return Inertia::render('Servers/Index', [
            'servers' => $servers->map(fn (Server $server) => [
                ...$this->summary($server, $agents[$server->id] ?? null, $versions[$server->id] ?? null),
                'services' => $services[$server->id] ?? [],
            ])->values(),
            // CPU / memory sparklines (last hour) load after the first paint.
            'sparklines' => Inertia::defer(fn () => $servers
                ->filter(fn (Server $server) => isset($agents[$server->id]))
                ->mapWithKeys(fn (Server $server) => [$server->id => $this->sparkline($server)])
                ->all()),
            'filters' => array_filter($filters),
            'types' => collect(ServerType::cases())->map(fn (ServerType $type) => ['value' => $type->value, 'label' => $type->label()]),
            'can' => [
                'create' => $this->access->can($request->user(), $organizationId, 'servers.create'),
                'upgrade_agents' => $this->access->can($request->user(), $organizationId, 'fleet.agents.manage'),
            ],
        ]);
    }

    public function create(Request $request, ProviderGateway $providers, HostChannel $host): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');

        return Inertia::render('Servers/Create', [
            'types' => collect(ServerType::cases())->map(fn (ServerType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'components' => $type->allowedComponents(),
                'defaults' => Stack::defaultsFor($type)->toArray(),
            ]),
            'providers' => collect(ProviderType::cases())->map(fn (ProviderType $type) => ['value' => $type->value, 'label' => $type->label(), 'has_api' => $type->hasApi()]),
            'credentials' => collect($providers->credentials($organizationId))->map(fn (CredentialSummary $credential) => [
                'id' => $credential->id,
                'name' => $credential->name,
                'provider' => $credential->provider->value,
            ])->values(),
            'options' => [
                'php_versions' => array_values((array) config('servers.php_versions')),
                'php_runtimes' => [['value' => 'frankenphp', 'label' => 'FrankenPHP'], ['value' => 'fpm', 'label' => 'PHP-FPM + Caddy']],
                'node_versions' => array_map('strval', array_keys((array) config('servers.node_versions'))),
            ],
            'sshKeys' => SshKey::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'fingerprint']),
            'canManageProviders' => $this->access->can($request->user(), $organizationId, 'providers.manage'),
            'thisMachine' => $this->thisMachineOffered($request, $organizationId, $host) ? ['reserve_mb' => (int) config('servers.control_plane_reserve_mb', 1536)] : null,
        ]);
    }

    public function store(StoreServerRequest $request, CreateServer $create, HostChannel $host): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');
        abort_if($request->boolean('control_plane_host') && ! $this->thisMachineOffered($request, $organizationId, $host), 403);

        $server = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->validated());

        return to_route('servers.show', $server);
    }

    public function show(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);
        $server->load('phpVersions');

        $agent = $this->agents->forServer($server->id);
        $canUpdate = $request->user()?->can('update', $server) ?? false;
        $awaitingAgent = in_array($server->status, [ServerStatus::Creating, ServerStatus::Error], true) && ! $agent;
        $canManageAgents = $this->access->can($request->user(), $server->organization_id, 'fleet.agents.manage');

        return Inertia::render('Servers/Show', [
            'server' => [
                ...$this->summary($server, $agent, $this->upgrades->versionsFor([$server->id])[$server->id] ?? null),
                'size' => $server->size,
                'image' => $server->image,
                'provider_server_id' => $server->provider_server_id,
                'ipv6' => $server->ipv6,
                'private_ipv4' => $server->private_ipv4,
                'ssh_port' => $server->ssh_port,
                'timezone' => $server->timezone,
                'stack' => $server->stack->toArray(),
                'os' => $server->os,
                'arch' => $server->arch,
                'cpus' => $server->cpus,
                'memory_bytes' => $server->memory_bytes,
                'disk_bytes' => $server->disk_bytes,
                'provision_command_id' => $server->provision_command_id,
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                // Without a live agent nobody can be displaced; otherwise only agent managers see (re)install commands.
                'install_command' => ($canUpdate && $awaitingAgent) || $canManageAgents ? $server->install_command : null,
                'can_regenerate_install_command' => $canManageAgents,
            ],
            'agent' => $agent ? [
                'id' => $agent->id,
                'status' => $agent->status->value,
                'version' => $agent->version,
                'hostname' => $agent->hostname,
                'enrolled_at' => $agent->enrolledAt->format(DATE_ATOM),
                'last_heartbeat_at' => $agent->lastHeartbeatAt?->format(DATE_ATOM),
                'certificate_expires_at' => $agent->certificateExpiresAt?->format(DATE_ATOM),
                'metrics' => $agent->metrics,
                'runtimes' => $agent->facts['runtimes'] ?? (object) [],
                'docker' => $agent->facts['docker'] ?? null,
                'kernel' => $agent->facts['kernel'] ?? null,
                'local_edge' => ($agent->facts['local_edge'] ?? false) === true,
            ] : null,
            'metrics' => $this->samples($server, '1h'),
            'machineCheck' => $this->machineCheck($server, $this->checks),
            'services' => $this->services->forServer($server->id),
            'can' => [
                'update' => $canUpdate,
                'delete' => $request->user()?->can('delete', $server) ?? false,
                'upgrade_agent' => $canManageAgents,
            ],
        ]);
    }

    public function update(Request $request, Server $server, RenameServer $rename, ChangeServerTimezone $changeTimezone): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'name' => ['required_without_all:timezone,sandboxes_enabled', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('servers_servers')->where('organization_id', $server->organization_id)->ignore($server->id)],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'sandboxes_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('sandboxes_enabled', $data)) {
            $server->forceFill(['sandboxes_enabled' => (bool) $data['sandboxes_enabled']])->save();
        }

        if (isset($data['name']) && $data['name'] !== $server->name) {
            $rename($server, $data['name']);
        }

        if (isset($data['timezone']) && $changeTimezone($server, $data['timezone'])) {
            return back()->with('success', 'Timezone saved — applying it to the server.');
        }

        return back()->with('success', 'Server settings saved.');
    }

    /**
     * Re-provision / Retry provisioning: the machine check first (agents with provision.v2), then the plan.
     */
    public function reprovision(Request $request, Server $server, ProvisionServer $provision): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_if($server->status === ServerStatus::Deleting, 422, 'The server is being deleted.');
        $provision($server, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function installCommand(Request $request, Server $server, RegenerateInstallCommand $regenerate): RedirectResponse
    {
        $this->authorize('update', $server);

        // A fresh install token re-binds the server to whichever host runs it (the current agent is
        // revoked on enrollment), so it needs the agent-management permission, not just servers.manage.
        $this->access->authorize($request->user(), $server->organization_id, 'fleet.agents.manage');

        abort_if($server->status === ServerStatus::Deleting, 422, 'The server is being deleted.');
        $regenerate($server);

        return back();
    }

    public function destroy(Request $request, Server $server, DeleteServer $delete): RedirectResponse
    {
        $this->authorize('delete', $server);

        $request->validate(['name' => ['required', 'string', Rule::in([$server->name])]], ['name.in' => 'Type the server name to confirm.']);

        $delete($server, destroyAtProvider: $request->boolean('destroy_at_provider', true));

        return to_route('servers.index');
    }

    /**
     * JSON samples for XHR callers (Accept: application/json); otherwise the Metrics tab.
     */
    public function metrics(Request $request, Server $server): JsonResponse|Response
    {
        $this->authorize('view', $server);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::METRIC_RANGES))]])['range'] ?? '1h';

        if (! $request->wantsJson()) {
            return app(ServerTabController::class)->metrics($request, $server);
        }

        return response()->json(['data' => $this->samples($server, $range)]);
    }

    public function search(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();

        if (! $this->access->can($request->user(), $organizationId, 'servers.view')) {
            return response()->json(['data' => []]);
        }

        $query = (string) $request->string('q');

        return response()->json([
            'data' => Server::query()
                ->where('organization_id', $organizationId)
                ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('ipv4', 'like', "%{$query}%")))
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'ipv4', 'type', 'status'])
                ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name, 'ipv4' => $server->ipv4, 'type' => $server->type->value, 'status' => $server->status->value]),
        ]);
    }

    /**
     * "This machine" in Add server: the operator organization's owners and admins, once the host channel exists
     * (falak-ctl ≥ v0.23) and while the control plane's machine is not a server yet.
     */
    private function thisMachineOffered(Request $request, string $organizationId, HostChannel $host): bool
    {
        return $organizationId === $this->operator->id()
            && $this->access->can($request->user(), $organizationId, self::CONTROL_PLANE_PERMISSION)
            && $host->available()
            && ! Server::query()->where('control_plane_host', true)->exists();
    }

    /**
     * Last hour of CPU / memory percentages, downsampled to at most 30 points.
     *
     * @return list<array{t: string, cpu: ?float, mem: ?float}>
     */
    private function sparkline(Server $server): array
    {
        $samples = $this->agents->metrics($server->id, now()->subHour());
        $step = max(1, (int) ceil(count($samples) / 30));
        $points = [];

        foreach ($samples as $index => $sample) {
            if ($index % $step !== 0 && $index !== count($samples) - 1) {
                continue;
            }

            $points[] = [
                't' => $sample->at->format(DATE_ATOM),
                'cpu' => $sample->cpuPercent !== null ? round($sample->cpuPercent, 1) : null,
                'mem' => $server->memory_bytes ? round($sample->memoryUsedBytes / $server->memory_bytes * 100, 1) : null,
            ];
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samples(Server $server, string $range): array
    {
        return array_map(
            fn (MetricSample $sample) => $sample->toArray(),
            $this->agents->metrics($server->id, now()->subHours(self::METRIC_RANGES[$range])),
        );
    }
}
