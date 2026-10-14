<?php

namespace Falak\Terminal\Http\Controllers;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Terminal\Application\Actions\RunNetTool;
use Falak\Terminal\Application\ConsoleTargets;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Models\NetToolRun;
use Falak\Terminal\Domain\Models\TerminalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Network tools (docs/plans/network/tools.md) for the panel (session auth) and `falak net` (API token): run one from a
 * server, a site or a database container, read a run with its output, list the user's last runs of a target.
 */
final class NetToolController extends Controller
{
    public const PERMISSION = 'network.tools';

    public const FUNCTION_REFUSED = 'Functions run on demand; they have no network of their own to test from.';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ConsoleTargets $targets,
        private readonly RunNetTool $run,
    ) {}

    /** POST servers/{server}/net-tools {tool, …}: 201 {data: Run} */
    public function server(Request $request, string $server): JsonResponse
    {
        $organizationId = $this->guard($request);

        return $this->started($request, $organizationId, $this->targets->forServer($this->targets->server($organizationId, $server)));
    }

    /** POST sites/{site}/net-tools {server_id?, service?, tool, …}: 201 */
    public function site(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate(['server_id' => ['nullable', 'string', 'max:255'], 'service' => ['nullable', 'string', 'max:255']]);
        $siteData = $this->targets->site($request->user(), $organizationId, $site);

        if ($siteData->runtime->isFunction()) {
            throw ValidationException::withMessages(['site' => self::FUNCTION_REFUSED]);
        }

        return $this->started($request, $organizationId, $this->targets->forSite($siteData, $data['server_id'] ?? null, $data['service'] ?? null));
    }

    /** GET sites/{site}/net-tools: {data: {servers, services, runtime}} (the dialog's choices) */
    public function siteOptions(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->guard($request);

        return response()->json(['data' => $this->targets->siteOptions($this->targets->site($request->user(), $organizationId, $site))]);
    }

    /** POST databases/instances/{instance}/net-tools {tool, …}: 201 */
    public function database(Request $request, string $instance): JsonResponse
    {
        $organizationId = $this->guard($request);

        return $this->started($request, $organizationId, $this->targets->forDatabase($this->targets->instance($request->user(), $organizationId, $instance)));
    }

    /** GET net-tools/runs?target_type=&target_id=: the user's last 10 runs of the target */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate([
            'target_type' => ['required', 'in:server,site,database'],
            'target_id' => ['required', 'string', 'max:64'],
        ]);
        $column = $data['target_type'] === TerminalSession::TARGET_SERVER ? 'server_id' : 'target_id';

        $runs = NetToolRun::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', (string) $request->user()?->getAuthIdentifier())
            ->where('target_type', $data['target_type'])
            ->where($column, $data['target_id'])
            ->latest()->latest('id')->limit(10)->get();

        return response()->json(['data' => $runs->map(fn (NetToolRun $run) => $run->toPanel())->values()]);
    }

    /** GET net-tools/runs/{run}?after=<seq>: the run with its output lines */
    public function show(Request $request, string $run, AgentGateway $agents): JsonResponse
    {
        $organizationId = $this->guard($request);
        $model = NetToolRun::query()->where('organization_id', $organizationId)->findOrFail($run);
        $after = (int) $request->query('after', '-1');
        $output = $model->command_id !== null ? $agents->output($model->command_id, $after) : null;

        return response()->json(['data' => [
            ...$model->toPanel(),
            'lines' => $output?->lines ?? [],
            'last_seq' => $output?->lastSeq ?? $after,
        ]]);
    }

    private function started(Request $request, string $organizationId, ConsoleTarget $target): JsonResponse
    {
        $run = ($this->run)($organizationId, (string) $request->user()?->getAuthIdentifier(), $target, $request->except(['server_id', 'service']));

        return response()->json(['data' => $run->toPanel()], 201);
    }

    /** The user may run network tools in the current organization; its id. */
    private function guard(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $organizationId;
    }
}
