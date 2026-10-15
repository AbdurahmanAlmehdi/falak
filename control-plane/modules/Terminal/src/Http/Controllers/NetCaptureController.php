<?php

namespace Falak\Terminal\Http\Controllers;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Terminal\Application\Actions\StartNetCapture;
use Falak\Terminal\Application\ConsoleTargets;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Models\NetCapture;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Infrastructure\NetCaptureStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Packet captures (docs/plans/network/capture.md) for the panel (session auth) and `falak net capture` (API token): start
 * one on a server, a site or a database container, follow it, cancel it, download the pcap while it's kept.
 * Everything needs `network.capture`; captures of other organizations are 404.
 */
final class NetCaptureController extends Controller
{
    public const PERMISSION = 'network.capture';

    public const FUNCTION_REFUSED = 'Functions run on demand; they have no network of their own to capture.';

    private const INPUT = ['interface', 'duration_s', 'max_mb', 'full_packets', 'filter', 'acknowledged'];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ConsoleTargets $targets,
        private readonly StartNetCapture $start,
        private readonly AuditLog $audit,
    ) {}

    /** POST servers/{server}/net-captures: 201 {data: Capture} */
    public function server(Request $request, string $server): JsonResponse
    {
        $organizationId = $this->guard($request);

        return $this->started($request, $organizationId, $this->targets->forServer($this->targets->server($organizationId, $server)));
    }

    /** POST sites/{site}/net-captures {server_id?, service?, …}: 201 */
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

    /** GET sites/{site}/net-captures: {data: {servers, services, runtime}} (the dialog's choices) */
    public function siteOptions(Request $request, string $site): JsonResponse
    {
        $organizationId = $this->guard($request);

        return response()->json(['data' => $this->targets->siteOptions($this->targets->site($request->user(), $organizationId, $site))]);
    }

    /** POST databases/instances/{instance}/net-captures: 201 */
    public function database(Request $request, string $instance): JsonResponse
    {
        $organizationId = $this->guard($request);

        return $this->started($request, $organizationId, $this->targets->forDatabase($this->targets->instance($request->user(), $organizationId, $instance)));
    }

    /** GET net-captures?target_type=&target_id=: the organization's last 10 captures of the target */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->guard($request);
        $data = $request->validate([
            'target_type' => ['required', 'in:server,site,database'],
            'target_id' => ['required', 'string', 'max:64'],
        ]);
        $column = $data['target_type'] === TerminalSession::TARGET_SERVER ? 'server_id' : 'target_id';

        $captures = NetCapture::query()
            ->where('organization_id', $organizationId)
            ->where('target_type', $data['target_type'])
            ->where($column, $data['target_id'])
            ->latest()->latest('id')->limit(10)->get();

        return response()->json(['data' => $captures->map(fn (NetCapture $capture) => $capture->toPanel())->values()]);
    }

    /** GET net-captures/{capture} */
    public function show(Request $request, string $capture): JsonResponse
    {
        return response()->json(['data' => $this->find($request, $capture)->toPanel()]);
    }

    /** POST net-captures/{capture}/cancel: the agent stops at its next progress report (or never gets the command). */
    public function cancel(Request $request, string $capture, AgentGateway $agents, NetCaptureStore $store): JsonResponse
    {
        $id = $this->find($request, $capture)->id;

        // Under the row lock the upload handler takes too: a chunk can't land between the check and the cancel, and two
        // cancels can't both audit.
        $model = DB::transaction(function () use ($id, $agents, $store) {
            $model = NetCapture::query()->lockForUpdate()->findOrFail($id);

            if (! $model->isActive() || $model->cancel_requested_at !== null) {
                return $model;
            }

            $model->forceFill(['cancel_requested_at' => now()])->save();

            if ($model->command_id !== null && $agents->cancel($model->command_id)) {
                $store->delete($model->id);
                $model->forceFill(['status' => NetCapture::CANCELLED, 'finished_at' => now()])->save();
            }

            $this->audit->record('network.capture_cancelled', 'net_capture', $model->id, [
                'target_name' => $model->target_name, 'server_name' => $model->server_name,
            ], $model->organization_id);

            return $model;
        });

        return response()->json(['data' => $model->toPanel()]);
    }

    /** GET net-captures/{capture}/download: the pcap, decrypted chunk by chunk as it streams. */
    public function download(Request $request, string $capture, NetCaptureStore $store): StreamedResponse
    {
        $model = $this->find($request, $capture);

        abort_if($model->status === NetCapture::EXPIRED || $model->expires_at->isPast(), 410, 'This capture expired; captures are kept 24 hours.');
        abort_unless($model->status === NetCapture::READY, 409, 'The capture is not ready.');
        // Never stream a file with a hole or a tail missing (a lost chunk on the disk): the response would look complete.
        abort_unless($store->complete($model), 410, 'The stored capture file is incomplete; start a new capture.');

        $this->audit->record('network.capture_downloaded', 'net_capture', $model->id, [
            'target_name' => $model->target_name, 'server_name' => $model->server_name, 'size_bytes' => $model->size_bytes, 'sha256' => $model->sha256,
        ], $model->organization_id);

        $name = 'falak-'.(Str::slug((string) $model->target_name) ?: 'capture').'-'.$model->created_at->format('Ymd-His').'.pcap';

        return response()->streamDownload(function () use ($store, $model) {
            $store->each($model, function (string $chunk) {
                echo $chunk;
                flush();
            });
        }, $name, [
            'Content-Type' => 'application/vnd.tcpdump.pcap',
            'Content-Length' => (string) $model->size_bytes,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function started(Request $request, string $organizationId, ConsoleTarget $target): JsonResponse
    {
        $capture = ($this->start)($organizationId, (string) $request->user()?->getAuthIdentifier(), $target, $request->only(self::INPUT));

        return response()->json(['data' => $capture->toPanel()], 201);
    }

    private function find(Request $request, string $id): NetCapture
    {
        $organizationId = $this->guard($request);

        return NetCapture::query()->where('organization_id', $organizationId)->findOrFail(strtolower($id));
    }

    /** The user may capture in the current organization; its id. */
    private function guard(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return $organizationId;
    }
}
