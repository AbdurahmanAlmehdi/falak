<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Domain\Models\OrganizationSettings;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization settings → Compose: the compose policy (docs/COMPOSE_TEMPLATES.md §1.3).
 */
final class ComposePolicyController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function show(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        return Inertia::render('Sites/ComposePolicy', [
            'policy' => [
                'allow_privileged' => OrganizationSettings::for($organizationId)->allow_privileged_compose,
                'safe_capabilities' => array_values((array) config('sites.compose.safe_capabilities', [])),
                // Managed install: privileged compose can't be allowed (OrganizationSettings reads it as off).
                'locked' => (bool) config('controlplane.managed'),
            ],
            'compose_sites' => Site::query()->where('organization_id', $organizationId)->where('runtime', 'compose')->count(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'sites.compose.policy')],
        ]);
    }

    public function update(Request $request, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.compose.policy');
        $data = $request->validate(['allow_privileged' => ['required', 'boolean']]);

        if ($data['allow_privileged'] && config('controlplane.managed')) {
            throw ValidationException::withMessages(['allow_privileged' => 'Privileged compose files can\'t be allowed on this Falak.']);
        }

        $settings = OrganizationSettings::for($organizationId);
        $settings->forceFill(['allow_privileged_compose' => (bool) $data['allow_privileged']])->save();
        $audit->record('sites.compose_policy_updated', 'organization', $organizationId, ['allow_privileged' => (bool) $data['allow_privileged']], $organizationId);

        return back()->with('success', $data['allow_privileged'] ? 'Privileged compose files are allowed.' : 'Privileged compose files are blocked.');
    }
}
