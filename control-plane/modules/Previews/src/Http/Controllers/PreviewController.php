<?php

namespace Falak\Previews\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Previews\Application\Actions\SavePreviewSettings;
use Falak\Previews\Application\PreviewLifecycle;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Previews\Domain\Policies\PreviewPolicy;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A project's Previews tab: its previews (status, URLs, credentials for members, age), their actions and settings.
 */
final class PreviewController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ProjectDirectory $projects,
    ) {}

    public function index(Request $request, string $project, PreviewDomains $domains, ServerDirectory $servers): Response
    {
        $data = $this->project($request, $project, PreviewPolicy::VIEW);
        $settings = PreviewSettings::query()->where('project_id', $data->id)->first();
        $environments = array_values(array_filter($this->projects->environments($data->id), fn ($e) => ! $e->isPreview));
        $base = $settings?->base_environment_id ?? ($environments[0]->id ?? null);
        $domain = $domains->settings();

        return Inertia::render('Previews/Index', [
            'project' => ['id' => $data->id, 'name' => $data->name],
            'previews' => Preview::query()->where('project_id', $data->id)->orderByRaw("status = 'closed'")->orderByDesc('created_at')->limit(100)->get()
                ->map(fn (Preview $preview) => $this->present($preview))->values(),
            'settings' => [
                'enabled' => (bool) $settings?->enabled,
                'base_environment_id' => $settings?->base_environment_id,
                'services' => (object) ($settings?->services ?? []),
                'server_id' => $settings?->server_id,
                'fork_server_id' => $settings?->fork_server_id,
                'variables' => $settings?->variables ?? [],
                'acknowledge_shared_database' => (bool) $settings?->acknowledge_shared_database,
                'domain_pattern' => $settings?->domain_pattern ?? 'pr-{number}-{service}',
                'databases' => (object) ($settings?->databases ?? []),
                'max_concurrent' => $settings?->max_concurrent ?? 5,
                'idle_ttl_hours' => $settings?->idle_ttl_hours ?? 72,
                'access' => $settings?->access ?? 'basic',
            ],
            'environments' => array_map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'is_production' => $e->isProduction], $environments),
            'services' => $base !== null ? array_map(fn ($s) => ['name' => $s->name, 'kind' => $s->kind->value], $this->projects->servicesIn($base)) : [],
            'servers' => array_map(fn ($s) => ['id' => $s->id, 'name' => $s->name], $servers->forOrganization($data->organizationId, activeOnly: true)),
            'preview_domain' => $domain !== null ? ['domain' => $domain->domain, 'status' => $domain->status] : null,
            'can' => ['manage' => $this->access->can($request->user(), $data->organizationId, PreviewPolicy::MANAGE)],
        ]);
    }

    public function settings(Request $request, string $project, SavePreviewSettings $save): RedirectResponse
    {
        $data = $this->project($request, $project, PreviewPolicy::MANAGE);
        $this->guardManaged($data->id, $request->all());
        $warnings = $save($data->id, $request->all(), $request->user()?->getAuthIdentifier());

        return back()->with('warnings', $warnings)->with('success', 'Preview settings saved.');
    }

    /**
     * Settings a falak.yaml's `previews:` block sets are refused (422) when the form changes them.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardManaged(string $projectId, array $input): void
    {
        $settings = PreviewSettings::query()->where('project_id', $projectId)->first();
        $managed = $settings !== null ? app(ManagedResources::class)->managed('preview_settings', $settings->id) : null;

        if ($settings === null || ! $managed->any()) {
            return;
        }

        $managed->guard([
            'enabled' => '/previews/enabled', 'base_environment_id' => '/previews/base', 'server_id' => '/previews/server', 'fork_server_id' => '/previews/fork_server',
            'variables' => '/previews/variables', 'domain_pattern' => '/previews/domain_pattern', 'max_concurrent' => '/previews/max_concurrent',
            'idle_ttl_hours' => '/previews/idle_ttl', 'access' => '/previews/access', 'acknowledge_shared_database' => '/previews/acknowledge_shared_database',
        ], $input, $settings->only(['enabled', 'base_environment_id', 'server_id', 'fork_server_id', 'variables', 'domain_pattern', 'max_concurrent', 'idle_ttl_hours', 'access', 'acknowledge_shared_database']));

        $unitPath = collect($managed->paths)->first(fn (string $p) => str_starts_with($p, '/previews/units/'));

        if ($unitPath !== null) {
            $sorted = function (array $map, bool $databases = false): array {
                $map = $databases ? array_map(fn ($e) => array_filter(array_intersect_key((array) $e, array_flip(['strategy', 'source_environment_id', 'sanitize_kind', 'sanitize_script'])), fn ($v) => $v !== null), $map) : $map;
                ksort($map);

                foreach ($map as $key => $entry) {
                    if (is_array($entry)) {
                        ksort($entry);
                        $map[$key] = $entry;
                    }
                }

                return $map;
            };
            $managed->guard(['services' => $unitPath, 'databases' => $unitPath], [
                ...(array_key_exists('services', $input) ? ['services' => $sorted((array) $input['services'])] : []),
                ...(array_key_exists('databases', $input) ? ['databases' => $sorted((array) $input['databases'], true)] : []),
            ], ['services' => $sorted((array) $settings->services), 'databases' => $sorted((array) $settings->databases, true)]);
        }
    }

    public function approve(Request $request, Preview $preview, PreviewLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('manage', $preview);
        // The head the member reviewed: refused when the pull request moved on since the page loaded.
        $data = $request->validate(['sha' => ['required', 'string', 'max:64']]);
        $lifecycle->approve($preview, (string) $request->user()?->getAuthIdentifier(), 'ui', $data['sha']);

        return back()->with('success', "Preview of #{$preview->number} approved.");
    }

    public function redeploy(Request $request, Preview $preview, PreviewLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('manage', $preview);
        $lifecycle->redeploy($preview, (string) $request->user()?->getAuthIdentifier());

        return back()->with('success', "Redeploying the preview of #{$preview->number}.");
    }

    public function destroy(Preview $preview, PreviewLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('manage', $preview);

        if ($preview->status !== Preview::CLOSED) {
            $lifecycle->destroy($preview, 'Deleted in Falak.');
        }

        return back()->with('success', "Preview of #{$preview->number} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Preview $preview): array
    {
        return [
            'id' => $preview->id,
            'number' => $preview->number,
            'title' => $preview->title,
            'url' => $preview->url,
            'author' => $preview->author,
            'repository' => $preview->repository,
            'head_branch' => $preview->head_branch,
            'head_sha' => $preview->head_sha,
            'is_fork' => $preview->is_fork,
            'status' => $preview->status,
            'status_message' => $preview->status_message,
            'urls' => (object) ($preview->urls ?? []),
            // Project members see the credentials here; the pull request comment never shows them.
            'credentials' => $preview->status !== Preview::CLOSED && $preview->basic_username !== null
                ? ['username' => $preview->basic_username, 'password' => $preview->basic_password]
                : null,
            'databases' => array_map(fn (array $entry) => ['strategy' => $entry['strategy'], 'state' => $entry['state']], $preview->databases ?? []),
            'environment_id' => $preview->environment_id,
            'approved_at' => $preview->approved_at?->toIso8601String(),
            'created_at' => $preview->created_at->toIso8601String(),
            'last_activity_at' => $preview->last_activity_at?->toIso8601String(),
            'closed_at' => $preview->closed_at?->toIso8601String(),
        ];
    }

    private function project(Request $request, string $project, string $permission): ProjectData
    {
        $organizationId = $this->organization->requireId();
        $data = $this->projects->find($project);

        if ($data === null || $data->organizationId !== $organizationId) {
            throw new NotFoundHttpException;
        }

        if (! $this->access->can($request->user(), $organizationId, PreviewPolicy::VIEW)) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($request->user(), $organizationId, $permission);

        return $data;
    }
}
