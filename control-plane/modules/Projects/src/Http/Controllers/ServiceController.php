<?php

namespace Falak\Projects\Http\Controllers;

use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Application\Actions\ArrangeCompose;
use Falak\Projects\Application\Actions\CreateService;
use Falak\Projects\Application\Actions\DeleteService;
use Falak\Projects\Application\Actions\MoveService;
use Falak\Projects\Application\Actions\RenameService;
use Falak\Projects\Application\Canvas\CanvasReadModel;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Projects\Http\Requests\ProjectRules;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Canvas services: the Create picker and card positions.
 */
final class ServiceController extends Controller
{
    use PresentsProjects;

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * POST /projects/{project}/{environment}/services
     * {kind: site, …site fields, x?, y?} | {kind: database, engine, server_id, name, x?, y?}
     * → 201 {data: CanvasService, warnings}
     */
    public function store(Request $request, Project $project, string $environment, CreateService $create, CanvasReadModel $canvas): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $base = $request->validate([
            'kind' => ['required', Rule::enum(ServiceKind::class)],
            'x' => ['nullable', ...ProjectRules::COORDINATE],
            'y' => ['nullable', ...ProjectRules::COORDINATE],
        ]);
        $kind = ServiceKind::from($base['kind']);

        if ($kind === ServiceKind::Site) {
            $this->access->authorize($request->user(), $project->organization_id, 'sites.create');
            $data = $request->except(['kind', 'x', 'y', 'project_id', 'environment_id']);
        } else {
            $this->access->authorize($request->user(), $project->organization_id, 'databases.manage');
            $data = $request->validate([
                'engine' => ['required', 'string', Rule::in(['postgresql', 'mysql', 'mariadb', 'redis', 'valkey'])],
                'server_id' => ['required', 'string', 'size:26'],
                'name' => ['required', 'string', 'max:63'],
                // The container (the picker's Advanced section): major version, memory limit, data volume size.
                'version' => ['nullable', 'string', 'max:16'],
                'memory_mb' => ['nullable', 'integer', 'min:16', 'max:262144'],
                'disk_gb' => ['nullable', 'integer', 'min:1', 'max:16384'],
                // Redis / Valkey
                'eviction' => ['nullable', 'string', 'max:32'],
                'persistence' => ['nullable', 'string', 'max:8'],
            ]);
        }

        $x = isset($base['x']) ? (int) $base['x'] : null;
        $y = isset($base['y']) ? (int) $base['y'] : null;
        $service = $create($model, $request->user()?->getAuthIdentifier(), $kind, $data, $x, $y);

        $card = collect($canvas->for($model)['services'])->firstWhere('id', $service->id);

