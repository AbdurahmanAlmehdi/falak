<?php

namespace Falak\Projects\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Falak\Projects\Application\Actions\GroupServices;
use Falak\Projects\Application\Actions\UngroupServices;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Http\Requests\ProjectRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Canvas groups (UI_DESIGN §4.3): frame services, rename, set an icon, move, collapse, ungroup. Layout only — nothing
 * here touches the sites or databases behind the cards.
 */
final class GroupController extends Controller
{
    use PresentsProjects;

    /**
     * POST /projects/{project}/{environment}/groups {name?, service_ids: [...]} → 201 {data: CanvasGroup}
     */
    public function store(Request $request, Project $project, string $environment, GroupServices $group): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $data = $request->validate([
            'name' => ['nullable', ...ProjectRules::NAME],
            'service_ids' => ['required', 'array', 'min:1', 'max:100'],
            'service_ids.*' => ['string', 'size:26'],
        ]);

        return response()->json(['data' => $group($model, (string) ($data['name'] ?? 'Group'), array_values($data['service_ids']))->toCanvas()], 201);
    }

    /**
     * PATCH /projects/{project}/{environment}/groups/{group} {name?, icon?, x?, y?, collapsed?}
     * Moving the group moves its services (their positions are relative to it).
     */
    public function update(Request $request, Project $project, string $environment, string $group): JsonResponse
    {
        $this->authorize('manage', $project);
        $record = $this->group($project, $environment, $group);

        $data = $request->validate([
            'name' => ['sometimes', 'required', ...ProjectRules::NAME],
            'x' => ['sometimes', 'required_with:y', ...ProjectRules::COORDINATE],
            'y' => ['sometimes', 'required_with:x', ...ProjectRules::COORDINATE],
            'collapsed' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', ...ProjectRules::ICON],
        ], ProjectRules::ICON_MESSAGES);

        if (array_key_exists('icon', $data)) {
            $data['icon'] = $data['icon'] ?: null;
        }

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        $record->forceFill($data)->save();

        return response()->json(['data' => $record->toCanvas()]);
    }

    /**
     * DELETE /projects/{project}/{environment}/groups/{group} — ungroup: the services stay where they are.
     */
    public function destroy(Project $project, string $environment, string $group, UngroupServices $ungroup): JsonResponse
    {
        $this->authorize('manage', $project);
        $ungroup($this->group($project, $environment, $group));

        return response()->json(null, 204);
    }

    private function group(Project $project, string $environment, string $group): Group
    {
        $model = $this->resolveEnvironment($project, $environment);

        return Group::query()->where('environment_id', $model->id)->find(strtolower($group)) ?? throw new NotFoundHttpException('Group not found.');
    }
}
