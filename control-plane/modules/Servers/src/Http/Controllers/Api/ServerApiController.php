<?php

namespace Falak\Servers\Http\Controllers\Api;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Application\Actions\AddStackRuntimes;
use Falak\Servers\Application\Actions\CreateServer;
use Falak\Servers\Application\Actions\DeleteServer;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Http\Controllers\PresentsServers;
use Falak\Servers\Http\Requests\StoreServerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Public API (Sanctum tokens; abilities are permission names).
 */
final class ServerApiController extends Controller
{
    use PresentsServers;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly AgentDirectory $agents,
        private readonly AgentUpgrades $upgrades,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.view');

        $servers = Server::query()->with('phpVersions')->where('organization_id', $organizationId)->orderBy('name')->get();
        $agents = $this->agents->forServers($servers->pluck('id')->all());
        $versions = $this->upgrades->versionsFor($servers->pluck('id')->all());

        return response()->json(['data' => $servers->map(fn (Server $server) => $this->summary($server, $agents[$server->id] ?? null, $versions[$server->id] ?? null))->values()]);
    }

    public function show(Server $server): JsonResponse
    {
        $this->authorize('view', $server);
        $server->load('phpVersions');

        return response()->json(['data' => [
            ...$this->summary($server, $this->agents->forServer($server->id), $this->upgrades->versionsFor([$server->id])[$server->id] ?? null),
            'stack' => $server->stack->toArray(),
            'php_versions' => $server->phpVersions->map(fn ($php) => $this->phpVersion($php))->values(),
        ]]);
    }

    public function store(StoreServerRequest $request, CreateServer $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'servers.create');

        $server = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->validated());
        $server->load('phpVersions');

        return response()->json(['data' => [...$this->summary($server, null), 'install_command' => $server->isCustom() ? $server->install_command : null]], 201);
    }

    /**
     * POST /api/v1/servers/{server}/agent/upgrade — upgrade the server's agent to the build this control plane ships.
     */
    public function upgradeAgent(Request $request, Server $server, AuditLog $audit): JsonResponse
    {
        $this->authorize('view', $server);
        $this->access->authorize($request->user(), $server->organization_id, 'fleet.agents.manage');

        try {
            $upgrade = $this->upgrades->upgrade($server->id, $request->user()?->getAuthIdentifier());
        } catch (AgentUpgradeUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $audit->record('server.agent_upgrade', 'server', $server->id, ['to_version' => $upgrade->toVersion], $server->organization_id);

        return response()->json(['data' => $upgrade->toArray()], 202);
    }

    /**
     * POST /api/v1/servers/{server}/stack — add PHP and/or Node.js to a server created without them, then re-provision.
     */
    public function addRuntimes(Request $request, Server $server, AddStackRuntimes $add): JsonResponse
    {
        $this->authorize('update', $server);
        $data = $request->validate([
            'php' => ['nullable', 'array'],
            'php.runtime' => ['required_with:php', 'string', Rule::in([...Stack::PHP_RUNTIMES, Stack::PHP_CLI])],
            'php.version' => ['nullable', 'string', Rule::in((array) config('servers.php_versions'))],
            'node' => ['nullable', 'string', Rule::in(array_map('strval', array_keys((array) config('servers.node_versions'))))],
        ]);

        $commandId = $add($server, $data, $request->user()?->getAuthIdentifier());
        $server->refresh()->load('phpVersions');

        return response()->json(['data' => [
            'stack' => $server->stack->toArray(),
            'status' => $server->status->value,
            'status_message' => $server->status_message,
            'command_id' => $commandId ?: null,
        ]], 202);
    }

    public function destroy(Request $request, Server $server, DeleteServer $delete): Response
    {
        $this->authorize('delete', $server);
        $delete($server, destroyAtProvider: $request->boolean('destroy_at_provider', true));

        return response()->noContent(202);
    }
}
