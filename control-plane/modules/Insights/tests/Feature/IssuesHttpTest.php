<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Insights\Contracts\IssuePriority;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Contracts\SiteNameResolver;
use Falak\Insights\Domain\Models\Issue;
use Falak\Insights\Domain\Models\IssueComment;
use Falak\Projects\Application\Actions\CreateProject;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Falak\Telemetry\Contracts\Data\RequestCounts;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    insights_ingest($this->organization->id, [
        insights_exception(),
        insights_exception(['type' => 'LogicException', 'message' => 'Broken invariant', 'site_id' => INSIGHTS_OTHER_SITE]),
        insights_aggregate(),
        insights_aggregate(['event_type' => 'query', 'name' => 'select * from `orders`', 'max_ms' => 2500]),
        insights_aggregate(['event_type' => 'job', 'name' => 'App\\Jobs\\Sync', 'count' => 20, 'errors' => 2]),
        insights_aggregate(['event_type' => 'cache', 'name' => 'hit:redis', 'count' => 90]),
        insights_aggregate(['event_type' => 'cache', 'name' => 'miss', 'count' => 10]),
    ]);
    $this->issue = Issue::query()->where('site_id', INSIGHTS_SITE)->sole();
});

it('renders the organization-wide overview with a per-site breakdown', function () {
    $this->get('/observability')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Overview', false)
        ->where('filters.range', '24h')
        ->has('sites', 2)
        ->has('siteRows', 2)
        ->where('openIssues', 2)
        ->where('overview.totals.requests', 100)
        ->where('overview.totals.exceptions_unhandled', 2)
        ->has('overview.issues', 2)
        ->where('siteRows', fn ($sites) => collect($sites)->firstWhere('id', INSIGHTS_SITE)['requests'] === 100
            && collect($sites)->firstWhere('id', INSIGHTS_SITE)['exceptions'] === 1
            && collect($sites)->firstWhere('id', INSIGHTS_SITE)['open_issues'] === 1));

    $this->get('/observability?range=30d')->assertOk()->assertInertia(fn ($page) => $page->where('filters.range', '30d')->has('overview.series', 60));
    $this->get('/observability?range=5y')->assertSessionHasErrors('range');
});

it('renders the app overview for one site from aggregates with telemetry deep links', function () {
    $this->get('/observability?site='.INSIGHTS_SITE.'&range=1h')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Overview', false)
        ->where('filters.site', INSIGHTS_SITE)
        ->where('siteRows', [])
        ->where('overview.totals.requests', 100)
        ->where('overview.totals.request_errors', 1)
        ->where('overview.totals.request_p95_ms', 120)
        ->where('overview.totals.jobs', 20)
        ->where('overview.totals.failed_jobs', 2)
        ->where('overview.totals.exceptions_unhandled', 1)
        ->where('overview.totals.exceptions_handled', 0)
        ->where('overview.totals.cache_hit_ratio', 0.9)
        ->has('overview.series', 60)
        ->where('overview.routes.0.name', 'GET /products')
        ->where('overview.queries.0.max_ms', 2500)
        ->where('overview.jobs.0.errors', 2)
        ->has('overview.issues', 1)
        ->where('links.logs', fn ($url) => str_contains($url, '/observability/logs') && str_contains($url, INSIGHTS_SITE))
        ->where('links.traces', fn ($url) => str_contains($url, '/observability/traces')));

    $this->get('/observability?site=not-a-site')->assertSessionHasErrors('site');
});

it('filters the overview by project', function () {
    $project = app(CreateProject::class)($this->organization->id, $this->user->id, ['name' => 'Empty']);

    $this->get('/observability?project='.$project->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.project', $project->id)
        ->has('projects')
        ->where('overview.totals.requests', 0)
        ->has('siteRows', 0));
});

it('returns the site summary for the service panel', function () {
    $this->getJson('/insights/sites/'.INSIGHTS_SITE.'/summary?range=1h')->assertOk()
        ->assertJsonPath('site.id', INSIGHTS_SITE)
        ->assertJsonPath('overview.totals.requests', 100)
        ->assertJsonPath('overview.routes.0.name', 'GET /products')
        ->assertJsonPath('open_issues', 1)
        ->assertJsonPath('links.issues', '/observability/issues?site='.INSIGHTS_SITE);

    $this->getJson('/insights/sites/'.INSIGHTS_SITE.'/summary?range=5y')->assertUnprocessable();
});

