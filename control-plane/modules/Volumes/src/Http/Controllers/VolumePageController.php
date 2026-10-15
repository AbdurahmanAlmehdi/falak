<?php

namespace Falak\Volumes\Http\Controllers;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Data\StorageProviderData;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Volumes\Application\Jobs\RefreshVolumeUsage;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Policies\VolumePolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Volumes pages: a server's volumes (a server tab), a project's, and one volume (attachments, resize, backups,
 * clone / move, file browser, danger zone). Plus the JSON a service's settings use to attach volumes.
 */
final class VolumePageController extends Controller
{
    use PresentsVolumes;

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly CurrentOrganization $organization,
    ) {}

    public function server(Request $request, string $server, ServerHeaders $headers, SiteDirectory $sites): Response
    {
        $data = $this->resolveServer($request->user(), $server);
        $siteIds = array_map(fn ($site) => $site->id, $sites->forServer($data->id));

        $volumes = Volume::query()
            ->with('attachments')
            ->where('organization_id', $data->organizationId)
            ->where(fn (Builder $q) => $q->where('server_id', $data->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('server_id')->whereHas('attachments', fn ($q) => $q->whereIn('attachable_id', $siteIds))))
            ->orderBy('name')
            ->get();

        return Inertia::render('Volumes/Server', [
            'server' => $headers->for($data->id),
            'volumes' => $this->presentVolumes($volumes),
            ...$this->creation($request->user(), $data->organizationId, [$data]),
        ]);
    }

    public function project(Request $request, string $project, ProjectDirectory $projects): Response
    {
        $organizationId = $this->organization->requireId();
        $data = $projects->find($project);

        if ($data === null || $data->organizationId !== $organizationId) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($request->user(), $organizationId, VolumePolicy::VIEW);

        $environments = $projects->environments($data->id);
        /** @var list<ServiceData> $services */
        $services = array_merge(...array_map(fn ($environment) => $projects->servicesIn($environment->id), $environments) ?: [[]]);
        $siteIds = array_values(array_map(fn (ServiceData $s) => $s->refId, array_filter($services, fn (ServiceData $s) => $s->kind === ServiceKind::Site)));
        $databaseIds = array_values(array_map(fn (ServiceData $s) => $s->refId, array_filter($services, fn (ServiceData $s) => $s->kind === ServiceKind::Database)));

        $volumes = Volume::query()
            ->with('attachments')
            ->where('organization_id', $organizationId)
            ->whereHas('attachments', fn ($q) => $q->where(fn ($q) => $q->whereIn('attachable_type', [AttachableType::Site, AttachableType::ComposeService])->whereIn('attachable_id', $siteIds))
                ->orWhere(fn ($q) => $q->where('attachable_type', AttachableType::Database)->whereIn('attachable_id', $databaseIds)))
            ->orderBy('name')
            ->get();

        return Inertia::render('Volumes/Project', [
            'project' => ['id' => $data->id, 'name' => $data->name],
            'environments' => array_map(fn ($environment) => ['id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug], $environments),
            'volumes' => $this->presentVolumes($volumes),
            ...$this->creation($request->user(), $organizationId, app(ServerDirectory::class)->forOrganization($organizationId, activeOnly: true)),
        ]);
    }

    /** GET /volumes/{id}: the page, or (JSON) the same props for the canvas panel's volume layer. */
    public function show(Request $request, Volume $volume, BackupStorage $storage): Response|JsonResponse
    {
        $this->authorize('view', $volume);
        $volume->load(['attachments', 'schedules']);
        $organizationId = $volume->organization_id;

        $backups = VolumeBackup::query()
            ->where('organization_id', $organizationId)
            ->where('volume_id', $volume->id)
            ->latest()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $operations = Operation::query()->where('volume_id', $volume->id)->latest()->orderByDesc('id')->limit(20)->get();

        $props = [
            'volume' => $this->presentVolumes(collect([$volume]))[0],
            'backups' => $backups->map(fn (VolumeBackup $backup) => $this->presentBackup($backup))->values(),
            'schedules' => $volume->schedules->map(fn ($schedule) => $this->presentSchedule($schedule))->values(),
            'operations' => $operations->map(fn (Operation $operation) => $this->presentOperation($operation))->values(),
            'storage_providers' => array_map(fn (StorageProviderData $provider) => $provider->toArray(), $storage->providers($organizationId)),
            'servers' => array_values(array_map(fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name, 'docker' => $server->docker],
                app(ServerDirectory::class)->forOrganization($organizationId, activeOnly: true))),
            'attachable_sites' => $this->attachableSites($volume),
            // Database volumes are read through backups; a shared .env is one file.
            'browsable' => ! $volume->attachments->contains(fn ($attachment) => $attachment->attachable_type === AttachableType::Database)
                && ! ($volume->kind === VolumeKind::SharedPath && $volume->sharedFile()),
            'download_max_bytes' => (int) config('volumes.download_max_bytes'),
            'can' => [
                'manage' => $this->access->can($request->user(), $organizationId, VolumePolicy::MANAGE),
                'browse' => $this->access->can($request->user(), $organizationId, VolumePolicy::BROWSE),
            ],
        ];

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $props])
            : Inertia::render('Volumes/Show', $props);
    }

    /**
     * GET /sites/{site}/volumes (JSON): what a service's settings show — its volumes, and the volumes it could mount
     * (on its servers, unattached to it).
     */
    public function site(Request $request, string $site, SiteDirectory $sites): JsonResponse
    {
        $data = $sites->find(strtolower($site));

        if ($data === null || ! $this->access->can($request->user(), $data->organizationId, VolumePolicy::VIEW)) {
            throw new NotFoundHttpException;
        }

        $attached = Volume::query()->with('attachments')
            ->whereHas('attachments', fn ($q) => $q->whereIn('attachable_type', [AttachableType::Site, AttachableType::ComposeService])->where('attachable_id', $data->id))
            ->orderBy('name')->get();

        $available = $data->runtime === SiteRuntime::Docker ? Volume::query()->with('attachments')
            ->where('organization_id', $data->organizationId)
            ->whereIn('server_id', $data->serverIds())
            ->whereIn('kind', [VolumeKind::Docker, VolumeKind::Sized, VolumeKind::Bind])
            ->whereNull('options->compose')
            ->whereNotIn('id', $attached->modelKeys())
            ->orderBy('name')->get() : collect();

        return response()->json(['data' => [
            'attachable' => $data->runtime === SiteRuntime::Docker,
            'volumes' => $this->presentVolumes($attached),
            'available' => $this->presentVolumes($available),
            'can' => ['manage' => $this->access->can($request->user(), $data->organizationId, VolumePolicy::MANAGE)],
        ]]);
    }

    /** POST /servers/{server}/volumes/refresh: ask the server for its volumes' usage now. */
    public function refresh(Request $request, string $server): RedirectResponse|JsonResponse
    {
        $data = $this->resolveServer($request->user(), $server, VolumePolicy::MANAGE);
        RefreshVolumeUsage::dispatch($data->id);

        return $this->done($request);
    }

    /**
     * What the "New volume" dialog offers.
     *
     * @param  list<ServerData>  $servers
     * @return array<string, mixed>
     */
    private function creation(?Authenticatable $user, string $organizationId, array $servers): array
    {
        return [
            'servers' => array_values(array_map(fn (ServerData $server) => ['id' => $server->id, 'name' => $server->name, 'docker' => $server->docker], $servers)),
            'bind_allow' => $this->isAdmin($user, $organizationId) ? array_values((array) config('volumes.bind_allow', [])) : [],
            'limits' => ['min_size_bytes' => (int) config('volumes.min_size_bytes'), 'max_size_bytes' => (int) config('volumes.max_size_bytes')],
            'can' => [
                'manage' => $this->access->can($user, $organizationId, VolumePolicy::MANAGE),
                'browse' => $this->access->can($user, $organizationId, VolumePolicy::BROWSE),
            ],
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function attachableSites(Volume $volume): array
    {
        if ($volume->server_id === null || ! $volume->kind->mountable() || $volume->composeKey() !== null) {
            return [];
        }

        return collect($this->sitesOf($volume->organization_id))
            ->filter(fn ($site) => $site->runtime === SiteRuntime::Docker && $site->target((string) $volume->server_id) !== null)
            ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function isAdmin(?Authenticatable $user, string $organizationId): bool
    {
        $role = $user !== null ? $this->access->roleOf((string) $user->getAuthIdentifier(), $organizationId) : null;

        return $role === Role::Owner || $role === Role::Admin;
    }

    private function resolveServer(?Authenticatable $user, string $serverId, string $permission = VolumePolicy::VIEW): ServerData
    {
        $server = app(ServerDirectory::class)->find(strtolower($serverId));

        if ($server === null || ! $this->access->can($user, $server->organizationId, VolumePolicy::VIEW)) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, $server->organizationId, $permission);

        return $server;
    }
}
