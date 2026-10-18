<?php

namespace Falak\Recovery\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Recovery\Application\SiteMovePlanner;
use Falak\Recovery\Application\SiteMoveRunner;
use Falak\Recovery\Domain\Models\SiteMove;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Move to another server": a site's dry run and move, its progress, retry and cancel; a server's "Move all sites".
 * Same permission as changing a site's servers (sites.manage).
 */
final class SiteMoveController extends Controller
{
    public const PERMISSION = 'sites.manage';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
    ) {}

    /** GET /sites/{site}/move: the latest move of the site (null: none) and the servers it could go to. */
    public function show(Request $request, string $site): JsonResponse
    {
        $data = $this->site($request, $site);
        $move = SiteMove::query()->where('site_id', $data->id)->latest()->orderByDesc('id')->first();

        return response()->json(['data' => [
            'move' => $move !== null ? self::resource($move) : null,
            'candidates' => $this->candidates($data->organizationId, array_map(fn ($t) => $t->serverId, $data->targets)),
        ]]);
    }

    /** POST /sites/{site}/move/plan {server_id?, storage_provider_id?}: the dry run, nothing changes. */
    public function plan(Request $request, string $site, SiteMovePlanner $planner): JsonResponse
    {
        $data = $this->site($request, $site);
        $input = $request->validate(self::rules(false));

        return response()->json(['data' => $planner->plan($data, $input['server_id'] ?? null, $input['from_server_id'] ?? null, $input['storage_provider_id'] ?? null)]);
    }

    /** POST /sites/{site}/move {server_id, storage_provider_id?, from_server_id?}: start. */
    public function store(Request $request, string $site, SiteMoveRunner $runner): JsonResponse
    {
        $data = $this->site($request, $site);
        $input = $request->validate(self::rules(true));
        $move = $runner->start($data, $this->target($data->organizationId, $input['server_id']), $input['from_server_id'] ?? null, $input['storage_provider_id'] ?? null, $this->actor($request));

        return response()->json(['data' => self::resource($move)], 201);
    }

    /** GET /site-moves/{move}: progress (the dialog polls it). */
    public function progress(Request $request, string $move): JsonResponse
    {
        return response()->json(['data' => self::resource($this->move($request, $move))]);
    }

    /** POST /site-moves/{move}/steps/{step}/retry */
    public function retry(Request $request, string $move, string $step, SiteMoveRunner $runner): JsonResponse
    {
        $model = $this->move($request, $move);
        abort_unless(array_key_exists($step, SiteMove::STEPS), 404);
        $runner->retry($model, $step);

        return response()->json(['data' => self::resource($model->refresh())]);
    }

    /** POST /site-moves/{move}/cancel */
    public function cancel(Request $request, string $move, SiteMoveRunner $runner): JsonResponse
    {
        $model = $this->move($request, $move);
        $runner->cancel($model);

        return response()->json(['data' => self::resource($model->refresh())]);
    }

    /** GET /servers/{server}/site-moves: the moves off this server (latest first) and where its sites could go. */
    public function forServer(Request $request, string $server): JsonResponse
    {
        $from = $this->server($request, $server);

        return response()->json(['data' => [
            'sites' => count($this->sites->forServer($from->id)),
            'moves' => SiteMove::query()->where('from_server_id', $from->id)->latest()->orderByDesc('id')->limit(50)->get()->map(fn (SiteMove $move) => self::resource($move, false))->values(),
            'candidates' => $this->candidates($from->organizationId, [$from->id]),
        ]]);
    }

    /** POST /servers/{server}/move-sites {server_id, storage_provider_id?}: every site moves, one after another. */
    public function moveAll(Request $request, string $server, SiteMoveRunner $runner): JsonResponse
    {
        $from = $this->server($request, $server);
        $input = $request->validate(self::rules(true));
        $moves = $runner->startAll($from, $this->target($from->organizationId, $input['server_id']), $input['storage_provider_id'] ?? null, $this->actor($request));

        return response()->json(['data' => array_map(fn (SiteMove $move) => self::resource($move->refresh(), false), $moves)], 201);
    }

    /** @return array<string, list<string>> */
    private static function rules(bool $start): array
    {
        return [
            'server_id' => [$start ? 'required' : 'nullable', 'string', 'size:26'],
            'from_server_id' => ['nullable', 'string', 'size:26'],
            'storage_provider_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @param  list<string>  $exclude
     * @return list<array<string, mixed>>
     */
    private function candidates(string $organizationId, array $exclude): array
    {
        return collect($this->servers->forOrganization($organizationId))
            ->reject(fn (ServerData $server) => in_array($server->id, $exclude, true) || ! $server->type->hostsSites())
            ->map(fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name, 'status' => $server->status->value, 'status_label' => $server->status->label(), 'ipv4' => $server->ipv4, 'docker' => $server->docker])
            ->values()->all();
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    private function target(string $organizationId, string $serverId): ServerData
    {
        $target = $this->servers->find(strtolower($serverId));
        abort_if($target === null || $target->organizationId !== $organizationId, 422, 'Choose a server of this organization.');

        return $target;
    }

    private function site(Request $request, string $id): SiteData
    {
        $organizationId = $this->organization->requireId();
        $site = $this->sites->find(strtolower($id));

        // Other organizations' sites don't exist.
        abort_if($site === null || $site->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $site;
    }

    private function server(Request $request, string $id): ServerData
    {
        $organizationId = $this->organization->requireId();
        $server = $this->servers->find(strtolower($id));
        abort_if($server === null || $server->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $server;
    }

    private function move(Request $request, string $id): SiteMove
    {
        $organizationId = $this->organization->requireId();
        $move = SiteMove::query()->where('organization_id', $organizationId)->find(strtolower($id));
        abort_if($move === null, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $move;
    }

    /** @return array<string, mixed> */
    private static function resource(SiteMove $move, bool $steps = true): array
    {
        return [
            'id' => $move->id,
            'site' => ['id' => $move->site_id, 'name' => $move->site_name],
            'from_server' => ['id' => $move->from_server_id, 'name' => $move->from_server_name],
            'target_server' => ['id' => $move->target_server_id, 'name' => $move->target_server_name],
            'batch_id' => $move->batch_id,
            'status' => $move->status,
            'current_step' => $move->current_step,
            'cancellable' => in_array($move->status, ['queued', 'failed'], true) && ! $move->switched() && ! $move->movedData(),
            ...($steps ? ['steps' => array_map(fn (array $step) => [
                ...$step,
                'items' => array_map(fn (array $item) => array_intersect_key($item, array_flip(['id', 'label', 'state', 'message', 'phase', 'kind'])), $step['items']),
            ], $move->steps)] : []),
            'created_at' => $move->created_at->toIso8601String(),
            'finished_at' => $move->finished_at?->toIso8601String(),
        ];
    }
}