it("falls back to the edge's request counts in the site summary when the app reports none", function () {
    config(['telemetry.loki.url' => 'http://loki:3100']);
    $edge = new class implements AccessLogCounts
    {
        public int $calls = 0;

        public function forRelease(string $organizationId, string $siteId, string $releaseId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
        {
            return new RequestCounts(0, 0);
        }

        public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
        {
            $this->calls++;

            return new RequestCounts(400, 8);
        }
    };
    app()->instance(AccessLogCounts::class, $edge);

    $this->getJson('/insights/sites/'.INSIGHTS_OTHER_SITE.'/summary')->assertOk()
        ->assertJsonPath('overview.totals.requests', 0)
        ->assertJsonPath('edge', ['requests' => 400, 'errors' => 8]);
    // The next poll reads the cached counts; another range queries Loki.
    $this->getJson('/insights/sites/'.INSIGHTS_OTHER_SITE.'/summary')->assertOk()->assertJsonPath('edge.requests', 400);
    expect($edge->calls)->toBe(1);
    $this->getJson('/insights/sites/'.INSIGHTS_OTHER_SITE.'/summary?range=7d')->assertOk()->assertJsonPath('edge.requests', 400);
    expect($edge->calls)->toBe(2);
    // The app's own numbers win.
    $this->getJson('/insights/sites/'.INSIGHTS_SITE.'/summary')->assertOk()->assertJsonPath('edge', null);
});

it('redirects the legacy insights pages', function () {
    $this->get('/insights')->assertRedirect('/observability');
    $this->get('/insights/issues?status=all')->assertRedirect('/observability/issues?status=all');
    $this->get("/insights/issues/{$this->issue->id}")->assertRedirect("/observability/issues/{$this->issue->id}");
    $this->get('/insights/heartbeats')->assertRedirect('/observability/heartbeats');
    $this->get('/insights/sites/'.INSIGHTS_SITE.'?range=1h')->assertRedirect('/observability?site='.INSIGHTS_SITE.'&range=1h');
    $this->get('/insights/sites/not-a-site')->assertNotFound();
    expect($this->issue->url())->toEndWith("/observability/issues/{$this->issue->id}");
});

it('bulk-resolves and ignores issues', function () {
    $other = Issue::query()->where('site_id', INSIGHTS_OTHER_SITE)->sole();

    $this->put('/insights/issues/bulk/status', ['ids' => [$this->issue->id, $other->id], 'status' => 'resolved'])->assertRedirect()->assertSessionHas('success', '2 issues resolved.');
    expect($this->issue->refresh()->status)->toBe(IssueStatus::Resolved)->and($other->refresh()->status)->toBe(IssueStatus::Resolved);

    $this->put('/insights/issues/bulk/status', ['ids' => [$other->id], 'status' => 'ignored'])->assertSessionHas('success', 'Issue ignored.');
    $this->put('/insights/issues/bulk/status', ['ids' => [], 'status' => 'ignored'])->assertSessionHasErrors('ids');
    $this->put('/insights/issues/bulk/status', ['ids' => [$other->id], 'status' => 'nope'])->assertSessionHasErrors('status');

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->put('/insights/issues/bulk/status', ['ids' => [$other->id], 'status' => 'open'])->assertForbidden();

    actingAsMember(Role::Owner);
    $this->put('/insights/issues/bulk/status', ['ids' => [$other->id], 'status' => 'open'])->assertNotFound();
});

it('uses the bound SiteNameResolver for names', function () {
    app()->instance(SiteNameResolver::class, new class implements SiteNameResolver
    {
        public function name(string $siteId): string
        {
            return "site-{$siteId}";
        }

        public function names(array $siteIds): array
        {
            return collect($siteIds)->mapWithKeys(fn ($id) => [$id => "site-{$id}"])->all();
        }
    });

    $this->get('/observability/issues')->assertInertia(fn ($page) => $page->where('issues.data.0.site_name', fn ($name) => str_starts_with($name, 'site-')));
});

it('filters, searches and sorts the issue list', function () {
    $this->get('/observability/issues')->assertOk()->assertInertia(fn ($page) => $page->component('Insights/Issues', false)->has('issues.data', 2)->where('filters.status', 'open')
        ->where('counts.open', 2)->where('counts.resolved', 0)->where('counts.all', 2)
        ->where('issues.data.0.sparkline', fn ($line) => collect($line)->count() === 24 && collect($line)->sum() === 1));
    $this->get('/observability/issues?site='.INSIGHTS_SITE)->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $this->issue->id));
    $this->get('/observability/issues?search=invariant')->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.title', 'LogicException: Broken invariant'));
    $this->get('/observability/issues?search=%25')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/observability/issues?status=resolved')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/observability/issues?kind=performance')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/observability/issues?sort=occurrences&status=all')->assertInertia(fn ($page) => $page->has('issues.data', 2));
    $this->get('/observability/issues?assignee=me')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/observability/issues?sort=random')->assertSessionHasErrors('sort');
});

