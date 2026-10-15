<?php

namespace Falak\Functions\Http\Controllers;

use Falak\Functions\Application\Actions\CreateFunction;
use Falak\Functions\Application\Starters;
use Falak\Functions\FunctionsServiceProvider as Permissions;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\Data\DomainChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/{environment}/functions — the Create picker's Function form. GET lists the starters.
 * `deploy: false` (the canvas) creates a draft: nothing runs until Deploy in its panel.
 */
final class CreateFunctionController extends Controller
{
    public function starters(): JsonResponse
    {
        $runtimes = [];

        foreach ((array) config('functions.runtimes') as $key => $runtime) {
            $runtimes[] = ['key' => $key, 'label' => $runtime['label'], 'family' => $runtime['family'], 'language' => $runtime['language'], 'entrypoint' => $runtime['entrypoint']];
        }

        return response()->json(['data' => ['runtimes' => $runtimes, 'default_runtime' => config('functions.default_runtime'), 'starters' => Starters::list()]]);
    }

    public function store(Request $request, string $project, string $environment, CurrentOrganization $organization, OrganizationAccess $access, ProjectDirectory $projects, CreateFunction $create): JsonResponse
    {
        $organizationId = $organization->requireId();
        $user = $request->user();
        $access->authorize($user, $organizationId, Permissions::CREATE);
        $access->authorize($user, $organizationId, 'projects.manage');
        $access->authorize($user, $organizationId, 'sites.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'server_id' => ['required', 'string', 'size:26'],
            'starter' => ['nullable', Rule::in(array_keys(Starters::ALL))],
            'runtime' => ['nullable', Rule::in(array_keys((array) config('functions.runtimes')))],
            'domain' => ['nullable', DomainChoice::rule()],
            'position' => ['nullable', 'array'],
            'position.x' => ['nullable', 'integer', 'between:-1000000,1000000'],
            'position.y' => ['nullable', 'integer', 'between:-1000000,1000000'],
            // false: a draft, deployed later from its panel (the canvas). Omitted: deployed right away, as before.
            'deploy' => ['sometimes', 'boolean'],
        ], ['name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.']);

        $projectData = $projects->find(strtolower($project));
        abort_if($projectData === null || $projectData->organizationId !== $organizationId, 404, 'Project not found.');
        $env = null;

        foreach ($projects->environments($projectData->id) as $candidate) {
            if ($candidate->slug === strtolower($environment) || $candidate->id === strtolower($environment)) {
                $env = $candidate;
            }
        }

        abort_if($env === null, 404, 'Environment not found.');
        $canDeploy = $access->can($user, $organizationId, Permissions::DEPLOY) && $access->can($user, $organizationId, 'deployments.create');
        $deploy = (bool) ($data['deploy'] ?? true);

        $result = $create(
            $env,
            $user?->getAuthIdentifier(),
            $user?->name ?? null,
            $data['name'],
            strtolower($data['server_id']),
            $data['starter'] ?? 'hello',
            $data['domain'] ?? null,
            isset($data['position']['x']) ? (int) $data['position']['x'] : null,
            isset($data['position']['y']) ? (int) $data['position']['y'] : null,
            $deploy && $canDeploy,
            $data['runtime'] ?? (string) config('functions.default_runtime', 'bun'),
        );

        $warnings = $result['warnings'];
        if ($deploy && ! $canDeploy) {
            $warnings[] = 'You cannot start deployments; ask a developer to deploy it.';
        }

        return response()->json([
            'data' => [
                'site_id' => $result['site']->id,
                'name' => $result['site']->name,
                'deployment_id' => $result['deployment_id'],
                'panel_url' => $projects->serviceUrl(ServiceKind::Site, $result['site']->id, 'code'),
            ],
            'warnings' => $warnings,
        ], 201);
    }
}
