<?php

namespace Falak\Templates\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Templates\Application\Actions\DeleteCustomTemplate;
use Falak\Templates\Application\Actions\DraftTemplateFromSite;
use Falak\Templates\Application\Actions\FetchTemplate;
use Falak\Templates\Application\Actions\SaveCustomTemplate;
use Falak\Templates\Application\Catalog\CatalogLocation;
use Falak\Templates\Application\Catalog\TemplateRepository;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Domain\Models\CustomTemplateRevision;
use Falak\Templates\TemplatesServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Templates: the organization's own templates (import by paste / upload / URL, edit, delete) and
 * "Save as template" drafts from compose sites.
 */
final class CustomTemplateController extends Controller
{
    use PresentsTemplates;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly TemplateRepository $templates,
        private readonly CatalogLocation $location,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->authorizeFor($request, TemplatesServiceProvider::VIEW);

        $rows = CustomTemplate::query()->where('organization_id', $organizationId)->orderBy('name')->get();

        return Inertia::render('Templates/Settings', [
            'templates' => $rows->map(fn (CustomTemplate $row) => $this->row($row))->values(),
            'categories' => $this->categories(),
            'catalog' => Arr::except($this->location->current(), 'path'),
            'limits' => ['max_kb' => intdiv((int) config('templates.max_bytes', 262144), 1024)],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, TemplatesServiceProvider::MANAGE)],
        ]);
    }

    /**
     * GET /settings/templates/{template} — files + revision history for the editor.
     */
    public function show(Request $request, string $template): JsonResponse
    {
        $row = $this->find($request, $template, TemplatesServiceProvider::VIEW);

        return response()->json(['data' => [
            ...$this->row($row),
            'template_yaml' => $row->template_yaml,
            'compose_yaml' => $row->compose_yaml,
            'revisions' => $row->revisions()->limit(20)->get()->map(fn (CustomTemplateRevision $revision) => [
                'revision' => $revision->revision,
                'version' => $revision->version,
                'created_at' => $revision->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * POST /settings/templates {template_yaml, compose_yaml?} — import (compose_yaml omitted: a bundle).
     */
    public function store(Request $request, SaveCustomTemplate $save): JsonResponse
    {
        $organizationId = $this->authorizeFor($request, TemplatesServiceProvider::MANAGE);
        $data = $this->files($request);

        $row = $save($organizationId, $request->user()?->getAuthIdentifier(), $data['template_yaml'], $data['compose_yaml']);

        return response()->json(['data' => $this->row($row)], 201);
    }

    /**
     * POST /settings/templates/preview — validate without saving (import dialog preview).
     */
    public function preview(Request $request, SaveCustomTemplate $save): JsonResponse
    {
        $organizationId = $this->authorizeFor($request, TemplatesServiceProvider::MANAGE);
        $data = $this->files($request);
        $existing = $request->filled('id') ? $this->find($request, (string) $request->input('id'), TemplatesServiceProvider::MANAGE) : null;

        return response()->json(['data' => $this->summary($save->validate($organizationId, $data['template_yaml'], $data['compose_yaml'], $existing))]);
    }

    /**
     * POST /settings/templates/fetch {url} — server-side fetch (SSRF-guarded) for Import from URL.
     */
    public function fetch(Request $request, FetchTemplate $fetch): JsonResponse
    {
        $this->authorizeFor($request, TemplatesServiceProvider::MANAGE);
        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'starts_with:https://']], ['url.starts_with' => 'Only https:// URLs can be imported.']);

        return response()->json(['data' => $fetch((string) $data['url'])]);
    }

    public function update(Request $request, string $template, SaveCustomTemplate $save): JsonResponse
    {
        $row = $this->find($request, $template, TemplatesServiceProvider::MANAGE);
        $data = $this->files($request);

        $row = $save($row->organization_id, $request->user()?->getAuthIdentifier(), $data['template_yaml'], $data['compose_yaml'], $row);

        return response()->json(['data' => $this->row($row)]);
    }

    public function destroy(Request $request, string $template, DeleteCustomTemplate $delete): JsonResponse
    {
        $row = $this->find($request, $template, TemplatesServiceProvider::MANAGE);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$row->name])]], ['confirm.in' => 'Type the template name to confirm.']);

        $delete($row, $request->user()?->getAuthIdentifier());

        return response()->json(null, 204);
    }

    /**
     * POST /settings/templates/from-site/{site} — a template draft from a compose site ("Save as template").
     */
    public function fromSite(Request $request, string $site, SiteDirectory $sites, DraftTemplateFromSite $draft): JsonResponse
    {
        $organizationId = $this->authorizeFor($request, TemplatesServiceProvider::MANAGE);
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        $data = $sites->find(strtolower($site));
        abort_if($data === null || $data->organizationId !== $organizationId, 404, 'Site not found.');

        return response()->json(['data' => $draft($data)]);
    }

    /**
     * @return array{template_yaml: string, compose_yaml: ?string}
     */
    private function files(Request $request): array
    {
        $max = (int) config('templates.max_bytes', 262144);
        $data = $request->validate([
            'template_yaml' => ['required', 'string', 'max:'.$max],
            'compose_yaml' => ['nullable', 'string', 'max:'.$max],
        ]);

        return ['template_yaml' => (string) $data['template_yaml'], 'compose_yaml' => isset($data['compose_yaml']) && trim((string) $data['compose_yaml']) !== '' ? (string) $data['compose_yaml'] : null];
    }

    private function authorizeFor(Request $request, string $permission): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, $permission);

        return $organizationId;
    }

    private function find(Request $request, string $id, string $permission): CustomTemplate
    {
        $organizationId = $this->authorizeFor($request, $permission);

        return CustomTemplate::query()->where('organization_id', $organizationId)->find(strtolower($id)) ?? abort(404, 'Template not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomTemplate $row): array
    {
        $template = $this->templates->fromModel($row);

        return [
            'id' => $row->id,
            'slug' => $row->slug,
            'name' => $row->name,
            'version' => $row->version,
            'revision' => $row->revision,
            'description' => $row->description,
            'category' => $row->category,
            'summary' => $template !== null ? $this->summary($template) : null,
            'updated_at' => $row->updated_at->toIso8601String(),
        ];
    }
}
