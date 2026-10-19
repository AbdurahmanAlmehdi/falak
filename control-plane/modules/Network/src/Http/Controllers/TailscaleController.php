<?php

namespace Falak\Network\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Network\Application\Tailscale;
use Falak\Network\Domain\Models\TailscaleNode;
use Falak\Network\Domain\Models\TailscaleSetting;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Tailscale (the auth key, setup steps, nodes) and a server's join / refresh / leave
 * (docs/plans/TAILSCALE.md). Viewing needs `network.view`, changing anything `network.tailscale` (Admin).
 */
final class TailscaleController extends Controller
{
    use ResolvesServers;

    private const MANAGE = 'network.tailscale';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly Tailscale $tailscale,
    ) {}

    public function show(Request $request, ServerDirectory $servers): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'network.view');
        $setting = TailscaleSetting::query()->find($organizationId);
        $names = collect($servers->forOrganization($organizationId))->mapWithKeys(fn ($s) => [$s->id => $s->name]);

        return Inertia::render('Network/Tailscale', [
            // Never the key itself: only that one is saved.
            'saved_key' => $setting !== null ? ['saved_at' => $setting->updated_at?->toIso8601String()] : null,
            'nodes' => TailscaleNode::query()->where('organization_id', $organizationId)->get()
                ->map(fn (TailscaleNode $node) => [...self::node($node), 'server' => ['id' => $node->server_id, 'name' => $names->get($node->server_id, $node->hostname)]])
                ->sortBy('server.name')->values(),
            'version' => (string) config('network.tailscale.version'),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, self::MANAGE)],
        ]);
    }

    public function saveKey(Request $request): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, self::MANAGE);
        $data = $request->validate(['auth_key' => ['required', 'string', 'starts_with:tskey-', 'max:500', 'regex:/^\S+$/']],
            ['auth_key.starts_with' => 'Paste an auth key (it starts with tskey-).']);

        $this->tailscale->saveKey($organizationId, $data['auth_key']);

        return back()->with('success', 'Auth key saved. Servers use it when they join the tailnet.');
    }

    public function removeKey(Request $request): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, self::MANAGE);
        $this->tailscale->removeKey($organizationId);

        return back()->with('success', 'Auth key removed.');
    }

    /** Join, or re-send the state of a joined server (refresh). */
    public function join(Request $request, string $server): RedirectResponse
    {
        $data = $this->server($request->user(), $server, self::MANAGE);
        $joined = TailscaleNode::query()->whereKey($data->id)->exists();
        $this->tailscale->join($data);

        return back()->with('success', $joined ? 'Refreshing the Tailscale status.' : "{$data->name} is joining the tailnet.");
    }

    public function leave(Request $request, string $server): RedirectResponse
    {
        $data = $this->server($request->user(), $server, self::MANAGE);
        $this->tailscale->leave(TailscaleNode::query()->findOrFail($data->id));

        return back()->with('success', "{$data->name} left the tailnet.");
    }

    /**
     * @return array<string, mixed>
     */
    public static function node(TailscaleNode $node): array
    {
        return [
            'status' => $node->status,
            'hostname' => $node->hostname,
            'ipv4' => $node->ipv4,
            'ipv6' => $node->ipv6,
            'routes_approved' => $node->routes_approved,
            'version' => $node->version,
            'error' => $node->error,
            'updated_at' => $node->updated_at?->toIso8601String(),
        ];
    }
}
