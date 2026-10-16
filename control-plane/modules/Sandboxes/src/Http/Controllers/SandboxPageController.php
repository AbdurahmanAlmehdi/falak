<?php

namespace Falak\Sandboxes\Http\Controllers;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
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

        return Inertia::render('Sandboxes/Index', [
            'sandboxes' => $sandboxes->map(fn (Sandbox $sandbox) => $sandbox->present($names[$sandbox->server_id] ?? null))->values(),
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

        return Inertia::render('Sandboxes/Show', [
            'sandbox' => $model->present($this->servers->find($model->server_id)?->name),
            'execs' => SandboxExec::query()->where('sandbox_id', $model->id)->latest('started_at')->latest('id')->limit(200)->get()
                ->map(fn (SandboxExec $exec) => $exec->present())->values(),
            'can' => [
                'update' => $user?->can('update', $model) ?? false,
                'operate' => $user?->can('operate', $model) ?? false,
                'kill' => $user?->can('kill', $model) ?? false,
            ],
        ]);
    }
}
