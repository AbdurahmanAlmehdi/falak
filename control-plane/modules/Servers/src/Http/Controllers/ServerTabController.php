<?php

namespace Falak\Servers\Http\Controllers;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Application\Queries\ServerServices;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Servers-owned tabs of the server page (/servers/{id}/{tab}): Metrics, Processes, SSH keys, PHP and Settings.
 * Overview is ServerController::show; Firewall, Private network, Terminal and Recipes are rendered by their modules.
 */
final class ServerTabController extends Controller
{
    use PresentsServers;

    public function __construct(
        private readonly ServerHeaders $headers,
        private readonly OrganizationAccess $access,
    ) {}

    public function overview(Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        return to_route('servers.show', $server);
    }

    public function metrics(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('Servers/Tabs/Metrics', [
            'server' => $this->headers->for($server->id),
            'capacity' => ['cpus' => $server->cpus, 'memory_bytes' => $server->memory_bytes, 'disk_bytes' => $server->disk_bytes],
            'ranges' => array_keys(ServerController::METRIC_RANGES),
            'telemetry' => $this->access->can($request->user(), $server->organization_id, 'telemetry.view'),
        ]);
    }

    public function processes(Request $request, Server $server, ServerServices $services): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('Servers/Tabs/Processes', [
            'server' => $this->headers->for($server->id),
            'sites' => collect($services->forServer($server->id))
                ->where('kind', 'site')
                ->map(fn (array $site) => ['id' => $site['id'], 'name' => $site['name'], 'icon' => $site['icon'], 'url' => $site['url']])
                ->values(),
            'can' => [
                'view' => $this->access->can($request->user(), $server->organization_id, 'processes.view'),
                'manage' => $this->access->can($request->user(), $server->organization_id, 'processes.manage'),
            ],
        ]);
    }

    public function sshKeys(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);
        $server->load('sshKeys');

        return Inertia::render('Servers/Tabs/SshKeys', [
            'server' => $this->headers->for($server->id),
            'sshKeys' => $server->sshKeys->map(fn (SshKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'unix_user' => $key->getRelationValue('pivot')?->getAttribute('unix_user'),
                'attached_at' => $key->getRelationValue('pivot')?->getAttribute('created_at')?->toIso8601String(),
            ])->values(),
            'availableSshKeys' => SshKey::query()->where('organization_id', $server->organization_id)->orderBy('name')->get(['id', 'name', 'fingerprint']),
            'unixUser' => (string) config('servers.unix_user', 'falak'),
            'can' => [
                'update' => $request->user()?->can('update', $server) ?? false,
                'manageKeys' => $this->access->can($request->user(), $server->organization_id, 'ssh_keys.manage'),
            ],
        ]);
    }

    public function php(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);
        $server->load('phpVersions');

        return Inertia::render('Servers/Tabs/Php', [
            'server' => $this->headers->for($server->id),
            'runtime' => $server->stack->phpRuntime,
            'php' => $server->phpVersions->map(fn (PhpVersion $php) => $this->phpVersion($php))->values(),
            'phpOptions' => array_values(array_diff($server->installablePhpVersions(), $server->phpVersions->pluck('version')->all())),
            'can' => ['update' => $request->user()?->can('update', $server) ?? false],
        ]);
    }

    public function settings(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);
        $canManageAgents = $this->access->can($request->user(), $server->organization_id, 'fleet.agents.manage');

        return Inertia::render('Servers/Tabs/Settings', [
            'server' => [
                ...$this->headers->for($server->id),
                'timezone' => $server->timezone,
                'ssh_port' => $server->ssh_port,
                'provider_server_id' => $server->provider_server_id,
                'install_command' => $canManageAgents ? $server->install_command : null,
                'sandboxes_enabled' => (bool) $server->sandboxes_enabled,
            ],
            'can' => [
                'update' => $request->user()?->can('update', $server) ?? false,
                'delete' => $request->user()?->can('delete', $server) ?? false,
                'regenerateInstallCommand' => $canManageAgents,
            ],
        ]);
    }
}
