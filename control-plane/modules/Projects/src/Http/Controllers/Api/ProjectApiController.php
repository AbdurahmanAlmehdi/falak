<?php

namespace Falak\Projects\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Application\Actions\CreateEnvironment;
use Falak\Projects\Application\Actions\CreateProject;
use Falak\Projects\Application\Actions\CreateService;
use Falak\Projects\Application\Actions\DeleteEnvironment;
use Falak\Projects\Application\Actions\DeleteProject;
use Falak\Projects\Application\Actions\RenameService;
use Falak\Projects\Application\Actions\UpdateEnvironment;
use Falak\Projects\Application\Actions\UpdateProject;
use Falak\Projects\Application\Canvas\CanvasReadModel;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Policies\ProjectPolicy;
use Falak\Projects\Http\Controllers\PresentsProjects;
use Falak\Projects\Http\Controllers\ServiceController;
use Falak\Projects\Http\Requests\ProjectRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public API v1 (Sanctum tokens; abilities are permission names): projects and their environments.
 * Projects outside the token's organization are reported as not found.
 */
final class ProjectApiController extends Controller
{
    use PresentsProjects;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, ProjectPolicy::VIEW);

        $projects = Project::query()->where('organization_id', $organizationId)->with('environments')->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $projects->map(fn (Project $project) => $this->project($project))->values()]);
    }

    public function store(Request $request, CreateProject $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, ProjectPolicy::MANAGE);

        $project = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->validate(ProjectRules::project(true), ProjectRules::messages()));

        return response()->json(['data' => $this->project($project)], 201);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        return response()->json(['data' => $this->project($this->resolve($request, $project, ProjectPolicy::VIEW))]);
    }

    public function update(Request $request, string $project, UpdateProject $update): JsonResponse
    {
        $model = $this->resolve($request, $project, ProjectPolicy::MANAGE);
        $update($model, $request->validate(ProjectRules::project(false), ProjectRules::messages()));

        return response()->json(['data' => $this->project($model->refresh())]);
    }

    public function destroy(Request $request, string $project, DeleteProject $delete): JsonResponse
    {
        $delete($this->resolve($request, $project, ProjectPolicy::MANAGE));

        return response()->json(null, 204);
    }

    public function environments(Request $request, string $project): JsonResponse
    {
        $model = $this->resolve($request, $project, ProjectPolicy::VIEW);

        return response()->json(['data' => $this->project($model)['environments']]);
    }

    public function storeEnvironment(Request $request, string $project, CreateEnvironment $create): JsonResponse
    {
        $model = $this->resolve($request, $project, ProjectPolicy::MANAGE);
        $data = $request->validate(ProjectRules::environment(true), ProjectRules::messages());
        $from = isset($data['from_environment_id']) ? $this->resolveEnvironment($model, (string) $data['from_environment_id']) : null;

        if ($from !== null && $from->services()->exists()) {
            $this->access->authorize($request->user(), $model->organization_id, 'sites.create');
        }

        $environment = $create($model, (string) $data['name'], $request->user()?->getAuthIdentifier(), $from);

        return response()->json(['data' => $this->environment($environment, $environment->services()->count()), 'warnings' => $create->warnings], 201);
    }

    public function updateEnvironment(Request $request, string $project, string $environment, UpdateEnvironment $update): JsonResponse
    {
        $model = $this->resolve($request, $project, ProjectPolicy::MANAGE);
        $env = $this->resolveEnvironment($model, $environment);
        $update($env, (string) $request->validate(ProjectRules::environment(false), ProjectRules::messages())['name']);

        return response()->json(['data' => $this->environment($env, $env->services()->count())]);
    }

    public function destroyEnvironment(Request $request, string $project, string $environment, DeleteEnvironment $delete): JsonResponse
    {
        $model = $this->resolve($request, $project, ProjectPolicy::MANAGE);
        $delete($this->resolveEnvironment($model, $environment));

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/projects/{project}/environments/{environment}/services: the canvas' Create (ServiceController),
     * with the project resolved like every API route (case-insensitive id, scoped to the token's organization).
     */
    public function storeService(Request $request, string $project, string $environment, ServiceController $services, CreateService $create, CanvasReadModel $canvas): JsonResponse
    {
        return $services->store($request, $this->resolve($request, $project, ProjectPolicy::MANAGE), $environment, $create, $canvas);
    }

    /**
     * PATCH /api/v1/projects/{project}/environments/{environment}/services/{service} {name?, icon?}: the canvas' rename
     * and icon picker (ServiceController).
     */
    public function updateService(Request $request, string $project, string $environment, string $service, ServiceController $services, RenameService $rename): JsonResponse
    {
        return $services->update($request, $this->resolve($request, $project, ProjectPolicy::MANAGE), $environment, $service, $rename);
    }

    private function resolve(Request $request, string $project, string $permission): Project
    {
        $organizationId = $this->organization->requireId();
        $model = Project::query()->where('organization_id', $organizationId)->find(strtolower($project));

        if ($model === null || ! $this->access->can($request->user(), $organizationId, ProjectPolicy::VIEW)) {
            throw new NotFoundHttpException('Project not found.');
        }

        $this->access->authorize($request->user(), $organizationId, $permission);

        return $model;
    }
}
