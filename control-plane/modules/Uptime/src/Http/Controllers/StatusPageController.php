<?php

namespace Falak\Uptime\Http\Controllers;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Uptime\Application\StatusPageView;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Models\StatusPage;
use Falak\Uptime\Domain\Policies\UptimePolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class StatusPageController extends Controller
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly CurrentOrganization $organization,
        private readonly ProjectDirectory $projects,
        private readonly AuditLog $audit,
    ) {}

    /** /observability/uptime/status-pages. */
    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::VIEW);

        $pages = StatusPage::query()->where('organization_id', $organizationId)->with('monitors')->get()->keyBy('project_id');

        return Inertia::render('Uptime/StatusPages', [
            'projects' => array_map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'page' => ($page = $pages->get($project->id)) ? [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'description' => $page->description,
                    'enabled' => $page->enabled,
                    'url' => url("/status/{$page->slug}"),
                    'monitors' => $page->monitors->map(fn (Monitor $m) => ['id' => $m->id, 'display_name' => $m->pivot->getAttribute('display_name')])->values(),
                ] : null,
            ], $this->projects->forOrganization($organizationId)),
            'monitors' => Monitor::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'site_id'])
                ->map(fn (Monitor $m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'can' => ['publish' => $this->access->can($request->user(), $organizationId, UptimePolicy::PUBLISH)],
        ]);
    }

    public function save(Request $request, string $projectId): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, UptimePolicy::PUBLISH);
        $project = $this->projects->find($projectId);
        abort_if($project === null || $project->organizationId !== $organizationId, 404);

        $page = StatusPage::query()->where('project_id', $project->id)->first();
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:'.StatusPage::SLUG],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['required', 'boolean'],
            'monitors' => ['present', 'array', 'max:50'],
            'monitors.*.id' => ['required', 'string', 'distinct', Rule::exists('uptime_monitors', 'id')->where('organization_id', $organizationId)],
            'monitors.*.display_name' => ['required', 'string', 'max:120'],
        ]);

        // Taken and reserved addresses get the same answer: whether another organization uses one is not revealed.
        $unavailable = ValidationException::withMessages(['slug' => 'This address is not available.']);

        if (StatusPage::isReserved($data['slug'])
            || StatusPage::query()->where('slug', $data['slug'])->when($page, fn ($q) => $q->whereKeyNot($page->id))->exists()) {
            throw $unavailable;
        }

        $old = $page?->slug;

        try {
            $page = DB::transaction(function () use ($page, $project, $organizationId, $data) {
                $page ??= new StatusPage(['organization_id' => $organizationId, 'project_id' => $project->id]);
                $page->fill(['slug' => $data['slug'], 'title' => $data['title'], 'description' => $data['description'] ?? null, 'enabled' => $data['enabled']])->save();
                $page->monitors()->sync(collect($data['monitors'])->values()->mapWithKeys(fn (array $m, int $i) => [$m['id'] => ['display_name' => $m['display_name'], 'position' => $i]])->all());

                return $page;
            });
        } catch (UniqueConstraintViolationException) {
            // Taken concurrently (slug), or the project's page created concurrently.
            throw $unavailable;
        }

        Cache::forget($page->cacheKey());

        if ($old !== null && $old !== $page->slug) {
            Cache::forget("uptime:status-page:{$old}");
        }

        $this->audit->record('uptime.status_page.saved', 'uptime_status_page', $page->id, ['slug' => $page->slug, 'enabled' => $page->enabled, 'monitors' => count($data['monitors'])], $organizationId);

        return response()->json(['data' => ['id' => $page->id, 'url' => url("/status/{$page->slug}")]]);
    }

    public function destroy(StatusPage $page): JsonResponse
    {
        $this->authorize('delete', $page);

        DB::table('uptime_status_page_monitors')->where('status_page_id', $page->id)->delete();
        $page->delete();
        Cache::forget($page->cacheKey());
        $this->audit->record('uptime.status_page.deleted', 'uptime_status_page', $page->id, ['slug' => $page->slug], $page->organization_id);

        return response()->json(null, 204);
    }

    /** GET /status/{slug}: public, no session, cached. */
    public function show(string $slug, StatusPageView $view): HttpResponse
    {
        abort_unless(preg_match(StatusPage::SLUG, $slug) === 1, 404);
        $seconds = max(1, (int) config('uptime.status_page.cache_seconds', 60));

        $data = Cache::remember("uptime:status-page:{$slug}", $seconds, function () use ($slug, $view) {
            $page = StatusPage::query()->where('slug', $slug)->where('enabled', true)->first();

            return $page === null ? false : $view->build($page);
        });

        abort_if($data === false, 404);

        $response = response()->view('uptime::status', ['page' => $data]);
        $response->headers->set('Cache-Control', "public, max-age={$seconds}");

        if (config('uptime.status_page.noindex', true)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
