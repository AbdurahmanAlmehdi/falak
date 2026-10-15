<?php

namespace Falak\Insights\Http\Controllers;

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Application\Queries\SiteOverview;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Insights\Domain\Models\HeartbeatMonitor;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /observability Overview tab: organization-wide (or per project / per site) application health from the
 * per-minute aggregates, plus the JSON summary behind the service panel's Observability tab.
 */
final class OverviewController extends Controller
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteNameResolver $sites,
        private readonly ProjectDirectory $projects,
    ) {}

    public function index(Request $request, SiteOverview $overview, TelemetryLinks $links): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');

        $filters = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(SiteOverview::RANGES))],
            'project' => ['nullable', 'string', 'regex:'.self::ULID],
            'site' => ['nullable', 'string', 'regex:'.self::ULID],
        ]);
        $range = $filters['range'] ?? '24h';
        $site = isset($filters['site']) ? strtolower($filters['site']) : null;
        $projects = $this->projects->forOrganization($organizationId);
        $project = isset($filters['project']) ? collect($projects)->first(fn (ProjectData $p) => strcasecmp($p->id, $filters['project']) === 0) : null;
        $projectSites = $project ? $this->projectSites($project->id) : null;

        $scope = $site ?? $projectSites;
        $data = $overview($organizationId, $scope, $range);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        $known = DB::table('insights_sites')->where('organization_id', $organizationId)->orderByDesc('last_seen_at')->get();
        $names = $this->sites->names($known->pluck('site_id')->all());

        return Inertia::render('Insights/Overview', [
            'filters' => ['range' => $range, 'project' => $project?->id, 'site' => $site],
            'ranges' => array_keys(SiteOverview::RANGES),
            'projects' => array_map(fn (ProjectData $p) => ['id' => $p->id, 'name' => $p->name], $projects),
            'sites' => $known->map(fn ($row) => ['id' => $row->site_id, 'name' => $names[$row->site_id] ?? $row->site_id])->values(),
            'overview' => $data,
            'siteRows' => $site === null ? $this->siteRows($organizationId, $projectSites, $from, $to, $names) : [],
            'issueSites' => $this->sites->names(array_values(array_unique(array_filter(array_column($data['issues'], 'site_id'))))),
            'openIssues' => SiteOverview::scope(DB::table('insights_issues'), $scope)->where('organization_id', $organizationId)->where('status', IssueStatus::Open->value)->count(),
            'heartbeats' => $this->heartbeatHealth($organizationId, $scope),
            'links' => [
                'logs' => $links->logs($site ? ['site_id' => $site] : [], $from, $to),
                'errorLogs' => $links->logs([...($site ? ['site_id' => $site] : []), 'search' => 'error'], $from, $to),
                'traces' => $links->traceSearch($site ? ['site_id' => $site] : [], $from, $to),
                'slowTraces' => $links->traceSearch([...($site ? ['site_id' => $site] : []), 'min_duration_ms' => 1000], $from, $to),
                'grafana' => $site ? $links->grafanaDashboard($organizationId, 'falak-laravel', ['site' => $site]) : null,
            ],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'insights.manage')],
        ]);
    }

    /** Legacy per-site overview → the Overview tab filtered to that site. */
    public function show(Request $request, string $siteId): RedirectResponse
    {
        abort_unless(preg_match(self::ULID, $siteId) === 1, 404);

        return redirect()->route('observability.overview', array_filter(['site' => strtolower($siteId), 'range' => $request->query('range')]));
    }

    /**
     * GET /insights/sites/{siteId}/summary — the service panel's Observability tab (issues, slow routes/jobs/queries,
     * heartbeats) as JSON.
     */
    public function summary(Request $request, string $siteId, SiteOverview $overview, TelemetryLinks $links, AccessLogCounts $edge): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'insights.view');
        abort_unless(preg_match(self::ULID, $siteId) === 1, 404);
        $siteId = strtolower($siteId);

        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(SiteOverview::RANGES))]])['range'] ?? '24h';
        $data = $overview($organizationId, $siteId, $range);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        return response()->json([
            'site' => ['id' => $siteId, 'name' => $this->sites->name($siteId)],
            'range' => $range,
            'ranges' => array_keys(SiteOverview::RANGES),
            'overview' => $data,
            'edge' => $this->edgeRequests($edge, $organizationId, $siteId, $data['totals']['requests'] ?? 0, $from, $to),
            'open_issues' => DB::table('insights_issues')->where('organization_id', $organizationId)->where('site_id', $siteId)->where('status', IssueStatus::Open->value)->count(),
            'heartbeats' => HeartbeatMonitor::query()->where('organization_id', $organizationId)->where('site_id', $siteId)->orderBy('job')->get()
                ->map(fn (HeartbeatMonitor $m) => HeartbeatController::present($m))->values(),
            'links' => [
                'overview' => route('observability.overview', ['site' => $siteId, 'range' => $range], false),
                'issues' => route('observability.issues.index', ['site' => $siteId], false),
                'heartbeats' => route('observability.heartbeats', [], false),
                'thresholds' => route('insights.sites.settings', ['siteId' => $siteId], false),
                'traces' => $links->traceSearch(['site_id' => $siteId], $from, $to),
                'slowTraces' => $links->traceSearch(['site_id' => $siteId, 'min_duration_ms' => 1000], $from, $to),
            ],
        ]);
    }

    /**
     * Requests and 5xx answers from the edge's access log when the app reports none (no APM package): the Metrics tab's
     * fallback ("from the edge"). null when the app reports requests, or Loki is off or failing.
     *
     * @return array{requests: int, errors: int}|null
     */
    private function edgeRequests(AccessLogCounts $edge, string $organizationId, string $siteId, int $apmRequests, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if ($apmRequests > 0 || (string) config('telemetry.loki.url') === '') {
            return null;
        }

        try {
            $counts = $edge->forSite($organizationId, $siteId, $from, $to);
        } catch (TelemetryUnavailable|TelemetryQueryFailed) {
            return null;
        }

        return $counts->total > 0 ? ['requests' => $counts->total, 'errors' => $counts->errors] : null;
    }

    /**
     * @return list<string>
     */
    private function projectSites(string $projectId): array
    {
        $ids = [];

        foreach ($this->projects->environments($projectId) as $environment) {
            foreach ($this->projects->servicesIn($environment->id) as $service) {
                if ($service->kind === ServiceKind::Site) {
                    $ids[] = strtolower($service->refId);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Per-site breakdown for the selected window.
     *
     * @param  list<string>|null  $sites
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function siteRows(string $organizationId, ?array $sites, CarbonImmutable $from, CarbonImmutable $to, array $names): array
    {
        $known = SiteOverview::scope(DB::table('insights_sites'), $sites)->where('organization_id', $organizationId)->orderByDesc('last_seen_at')->get();

        $requests = SiteOverview::scope(DB::table('insights_aggregates'), $sites)
            ->where('organization_id', $organizationId)
            ->where('event_type', 'request')
            ->where('minute', '>=', $from)
            ->where('minute', '<', $to)
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(count) AS total, SUM(errors) AS errors, SUM(p95_ms * count) AS weighted_p95')
            ->get()
            ->keyBy('site_id');

        $exceptions = SiteOverview::scope(DB::table('insights_exceptions'), $sites)
            ->where('organization_id', $organizationId)
            ->where('minute', '>=', $from)
            ->where('minute', '<', $to)
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) AS total')
            ->pluck('total', 'site_id');

        $openIssues = SiteOverview::scope(DB::table('insights_issues'), $sites)
            ->where('organization_id', $organizationId)
            ->where('status', IssueStatus::Open->value)
            ->whereNotNull('site_id')
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) AS total')
            ->pluck('total', 'site_id');

        return $known->map(function ($site) use ($names, $requests, $exceptions, $openIssues) {
            $req = $requests->get($site->site_id);
            $total = (int) ($req->total ?? 0);

            return [
                'id' => $site->site_id,
                'name' => $names[$site->site_id] ?? $site->site_id,
                'last_seen_at' => Carbon::parse($site->last_seen_at)->toIso8601String(),
                'requests' => $total,
                'errors' => (int) ($req->errors ?? 0),
                'p95_ms' => $total > 0 ? round((float) $req->weighted_p95 / $total, 1) : null,
                'exceptions' => (int) ($exceptions[$site->site_id] ?? 0),
                'open_issues' => (int) ($openIssues[$site->site_id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * @param  string|list<string>|null  $sites
     * @return array{total: int, healthy: int, missed: int, failing: int}
     */
    private function heartbeatHealth(string $organizationId, string|array|null $sites): array
    {
        $monitors = SiteOverview::scope(HeartbeatMonitor::query(), $sites)->where('organization_id', $organizationId)->where('enabled', true)->get();

        $missed = $monitors->filter(fn (HeartbeatMonitor $m) => $m->missed_at !== null)->count();
        $failing = $monitors->filter(fn (HeartbeatMonitor $m) => $m->missed_at === null && in_array($m->last_status, ['failed', 'timeout'], true))->count();

        return ['total' => $monitors->count(), 'healthy' => $monitors->count() - $missed - $failing, 'missed' => $missed, 'failing' => $failing];
    }
}
