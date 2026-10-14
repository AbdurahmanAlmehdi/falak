<?php

use Falak\Identity\Contracts\Role;
use Falak\Insights\Contracts\IssueDirectory;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\ExceptionOccurrence;
use Falak\Insights\Domain\Models\Issue;
use Falak\Insights\Events\IssueOpened;
use Falak\Insights\Events\IssueRegressed;
use Falak\Insights\Events\IssueResolved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('ingests the agent insights tee end-to-end through the Fleet endpoint', function () {
    Event::fake([IssueOpened::class]);
    $enrolled = fleet_enroll($this->organization->id, null);

    $body = fleet_ndjson([
        insights_exception(),
        insights_aggregate(),
        insights_heartbeat(),
    ]);

    $this->call('POST', '/agent/v1/insights', [], [], [], $this->transformHeadersToServerVars(fleet_mtls($enrolled['fingerprint'])), $body)->assertNoContent();

    $issue = Issue::query()->sole();
    expect($issue->organization_id)->toBe($this->organization->id)
        ->and($issue->kind)->toBe(IssueKind::Exception)
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->title)->toBe('App\\Exceptions\\PaymentFailed: Card declined')
        ->and($issue->culprit)->toBe('App\\Services\\Gateway->charge (app/Services/Checkout.php:42)')
        ->and($issue->occurrences)->toBe(1)
        ->and($issue->unhandled_occurrences)->toBe(1)
        ->and($issue->affected_users)->toBe(1)
        ->and(DB::table('insights_aggregates')->count())->toBe(1)
        ->and(DB::table('insights_heartbeat_monitors')->count())->toBe(1)
        ->and(DB::table('insights_sites')->where('organization_id', $this->organization->id)->pluck('site_id')->all())->toBe([INSIGHTS_SITE]);

    Event::assertDispatched(IssueOpened::class, fn (IssueOpened $e) => $e->issueId === $issue->id
        && $e->organizationId === $this->organization->id
        && $e->siteId === INSIGHTS_SITE
        && $e->kind === 'exception'
        && str_ends_with($e->url, "/observability/issues/{$issue->id}"));
});

it('groups exceptions by fingerprint and counts occurrences and distinct users', function () {
    Event::fake([IssueOpened::class]);

    insights_ingest($this->organization->id, [
        insights_exception(['line' => 42, 'user_id' => 'u1']),
        insights_exception(['line' => 57, 'user_id' => 'u2', 'message' => 'Card expired', 'handled' => true]),
        insights_exception(['user_id' => 'u1']),
        insights_exception(['type' => 'LogicException', 'user_id' => null]),
        insights_exception(['site_id' => INSIGHTS_OTHER_SITE]),
    ]);

    $payment = Issue::query()->where('exception_type', 'App\\Exceptions\\PaymentFailed')->where('site_id', INSIGHTS_SITE)->sole();

    expect(Issue::query()->count())->toBe(3)
        ->and($payment->occurrences)->toBe(3)
        ->and($payment->unhandled_occurrences)->toBe(2)
        ->and($payment->affected_users)->toBe(2)
        ->and(ExceptionOccurrence::query()->where('issue_id', $payment->id)->count())->toBe(3)
        ->and(ExceptionOccurrence::query()->whereNotNull('user_hash')->where('user_hash', 'u1')->exists())->toBeFalse(); // user ids are hashed

    Event::assertDispatchedTimes(IssueOpened::class, 3);

    // Later occurrences keep counting on the same issue without reopening events.
    insights_ingest($this->organization->id, [insights_exception(['user_id' => 'u3'])]);

    expect($payment->refresh()->occurrences)->toBe(4)->and($payment->affected_users)->toBe(3);
    Event::assertDispatchedTimes(IssueOpened::class, 3);
});

it('is idempotent when an agent re-sends the same batch', function () {
    $batch = [insights_exception(), insights_aggregate(), insights_aggregate(['name' => 'GET /cart']), insights_heartbeat()];

    insights_ingest($this->organization->id, $batch);
    insights_ingest($this->organization->id, $batch);

    expect(Issue::query()->sole()->occurrences)->toBe(1)
        ->and(ExceptionOccurrence::query()->count())->toBe(1)
        ->and(DB::table('insights_aggregates')->count())->toBe(2)
        ->and(DB::table('insights_heartbeat_runs')->count())->toBe(1);

    // A corrected aggregate for the same minute replaces the stored values.
    insights_ingest($this->organization->id, [insights_aggregate(['count' => 250, 'p95_ms' => 180])]);

    expect(DB::table('insights_aggregates')->where('name', 'GET /products')->value('count'))->toBe(250);
});

