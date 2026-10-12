<?php

namespace Falak\Config\Http\Controllers;

use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Policies\ConfigPolicy;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unmanaged resources of a project's environments: what runs detached (gone from the file but kept) or released with
 * their environment. The dashboard deletes them through each module's own route (its permissions), then dismisses them.
 */
final class UnmanagedController extends Controller
{
    use ResolvesProjects;

    public function index(Request $request, string $project, ProjectDirectory $projects): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $api = $request->is('api/*');
        $input = $request->validate(['environment' => [$api ? 'required' : 'nullable', 'string', 'max:64']]);
        $environments = [];

        foreach ($projects->environments($data->id) as $environment) {
            $environments[$environment->id] = $environment->slug;
        }

        $query = ConfigDetached::query()->where('project_id', $data->id)->orderByDesc('detached_at')->orderByDesc('id');

        if (($input['environment'] ?? null) !== null) {
            $query->where('environment_id', $this->environment($data, $input['environment'])->id);
        }

        return response()->json(['data' => $query->limit(500)->get()->map(fn (ConfigDetached $row) => array_filter([
            'id' => $row->id,
            'environment' => $environments[$row->environment_id] ?? null,
            'unit' => $row->unit,
            'resource_type' => $row->resource_type,
            'resource_id' => $row->resource_id,
            'label' => $row->label,
            'reason' => $row->reason,
            'run_id' => $row->run_id,
            'detached_at' => $row->detached_at->toIso8601String(),
            // The dashboard's delete: the module's own route and what it needs to confirm.
            'delete_url' => $api ? false : $row->delete_url,
            'delete_body' => $api ? false : $row->delete_body,
        ], fn ($v) => $v !== false))->values()->all()]);
    }

    public function dismiss(Request $request, string $item): JsonResponse
    {
        $organizationId = app(CurrentOrganization::class)->requireId();
        app(OrganizationAccess::class)->authorize($request->user(), $organizationId, ConfigPolicy::VIEW);
        $row = ConfigDetached::query()->where('organization_id', $organizationId)->findOrFail(strtolower($item));
        app(OrganizationAccess::class)->authorize($request->user(), $organizationId, ConfigPolicy::APPLY);
        $row->delete();

        return response()->json(null, 204);
    }
}
