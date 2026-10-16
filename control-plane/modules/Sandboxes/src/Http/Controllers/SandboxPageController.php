<?php

namespace Falak\Sandboxes\Http\Controllers;

use Falak\Databases\Contracts\Data\DatabaseInstanceData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sandboxes\Application\Actions\CreateSandbox;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Falak\Sandboxes\Domain\Policies\SandboxPolicy;
use Falak\Sandboxes\Infrastructure\EloquentSandboxDirectory;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard's Sandboxes pages; their actions call the API routes under the session (routes/web.php).
 */
final class SandboxPageController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agents,
        private readonly DatabaseDirectory $databases,
    ) {}

    /** /sandboxes */
    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SandboxPolicy::VIEW);
        $servers = $this->servers->forOrganization($organizationId);
        $names = array_column(array_map(fn (ServerData $s) => ['id' => $s->id, 'name' => $s->name], $servers), 'name', 'id');
        $agents = $this->agents->forServers(array_keys($names));

        $sandboxes = Sandbox::query()->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where('status', '!=', Sandbox::KILLED)->orWhere('killed_at', '>', now()->subDay()))
            ->latest('created_at')->latest('id')->limit(500)->get();

        $sandboxes->load('fromSnapshot');
        $snapshots = SandboxSnapshot::query()->with('sandbox')->where('organization_id', $organizationId)->where('status', '!=', SandboxSnapshot::DELETED)
            ->latest('created_at')->latest('id')->limit(500)->get();

        return Inertia::render('Sandboxes/Index', [
            'sandboxes' => $sandboxes->map(fn (Sandbox $sandbox) => $sandbox->present($names[$sandbox->server_id] ?? null))->values(),
            'snapshots' => $snapshots->map(fn (SandboxSnapshot $snapshot) => [
                ...$snapshot->present($names[$snapshot->server_id] ?? null),
                'can_operate' => $request->user()?->can('operate', $snapshot) ?? false,
            ])->values(),
            'servers' => array_values(array_map(fn (ServerData $s) => ['id' => $s->id, 'name' => $s->name], array_filter(
                $servers,
                fn (ServerData $s) => $s->isActive() && $s->sandboxesEnabled && ($agents[$s->id] ?? null)?->supports(SandboxDirectory::FEATURE),
            ))),
            'limits' => [
                'max_running' => (int) config('sandboxes.max_running'),
                'max_ttl' => (int) config('sandboxes.max_ttl'),
                'default_ttl' => (int) config('sandboxes.default_ttl'),
                'max_cpus' => (float) config('sandboxes.max_cpus'),
                'max_memory_mb' => (int) config('sandboxes.max_memory_mb'),
                'max_disk_mb' => (int) config('sandboxes.max_disk_mb'),
                'max_fork_count' => CreateSandbox::MAX_FORK_COUNT,
            ],
            'can' => ['use' => $this->access->can($request->user(), $organizationId, SandboxPolicy::USE)],
        ]);
    }

    /** /sandboxes/{sandbox} */
    public function show(Request $request, string $sandbox): Response
    {
        $organizationId = $this->organization->requireId();
        $model = EloquentSandboxDirectory::lookup($organizationId, $sandbox);
        abort_if($model === null, 404);
        $this->authorize('view', $model);
        $user = $request->user();
        $serverName = $this->servers->find($model->server_id)?->name;
        $operate = $user?->can('operate', $model) ?? false;
        $db = $operate && $this->access->can($user, $organizationId, DatabaseDirectory::BACKUP_DOWNLOAD_PERMISSION);

        return Inertia::render('Sandboxes/Show', [
            'sandbox' => $model->present($serverName),
            'snapshots' => SandboxSnapshot::query()->with('sandbox')->where('sandbox_id', $model->id)->where('status', '!=', SandboxSnapshot::DELETED)
                ->latest('created_at')->latest('id')->limit(200)->get()
                ->map(fn (SandboxSnapshot $snapshot) => [...$snapshot->present($serverName), 'can_operate' => $user?->can('operate', $snapshot) ?? false])->values(),
            // The database copy dialog's choices: SQL databases of instances on the sandbox's server.
            'databases' => $db ? $this->databases($organizationId, $model->server_id) : [],
            'limits' => ['max_ttl' => (int) config('sandboxes.max_ttl'), 'default_ttl' => (int) config('sandboxes.default_ttl'), 'max_fork_count' => CreateSandbox::MAX_FORK_COUNT],
            'execs' => SandboxExec::query()->where('sandbox_id', $model->id)->latest('started_at')->latest('id')->limit(200)->get()
                ->map(fn (SandboxExec $exec) => $exec->present())->values(),
            'can' => [
                'update' => $user?->can('update', $model) ?? false,
                'operate' => $operate,
                'db' => $db,
                'kill' => $user?->can('kill', $model) ?? false,
            ],
        ]);
    }

    /**
     * @return list<array{instance: string, name: string, engine: string, databases: list<string>}>
     */
    private function databases(string $organizationId, string $serverId): array
    {
        $names = [];

        foreach ($this->databases->forServer($serverId) as $database) {
            if ($database->instanceId !== null && $database->organizationId === $organizationId) {
                $names[$database->instanceId][] = $database->name;
            }
        }

        $instances = array_filter(
            $this->databases->instancesForOrganization($organizationId),
            fn (DatabaseInstanceData $i) => $i->serverId === $serverId && in_array($i->engine, ['postgresql', 'mysql', 'mariadb'], true) && isset($names[$i->id]),
        );

        return array_values(array_map(fn (DatabaseInstanceData $i) => ['instance' => $i->id, 'name' => $i->name, 'engine' => $i->engine, 'databases' => $names[$i->id]], $instances));
    }
}