it('keeps each organization and reporting server separate', function () {
    [, $other] = memberOf();

    insights_ingest($this->organization->id, [insights_exception(), insights_aggregate()]);
    insights_ingest($other->id, [insights_exception(), insights_aggregate()]);
    insights_ingest($this->organization->id, [insights_aggregate()], serverId: '01J8SERVER0000000000000001');

    expect(Issue::query()->count())->toBe(2)
        ->and(Issue::query()->where('organization_id', $other->id)->sole()->occurrences)->toBe(1)
        ->and(DB::table('insights_aggregates')->where('organization_id', $this->organization->id)->count())->toBe(2)
        ->and(app(IssueDirectory::class)->open($this->organization->id))->toHaveCount(1);
});

it('skips malformed lines without failing the batch', function () {
    $result = insights_ingest($this->organization->id, [
        insights_exception(['site_id' => 'not-a-ulid']),
        insights_exception(['type' => '']),
        insights_aggregate(['count' => -5]),
        insights_aggregate(['event_type' => null]),
        insights_heartbeat(['status' => 'weird']),
        ['kind' => 'unknown'],
        insights_exception(['trace_id' => 'zz', 'at' => 'yesterday-ish', 'stacktrace' => null]),
    ]);

    expect($result)->toBe(['exceptions' => 1, 'aggregates' => 0, 'heartbeats' => 0, 'skipped' => 6])
        ->and(ExceptionOccurrence::query()->sole()->trace_id)->toBeNull();
});

it('truncates oversized fields and ignores timestamps from the future', function () {
    insights_ingest($this->organization->id, [insights_exception([
        'message' => str_repeat('m', 10_000),
        'stacktrace' => str_repeat("#0 /app/x.php(1): f()\n", 5000),
        'at' => now()->addDay()->toIso8601ZuluString(),
    ])]);

    $occurrence = ExceptionOccurrence::query()->sole();

    expect(strlen($occurrence->message))->toBe(4096)
        ->and(strlen((string) $occurrence->stacktrace))->toBeLessThanOrEqual(16384)
        ->and($occurrence->occurred_at->isFuture())->toBeFalse();
});

it('reopens resolved issues on a new occurrence (regression)', function () {
    Event::fake([IssueOpened::class, IssueRegressed::class, IssueResolved::class]);
    $this->travelTo(now()->subHour());
    insights_ingest($this->organization->id, [insights_exception()]);
    $this->travelBack();
    $issue = Issue::query()->sole();

    $this->put("/insights/issues/{$issue->id}/status", ['status' => 'resolved'])->assertRedirect();
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)->and($issue->resolved_by)->toBe($this->user->id);
    Event::assertDispatched(IssueResolved::class, fn ($e) => $e->issueId === $issue->id);

    // Late data from before the resolution does not regress.
    insights_ingest($this->organization->id, [insights_exception(['at' => now()->subMinutes(30)->toIso8601ZuluString()])]);
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved);
    Event::assertNotDispatched(IssueRegressed::class);

    $this->travel(1)->minutes();
    insights_ingest($this->organization->id, [insights_exception()]);

    expect($issue->refresh())
        ->status->toBe(IssueStatus::Open)
        ->regressions->toBe(1)
        ->occurrences->toBe(3)
        ->resolved_at->toBeNull()
        ->and($issue->regressed_at)->not->toBeNull()
        ->and($issue->activities()->pluck('type')->all())->toBe(['opened', 'resolved', 'regressed']);
    Event::assertDispatched(IssueRegressed::class, fn ($e) => $e->issueId === $issue->id);
    Event::assertDispatchedTimes(IssueOpened::class, 1);
});

it('keeps counting ignored issues silently', function () {
    insights_ingest($this->organization->id, [insights_exception()]);
    $issue = Issue::query()->sole();
    $this->put("/insights/issues/{$issue->id}/status", ['status' => 'ignored'])->assertRedirect();

    Event::fake([IssueOpened::class, IssueRegressed::class]);
    insights_ingest($this->organization->id, [insights_exception()]);

    expect($issue->refresh()->status)->toBe(IssueStatus::Ignored)->and($issue->occurrences)->toBe(2);
    Event::assertNothingDispatched();
});

it('groups an exception found in a Laravel log with the same exception reported by the APM package', function () {
    // The agent's line for a log entry (docs/plans/OBSERVABILITY.md phase 2): no trace, event type log, the log file's
    // name, the "[stacktrace]" frames of another release with other line numbers.
    $fromLog = insights_exception([
        'trace_id' => '', 'span_id' => '', 'user_id' => '', 'event_type' => 'log', 'route_or_name' => 'laravel-2026-10-14.log',
        'stacktrace' => str_replace(['01J8REL0000000000000000000', '(20)'], ['01J8LOG0000000000000000000', '(21)'], insights_exception(['line' => 50])['stacktrace']),
    ]);

    insights_ingest($this->organization->id, [insights_exception(), $fromLog]);

    $issue = Issue::query()->sole();
    $occurrence = ExceptionOccurrence::query()->where('event_type', 'log')->sole();
    expect($issue->occurrences)->toBe(2)
        ->and($occurrence->trace_id)->toBeNull()
        ->and($occurrence->route_or_name)->toBe('laravel-2026-10-14.log');
});
