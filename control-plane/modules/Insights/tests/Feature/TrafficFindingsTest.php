<?php

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Application\Queries\IssueTimeline;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\PerformanceFindings;
use Falak\Insights\Contracts\SecurityFindings;
use Falak\Insights\Contracts\SecurityIssues;
use Falak\Insights\Domain\Models\Issue;
use Illuminate\Support\Str;

beforeEach(function () {
    [, $this->organization] = actingAsMember(Role::Admin);
    $this->siteId = strtolower((string) Str::ulid());
});

it('charts no data, not a p95 of 0, for an edge slow endpoint', function () {
    [$data] = app(PerformanceFindings::class)->slowEndpoint($this->organization->id, $this->siteId, '/orders/:id', 1800, 1000, 120, now());

    $timeline = app(IssueTimeline::class)(Issue::query()->findOrFail($data->id), CarbonImmutable::now());

    expect($timeline)->toHaveCount(24)
        ->and(array_unique(array_column($timeline, 'value')))->toBe([null]);
});

it('files HTTP scanners under the one security kind, apart from the server findings of the same kind', function () {
    app(SecurityFindings::class)->scanner($this->organization->id, $this->siteId, 'env', 'Scanner probing for .env files', 30, now());
    app(SecurityIssues::class)->record($this->organization->id, strtolower((string) Str::ulid()), 'network.exposed_port', 'tcp/5432', 'Port 5432 is open', null, now());

    expect(Issue::query()->where('kind', IssueKind::Security)->count())->toBe(2)
        ->and(array_map(fn ($i) => $i->title, app(SecurityFindings::class)->openScanners($this->organization->id, $this->siteId)))->toBe(['Scanner probing for .env files']);
});