it('shows the issue with its stack trace, occurrences and trace links', function () {
    $this->get("/observability/issues/{$this->issue->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Issue', false)
        ->where('issue.id', $this->issue->id)
        ->where('issue.frames.0.file', 'app/Services/Checkout.php')
        ->where('issue.frames.0.in_app', true)
        ->where('issue.frames.2.in_app', false)
        ->where('issue.trace_id', $this->issue->last_trace_id)
        ->has('occurrences', 1)
        ->where('occurrences.0.trace_url', fn ($url) => str_contains($url, "/observability/traces/{$this->issue->last_trace_id}"))
        ->has('timeline', 24)
        ->where('links.trace', fn ($url) => str_contains($url, $this->issue->last_trace_id))
        ->where('links.traceLogs', fn ($url) => str_contains($url, $this->issue->last_trace_id))
        ->where('can.manage', true)
        ->has('members', 2));
});

it('supports assigning, prioritising and commenting', function () {
    [$teammate] = memberOf($this->organization, Role::Viewer);
    [$stranger] = memberOf();

    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => $teammate->id])->assertSessionHasNoErrors();
    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => $stranger->id])->assertSessionHasErrors('assignee_id');
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'urgent'])->assertSessionHasNoErrors();
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'meh'])->assertSessionHasErrors('priority');
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => 'Looking into it'])->assertSessionHasNoErrors();
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => ''])->assertSessionHasErrors('body');

    expect($this->issue->refresh())
        ->assignee_id->toBe($teammate->id)
        ->priority->toBe(IssuePriority::Urgent)
        ->and($this->issue->comments()->sole()->body)->toBe('Looking into it')
        ->and($this->issue->activities()->pluck('type')->all())->toBe(['opened', 'assigned', 'priority', 'commented'])
        ->and(AuditEntry::query()->where('action', 'insights.issue.assigned')->exists())->toBeTrue();

    $this->get('/observability/issues?assignee='.$teammate->id)->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.assignee.name', $teammate->name));
    $this->put("/insights/issues/{$this->issue->id}/assignee", ['assignee_id' => null])->assertSessionHasNoErrors();
    expect($this->issue->refresh()->assignee_id)->toBeNull();

    // Only the author may delete a comment.
    $comment = IssueComment::query()->sole();
    [$colleague] = memberOf($this->organization, Role::Developer);
    $this->actingAs($colleague)->delete("/insights/issues/{$this->issue->id}/comments/{$comment->id}")->assertForbidden();
    $this->actingAs($this->user)->delete("/insights/issues/{$this->issue->id}/comments/{$comment->id}")->assertRedirect();
    expect(IssueComment::query()->count())->toBe(0);
});

it('reopens ignored issues and records the audit trail', function () {
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'ignored'])->assertRedirect();
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'open'])->assertRedirect();
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'nope'])->assertSessionHasErrors('status');

    expect($this->issue->refresh()->status)->toBe(IssueStatus::Open)
        ->and(AuditEntry::query()->whereIn('action', ['insights.issue.ignored', 'insights.issue.open'])->count())->toBe(2);
});

it('enforces permissions and organization isolation', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);
    $this->get("/observability/issues/{$this->issue->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->put("/insights/issues/{$this->issue->id}/status", ['status' => 'resolved'])->assertForbidden();
    $this->post("/insights/issues/{$this->issue->id}/comments", ['body' => 'hi'])->assertForbidden();

    actingAsMember(Role::Owner);
    $this->get("/observability/issues/{$this->issue->id}")->assertNotFound();
    $this->put("/insights/issues/{$this->issue->id}/priority", ['priority' => 'high'])->assertNotFound();
    $this->get('/observability/issues')->assertInertia(fn ($page) => $page->has('issues.data', 0));
    $this->get('/observability')->assertInertia(fn ($page) => $page->has('sites', 0));
    $this->get('/observability?site='.INSIGHTS_SITE)->assertInertia(fn ($page) => $page->where('overview.totals.requests', 0));
    $this->getJson('/insights/sites/'.INSIGHTS_SITE.'/summary')->assertOk()->assertJsonPath('overview.totals.requests', 0);

    auth()->logout();
    $this->get('/observability')->assertRedirect('/login');
});
