<?php

namespace Falak\Network\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Network\Application\Actions\BlockAddress;
use Falak\Network\Application\Actions\UnblockAddress;
use Falak\Network\Application\ApplyProtection;
use Falak\Network\Domain\Models\IpBlock;
use Falak\Network\Domain\Models\ProtectionState;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Network → Blocked IPs (docs/plans/network/protection.md): the organization's blocks with their hits, block / unblock
 * (also from the `network.ip-actions` slot on A's and B's rows) and the per-server auto-ban settings.
 */
final class ProtectionController extends Controller
{
    use ResolvesServers;

    public const PERMISSION = 'network.protect';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function index(Request $request, ApplyProtection $apply): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'network.view');

        $servers = collect($this->servers->forOrganization($organizationId))->keyBy('id');
        $blocks = IpBlock::query()->current()->where('organization_id', $organizationId)->orderByDesc('created_at')->limit(2000)->get();
        $hits = DB::table('network_ip_block_hits')->whereIn('block_id', $blocks->pluck('id'))
            ->selectRaw('block_id, sum(packets) as packets, sum(bytes) as bytes, max(last_hit_at) as last_hit_at')->groupBy('block_id')->get()->keyBy('block_id');
        $states = ProtectionState::query()->where('organization_id', $organizationId)->get()->keyBy('server_id');

        return Inertia::render('Network/Blocked', [
            'blocks' => $blocks->map(fn (IpBlock $block) => [
                'id' => $block->id,
                'cidr' => $block->cidr,
                'server' => $block->server_id !== null ? ['id' => $block->server_id, 'name' => $servers[$block->server_id]->name ?? $block->server_id] : null,
                'reason' => $block->reason,
                'source' => $block->source,
                'expires_at' => $block->expires_at?->toIso8601String(),
                'suspended_reason' => $block->suspended_reason,
                'created_at' => $block->created_at->toIso8601String(),
                'packets' => isset($hits[$block->id]) ? (int) $hits[$block->id]->packets : 0,
                'bytes' => isset($hits[$block->id]) ? (int) $hits[$block->id]->bytes : 0,
                'last_hit_at' => isset($hits[$block->id]->last_hit_at) ? (string) $hits[$block->id]->last_hit_at : null,
            ])->values(),
            'servers' => $servers->map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'supported' => $apply->supported($server->id),
                'status' => $states[$server->id]->status ?? null,
                'error' => $states[$server->id]->error ?? null,
                'failed' => $states[$server->id]->failed ?? [],
                'blocked_packets' => $states[$server->id]->blocked_packets ?? null,
                'auto_ban' => [
                    'enabled' => (bool) ($states[$server->id]->auto_ban ?? false),
                    'failures' => (int) ($states[$server->id]->failures ?? 10),
                    'window_s' => (int) ($states[$server->id]->window_s ?? 600),
                    'ban_s' => (int) ($states[$server->id]->ban_s ?? 3600),
                ],
            ])->values(),
            'yourIp' => $request->ip(),
            'can' => ['protect' => $this->access->can($request->user(), $organizationId, self::PERMISSION)],
        ]);
    }

    public function store(Request $request, BlockAddress $block, SiteDirectory $sites): JsonResponse|RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        abort_unless($this->access->can($request->user(), $organizationId, 'network.view'), 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        $data = $request->validate([
            'address' => ['required', 'string', 'max:43'],
            'scope' => ['required', Rule::in(['server', 'site', 'organization'])],
            'server_id' => ['required_if:scope,server', 'nullable', 'string', 'max:26'],
            'site_id' => ['required_if:scope,site', 'nullable', 'string', 'max:26'],
            'duration' => ['required', Rule::in(array_keys(BlockAddress::DURATIONS))],
            'reason' => ['nullable', 'string', 'max:500'],
            'confirm' => ['boolean'],
            // The row is a peer the server connects to (stream A's "talks to"): blocking it needs confirming.
            'outbound' => ['boolean'],
        ]);

        $serverIds = match ($data['scope']) {
            'organization' => null,
            'server' => [$this->orgServer($request, strtolower((string) $data['server_id']), $organizationId)->id],
            'site' => $this->siteServers($sites, strtolower((string) $data['site_id']), $organizationId),
        };

        $blocks = $block($organizationId, $serverIds, (string) $data['address'], (string) $data['duration'], $data['reason'] ?? null, (bool) ($data['confirm'] ?? false),
            $request->ip(), (string) $request->user()?->getAuthIdentifier(), array_filter(['site_id' => $data['site_id'] ?? null]), (bool) ($data['outbound'] ?? false));

        return $request->wantsJson() && ! $request->header('X-Inertia')
            ? response()->json(['blocks' => array_map(fn (IpBlock $b) => ['id' => $b->id, 'cidr' => $b->cidr, 'server_id' => $b->server_id], $blocks)], 201)
            : back();
    }

    public function destroy(Request $request, string $block, UnblockAddress $unblock): JsonResponse|RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $model = IpBlock::query()->where('organization_id', $organizationId)->whereKey($block)->first();
        abort_if($model === null || ! $this->access->can($request->user(), $organizationId, 'network.view'), 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        $unblock($model, (string) $request->user()?->getAuthIdentifier());

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(null, 204) : back();
    }

    public function settings(Request $request, string $server, ApplyProtection $apply, AuditLog $audit): RedirectResponse
    {
        $data = $this->orgServer($request, $server, $this->organization->requireId());

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'failures' => ['required', 'integer', 'min:3', 'max:1000'],
            'window_s' => ['required', 'integer', 'min:60', 'max:86400'],
            'ban_s' => ['required', 'integer', 'min:60', 'max:2592000'],
        ]);

        $state = ProtectionState::query()->find($data->id) ?? new ProtectionState(['server_id' => $data->id, 'organization_id' => $data->organizationId]);
        $state->forceFill(['auto_ban' => (bool) $validated['enabled'], 'failures' => $validated['failures'], 'window_s' => $validated['window_s'], 'ban_s' => $validated['ban_s']])->save();
        $audit->record('network.protection_updated', 'server', $data->id, ['auto_ban' => $validated], $data->organizationId);

        if ($validated['enabled'] && ! $apply->supported($data->id)) {
            return back()->with('error', 'Auto-ban needs a newer agent on this server; it starts once the agent is updated.');
        }

        $apply($data->id);

        return back();
    }

    /**
     * A server of the current organization the user may protect: 404 for any other (one of another organization the
     * user also belongs to included), 403 without network.protect.
     */
    private function orgServer(Request $request, string $serverId, string $organizationId): ServerData
    {
        $server = $this->server($request->user(), $serverId, self::PERMISSION);
        abort_if($server->organizationId !== $organizationId, 404);

        return $server;
    }

    /**
     * @return list<string>
     */
    private function siteServers(SiteDirectory $sites, string $siteId, string $organizationId): array
    {
        $site = $sites->find($siteId);
        abort_if($site === null || $site->organizationId !== $organizationId, 404);

        $ids = array_values(array_unique(array_map(fn ($target) => $target->serverId, $sites->targets($site->id))));
        abort_if($ids === [], 422, 'The site runs on no server.');

        return $ids;
    }
}
