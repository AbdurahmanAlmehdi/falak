<?php

namespace Falak\Edge\Http\Controllers;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\PathRateLimit;
use Falak\Edge\Domain\Models\ServerState;
use Falak\Edge\Infrastructure\EdgeCaddyBuilds;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Network → Rate limits (docs/plans/network/protection.md): requests per client IP on a path of a site, enforced by the
 * site's edge (Falak's Caddy build, installed by the agent on first use). Refused for sites served by a FrankenPHP edge
 * (its Caddy is FrankenPHP's build) or by an agent without `edge.rate_limit`.
 */
final class PathRateLimitController extends Controller
{
    public const FEATURE = 'edge.rate_limit';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
    ) {}

    public function index(Request $request, EdgeChanges $changes, ServerDirectory $servers, AgentDirectory $agents): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.view');

        $sites = collect($this->sites->forOrganization($organizationId))->keyBy('id');
        $limits = PathRateLimit::query()->where('organization_id', $organizationId)->orderBy('site_id')->orderBy('path')->get();

        return Inertia::render('Edge/RateLimits', [
            'limits' => $limits->map(fn (PathRateLimit $limit) => [
                'id' => $limit->id,
                'site' => ['id' => $limit->site_id, 'name' => $sites[$limit->site_id]->name ?? $limit->site_id],
                'path' => $limit->path,
                'events' => $limit->events,
                'window_s' => $limit->window_s,
                'created_at' => $limit->created_at->toIso8601String(),
            ])->values(),
            'sites' => $sites->map(fn (SiteData $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'refusal' => self::refusal($site->id, $changes, $servers, $agents),
            ])->sortBy('name')->values(),
            // Servers whose edge applied its config without the rate limits, and why (the agent's last result).
            'notApplied' => ServerState::query()->whereIn('server_id', $limits->pluck('site_id')->unique()->flatMap(fn (string $siteId) => $changes->serversFor($siteId))->unique()->values())
                ->whereNotNull('rate_limits_refused')->get()
                ->map(fn (ServerState $state) => ['server' => $servers->find($state->server_id)->name ?? $state->server_id, 'reason' => $state->rate_limits_refused])
                ->values(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'edge.manage')],
        ]);
    }

    public function store(Request $request, EdgeChanges $changes, ServerDirectory $servers, AgentDirectory $agents, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        abort_unless($this->access->can($request->user(), $organizationId, 'edge.view'), 404);
        $this->access->authorize($request->user(), $organizationId, 'edge.manage');

        $data = $request->validate([
            'site_id' => ['required', 'string', 'max:26'],
            'path' => ['required', 'string', 'max:200', 'regex:'.PathRateLimit::PATH],
            'events' => ['required', 'integer', 'min:1', 'max:100000'],
            'window_s' => ['required', 'integer', 'min:1', 'max:86400'],
        ], ['path.regex' => 'Start the path with / (a trailing * matches a prefix, e.g. /api/*); no spaces, quotes or {placeholders}.']);

        $site = $this->sites->find(strtolower((string) $data['site_id']));
        abort_if($site === null || $site->organizationId !== $organizationId, 404);

        if (($why = self::refusal($site->id, $changes, $servers, $agents)) !== null) {
            throw ValidationException::withMessages(['site_id' => $why]);
        }

        $existing = PathRateLimit::query()->where('site_id', $site->id)->where('path', $data['path'])->first();

        if ($existing === null && PathRateLimit::query()->where('site_id', $site->id)->count() >= PathRateLimit::MAX_PER_SITE) {
            throw ValidationException::withMessages(['path' => 'A site has at most '.PathRateLimit::MAX_PER_SITE.' rate limits.']);
        }

        $limit = $existing ?? new PathRateLimit(['organization_id' => $organizationId, 'site_id' => $site->id, 'path' => $data['path']]);
        $limit->forceFill(['events' => (int) $data['events'], 'window_s' => (int) $data['window_s'], 'created_by' => $request->user()?->getAuthIdentifier()])->save();

        $audit->record('edge.rate_limit_saved', 'site', $site->id, ['path' => $limit->path, 'events' => $limit->events, 'window_s' => $limit->window_s], $organizationId);
        $changes->siteChanged($site->id);

        return back();
    }

    public function destroy(Request $request, string $limit, EdgeChanges $changes, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $model = PathRateLimit::query()->where('organization_id', $organizationId)->whereKey($limit)->first();
        abort_if($model === null || ! $this->access->can($request->user(), $organizationId, 'edge.view'), 404);
        $this->access->authorize($request->user(), $organizationId, 'edge.manage');

        $model->delete();
        $audit->record('edge.rate_limit_removed', 'site', $model->site_id, ['path' => $model->path], $organizationId);
        $changes->siteChanged($model->site_id);

        return back();
    }

    /** GET /install/edge/caddy-linux-{arch} — public, like the agent installer: the build holds nothing secret. */
    public function caddyBuild(string $arch, EdgeCaddyBuilds $builds): BinaryFileResponse
    {
        $file = $builds->file($arch) ?? abort(404, 'No Falak Caddy build for this architecture is published.');

        return response()->download($file, "falak-caddy-linux-{$arch}", [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-Sha256' => (string) $builds->checksum($arch),
        ]);
    }

    /**
     * Why the site's edge cannot rate-limit, or null: every server routing it (targets, load balancer) needs an agent
     * with edge.rate_limit and a Caddy edge (not FrankenPHP).
     */
    public static function refusal(string $siteId, EdgeChanges $changes, ServerDirectory $servers, AgentDirectory $agents): ?string
    {
        $ids = $changes->serversFor($siteId);

        if ($ids === []) {
            return 'The site runs on no server yet.';
        }

        foreach ($ids as $id) {
            $server = $servers->find($id);

            if ($server === null) {
                continue;
            }

            if ($server->phpRuntime === 'frankenphp') {
                return "{$server->name} serves sites with FrankenPHP, whose Caddy has no rate-limit module: use a Cloudflare rate limit (Settings → Networking) or a PHP-FPM + Caddy server.";
            }

            if (! ($agents->forServer($id)?->supports(self::FEATURE) ?? false)) {
                return "Update the agent on {$server->name} first (edge rate limits need a newer agent).";
            }

            if (app(EdgeCaddyBuilds::class)->forArch($server->arch) === null) {
                return "No Falak Caddy build is published for {$server->name}'s architecture (".($server->arch ?? 'unknown').').';
            }
        }

        return null;
    }
}