        return response()->json(['data' => $card, 'warnings' => $create->warnings], 201);
    }

    /**
     * PATCH /projects/{project}/{environment}/services/{service} {name?, icon?} — rename the canvas service and/or set
     * its icon (a Cool Icons slug; null brings back the derived one). The icon is canvas-only: falak.yaml ignores it.
     */
    public function update(Request $request, Project $project, string $environment, string $service, RenameService $rename): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $data = $request->validate([
            // `icon: null` (back to the derived icon) is a request too: required_without would count it as missing.
            'name' => [Rule::requiredIf(! $request->has('icon')), 'string', 'max:60', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'icon' => ['sometimes', ...ProjectRules::ICON],
        ], ProjectRules::ICON_MESSAGES);

        $record = Service::query()->where('environment_id', $model->id)->find(strtolower($service)) ?? throw new NotFoundHttpException('Service not found.');

        if (isset($data['name'])) {
            // A service falak.yaml manages is named by its unit key: renaming it would make the file look for another one.
            if (trim((string) $data['name']) !== $record->name && ($managed = $this->managedUnit($record))->any()) {
                throw ValidationException::withMessages(['name' => $managed->badge().': the service is a unit of the file; rename it there.']);
            }

            $rename($record, (string) $data['name']);
        }

        if (array_key_exists('icon', $data)) {
            $record->forceFill(['icon' => $data['icon'] ?: null])->save();
        }

        return response()->json(['data' => ['id' => $record->id, 'name' => $record->name, 'icon' => $record->icon]]);
    }

    /**
     * DELETE /projects/{project}/{environment}/services/{service} {confirm: service name, delete_volumes?: volume ids}
     * — delete the site / database behind a card (§1.9: typed confirmation). Volumes are kept unless picked.
     */
    public function destroy(Request $request, Project $project, string $environment, string $service, DeleteService $delete): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);
        $record = Service::query()->where('environment_id', $model->id)->find(strtolower($service)) ?? throw new NotFoundHttpException('Service not found.');

        $this->access->authorize($request->user(), $project->organization_id, $record->kind === ServiceKind::Site ? 'sites.delete' : 'databases.manage');
        $data = $request->validate([
            'confirm' => ['required', 'string', Rule::in([$record->name])],
            'delete_volumes' => ['sometimes', 'array', 'max:100'],
            'delete_volumes.*' => ['string', 'size:26'],
        ], ['confirm.in' => 'Type the service name to confirm.']);

        // A database container falak.yaml manages is never deleted from here: its unit would plan a new, empty one.
        if ($record->kind === ServiceKind::Database && ($managed = $this->managedUnit($record))->any()) {
            throw ValidationException::withMessages(['confirm' => $managed->badge().': remove the unit from the file first (it is detached, never deleted on its own).']);
        }

        $delete($record, array_values($data['delete_volumes'] ?? []), $request->user()?->getAuthIdentifier());

        return response()->json(null, 204);
    }

    /**
     * PATCH /projects/{project}/{environment}/services/{service}/position {x, y, group_id?}
     * With `group_id` (a group id, or null to leave the group) the card moves into / out of a group; positions of
     * grouped cards are relative to their group's anchor.
     */
    public function position(Request $request, Project $project, string $environment, string $service, MoveService $move): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $data = $request->validate([
            'x' => ['required', ...ProjectRules::COORDINATE],
            'y' => ['required', ...ProjectRules::COORDINATE],
            'group_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $record = Service::query()->where('environment_id', $model->id)->find(strtolower($service)) ?? throw new NotFoundHttpException('Service not found.');
        $group = false;

        if (array_key_exists('group_id', $data)) {
            $group = $data['group_id'] !== null
                ? (Group::query()->where('environment_id', $model->id)->find(strtolower((string) $data['group_id'])) ?? throw ValidationException::withMessages(['group_id' => 'That group does not exist.']))
                : null;

            if ($group !== null && $record->kind === ServiceKind::Site && $this->sites->find($record->ref_id)?->compose !== null) {
                throw ValidationException::withMessages(['group_id' => 'Compose services are already grouped by their site.']);
            }
        }

        $move($record, (int) $data['x'], (int) $data['y'], $group);

        return response()->json(['data' => ['id' => $record->id, 'position' => ['x' => $record->x, 'y' => $record->y], 'group_id' => $record->group_id]]);
    }

    /**
     * PATCH /projects/{project}/{environment}/services/{service}/layout {children?: {name: {x, y}}, collapsed?}
     * A compose site's group: positions of its compose services (relative to the card) and collapsed state.
     */
    public function layout(Request $request, Project $project, string $environment, string $service, ArrangeCompose $arrange): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $data = $request->validate([
            'children' => ['sometimes', 'array', 'max:100'],
            'children.*.x' => ['required', ...ProjectRules::COORDINATE],
            'children.*.y' => ['required', ...ProjectRules::COORDINATE],
            'collapsed' => ['sometimes', 'boolean'],
        ]);

        $record = Service::query()->where('environment_id', $model->id)->find(strtolower($service)) ?? throw new NotFoundHttpException('Service not found.');
        /** @var array<string, array{x: int, y: int}> $children */
        $children = $data['children'] ?? [];
        $arrange($record, $children, isset($data['collapsed']) ? (bool) $data['collapsed'] : null);

        return response()->json(['data' => ['id' => $record->id, 'layout' => $record->layout]]);
    }

    /**
     * What falak.yaml manages of the service's resource: the site, or the database container its database lives in.
     */
    private function managedUnit(Service $record): ManagedFields
    {
        if ($record->kind === ServiceKind::Site) {
            return app(ManagedResources::class)->managed('site', $record->ref_id);
        }

        $instanceId = app(DatabaseDirectory::class)->find($record->ref_id)?->instanceId;

        return $instanceId !== null ? app(ManagedResources::class)->managed('database_instance', $instanceId) : ManagedFields::none();
    }
}
