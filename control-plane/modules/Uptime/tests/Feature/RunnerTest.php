<?php

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Jobs\DispatchChecks;
use Falak\Uptime\Application\Jobs\PruneUptime;
use Falak\Uptime\Application\Jobs\RunCheck;
use Falak\Uptime\Application\Queries\UptimeStats;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Domain\Probing\TlsReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['prober' => $this->prober, 'alerts' => $this->alerts] = uptime_fakes();
    [, $this->organization] = memberOf(null, Role::Owner);
    $this->now = CarbonImmutable::parse('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow($this->now);
    Carbon::setTestNow($this->now);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function uptime_down(): ProbeResult
{
    return ProbeResult::failure(ErrorKind::Status, 'HTTP 502 (expected 2xx,3xx)', ['statusCode' => 502, 'totalMs' => 80]);
}

function uptime_up(int $ms = 120): ProbeResult
{
    return new ProbeResult(true, statusCode: 200, totalMs: $ms, dnsMs: 2, connectMs: 10, tlsMs: 20, ttfbMs: 100);
}

function uptime_tick(object $test, int $seconds = 60): void
{
    $test->now = $test->now->addSeconds($seconds);
    CarbonImmutable::setTestNow($test->now);
    Carbon::setTestNow($test->now);
}

it('claims due monitors once and queues them on the uptime queue', function () {
    Queue::fake();
    $due = uptime_monitor($this->organization->id);
    uptime_monitor($this->organization->id, ['paused_at' => now()]);
    uptime_monitor($this->organization->id, ['next_check_at' => now()->addMinute()]);

    expect((new DispatchChecks)->handle())->toBe(1)
        ->and((new DispatchChecks)->handle())->toBe(0)
        ->and($due->refresh()->next_check_at->equalTo($this->now->addSeconds(60)))->toBeTrue();

    Queue::assertPushedOn('uptime', RunCheck::class, fn (RunCheck $job) => $job->monitorId === $due->id);
});

it('confirms a failure with a second attempt before counting it', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $this->prober->queue(uptime_down(), uptime_up());

    (new RunCheck($monitor->id))->handle($this->prober, app(RecordCheck::class));

    $check = Check::query()->sole();
    expect($check->ok)->toBeTrue()->and($check->attempts)->toBe(2)
        ->and(count($this->prober->targets))->toBe(2)
        ->and($monitor->refresh()->consecutive_failures)->toBe(0);
});

it('opens an incident, an uptime issue and a down alert after two failed checks, and closes them on recovery', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $record = app(RecordCheck::class);

    $record($monitor, uptime_up(), 1);
    uptime_tick($this);
    $monitor->forceFill(['next_check_at' => $this->now->addSeconds(60)])->save(); // as DispatchChecks claimed it
    $record($monitor, uptime_down(), 2);
    expect($monitor->refresh()->status)->toBe(MonitorStatus::Up)
        ->and(Incident::query()->count())->toBe(0)
        // A failed check is retried sooner than the interval.
        ->and($monitor->next_check_at->equalTo($this->now->addSeconds(30)))->toBeTrue();

    $started = $this->now;
    uptime_tick($this, 30);
    $record($monitor, uptime_down(), 2);

    $incident = Incident::query()->sole();
    $issue = Issue::query()->where('kind', IssueKind::Uptime)->sole();
    expect($monitor->refresh()->status)->toBe(MonitorStatus::Down)
        ->and($incident->started_at->equalTo($started))->toBeTrue()
        ->and($incident->cause_kind)->toBe('status')
        ->and($incident->cause_status_code)->toBe(502)
        ->and($incident->issue_id)->toBe($issue->id)
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->meta['monitor_id'])->toBe(strtolower($monitor->id))
        ->and($this->alerts->ofType(RecordCheck::ALERT_DOWN))->toHaveCount(1)
        // Insights does not announce uptime issues itself.
        ->and(collect($this->alerts->raised)->pluck('type')->filter(fn ($t) => str_starts_with($t, 'insights.'))->all())->toBe([]);

    // Still down: no new alert.
    uptime_tick($this, 30);
    $record($monitor, uptime_down(), 2);
    expect($this->alerts->ofType(RecordCheck::ALERT_DOWN))->toHaveCount(1);

    uptime_tick($this);
    $record($monitor, uptime_up(), 1);
    expect($monitor->refresh()->status)->toBe(MonitorStatus::Down);
    uptime_tick($this);
    $record($monitor, uptime_up(), 1);

    $recovered = $this->alerts->ofType(RecordCheck::ALERT_RECOVERED);
    expect($monitor->refresh()->status)->toBe(MonitorStatus::Up)
        ->and($incident->refresh()->resolved_at)->not->toBeNull()
        ->and($incident->duration_seconds)->toBe(180)
        ->and($incident->resolution)->toBe('recovered')
        ->and($issue->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and($recovered)->toHaveCount(1)
        ->and($recovered[0]->resolves)->toBeTrue()
        ->and($recovered[0]->dedupKey)->toBe($this->alerts->ofType(RecordCheck::ALERT_DOWN)[0]->dedupKey)
        ->and($recovered[0]->body)->toBe('Back up after 3 min.');

    // The next outage regresses the same issue.
    uptime_tick($this);
    $record($monitor, uptime_down(), 2);
    uptime_tick($this, 30);
    $record($monitor, uptime_down(), 2);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open)->and($issue->regressions)->toBe(1)
        ->and(Incident::query()->count())->toBe(2);
});

it('opens no incident for an automatic monitor that has never been up', function () {
    $monitor = uptime_monitor($this->organization->id, ['source' => MonitorSource::Auto, 'auto_key' => 'site:x:shop.example.com']);

    foreach (range(1, 4) as $_) {
        app(RecordCheck::class)($monitor, uptime_down(), 2);
        uptime_tick($this);
    }

    expect($monitor->refresh()->status)->toBe(MonitorStatus::Pending)
        ->and(Incident::query()->count())->toBe(0)
        ->and($this->alerts->raised)->toBe([]);
});

it('holds the down alert while a monitor flaps and announces the recovery once it is stable', function () {
    config(['uptime.flapping.hold_minutes' => 15]);
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $record = app(RecordCheck::class);

    foreach (range(1, 5) as $_) {
        foreach ([uptime_down(), uptime_down(), uptime_up(), uptime_up()] as $result) {
            $record($monitor, $result, 1);
            uptime_tick($this);
        }
    }

    // From the third incident within the hour on, the down alert stays raised through the flaps.
    expect(Incident::query()->count())->toBe(5)
        ->and(Incident::query()->whereNull('resolved_at')->count())->toBe(0)
        ->and($monitor->refresh()->flapping_until)->not->toBeNull()
        ->and($this->alerts->ofType(RecordCheck::ALERT_DOWN))->toHaveCount(3)
        ->and($this->alerts->ofType(RecordCheck::ALERT_RECOVERED))->toHaveCount(2);

    uptime_tick($this, 16 * 60);
    $record($monitor, uptime_up(), 1);
    expect($this->alerts->ofType(RecordCheck::ALERT_RECOVERED))->toHaveCount(3)
        ->and($this->alerts->ofType(RecordCheck::ALERT_DOWN))->toHaveCount(3);
});

it('rolls checks up into hourly and daily uptime and response times', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $record = app(RecordCheck::class);

    foreach ([uptime_up(100), uptime_up(300), uptime_down(), uptime_down(), uptime_up(200), uptime_up(200)] as $result) {
        $record($monitor, $result, 1);
        uptime_tick($this);
    }

    $hour = DB::table('uptime_rollups')->where('monitor_id', $monitor->id)->where('period', 'hour')->sole();
    expect((int) $hour->checks)->toBe(6)
        // The first failure was not yet confirmed: only the second and the first recovery check count as down.
        ->and((int) $hour->down_checks)->toBe(2)
        ->and((int) $hour->latency_count)->toBe(4)
        ->and((int) $hour->latency_sum_ms)->toBe(800)
        ->and((int) $hour->latency_max_ms)->toBe(300);

    $stats = app(UptimeStats::class);
    $uptime = $stats->percentages([$monitor->id])[$monitor->id];
    expect($uptime['24h'])->toBe(66.667)->and($uptime['90d'])->toBe(66.667);

    $days = $stats->days([$monitor->id], 90)[$monitor->id];
    expect($days)->toHaveCount(90)->and(end($days)['uptime'])->toBe(66.667)->and($days[0]['uptime'])->toBeNull();

    expect($stats->series($monitor->id, '24h'))->toHaveCount(6)
        ->and($stats->series($monitor->id, '7d')[0])->toMatchArray(['total' => 200, 'max' => 300, 'dns' => 2, 'connect' => 10, 'tls' => 20, 'ttfb' => 70]);
});

it('alerts on slow responses held for five minutes', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true, 'slow_ms' => 1000]);

    foreach (range(1, 8) as $_) {
        app(RecordCheck::class)($monitor, uptime_up(2500), 1);
        uptime_tick($this);
    }

    expect($this->alerts->ofType(RecordCheck::ALERT_SLOW))->toHaveCount(1);

    foreach (range(1, 5) as $_) {
        app(RecordCheck::class)($monitor, uptime_up(100), 1);
        uptime_tick($this);
    }

    expect(collect($this->alerts->ofType(RecordCheck::ALERT_SLOW))->where('resolves', true))->toHaveCount(1);
});

it('raises a TLS problem alert and issue, and clears them when the chain is fixed', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $report = fn (?string $problem, int $days = 60) => new TlsReport($problem, 'shop.example.com', 'R11', null, $this->now->addDays($days)->toDateTimeImmutable(), true, $problem === null, 1);

    app(RecordCheck::class)($monitor, uptime_up()->withTls($report(TlsReport::INTERMEDIATE_MISSING)), 1);

    $alert = $this->alerts->ofType(RecordCheck::ALERT_TLS)[0];
    expect($alert->body)->toContain('intermediate certificate')
        ->and(Issue::query()->where('kind', IssueKind::Uptime)->where('meta->problem', 'tls')->sole()->status)->toBe(IssueStatus::Open)
        ->and($monitor->refresh()->tls['problem'])->toBe('intermediate_missing');

    uptime_tick($this, 3600);
    app(RecordCheck::class)($monitor, uptime_up()->withTls($report(null)), 1);
    expect(collect($this->alerts->ofType(RecordCheck::ALERT_TLS))->where('resolves', true))->toHaveCount(1)
        ->and(Issue::query()->where('meta->problem', 'tls')->sole()->status)->toBe(IssueStatus::Resolved);

    // User monitors: expiring soon is a problem; site domains leave that to Edge.
    uptime_tick($this, 3600);
    app(RecordCheck::class)($monitor, uptime_up()->withTls($report(null, 5)), 1);
    expect(collect($this->alerts->ofType(RecordCheck::ALERT_TLS))->where('resolves', false))->toHaveCount(2);

    $auto = uptime_monitor($this->organization->id, ['source' => MonitorSource::Auto, 'auto_key' => 'site:y:a.example.com', 'status' => MonitorStatus::Up, 'armed' => true]);
    expect(RecordCheck::tlsProblem($auto, $report(null, 5), $this->now))->toBeNull()
        ->and(RecordCheck::tlsProblem($auto, $report(TlsReport::EXPIRED), $this->now))->toBe('expired');
});

it('inspects certificates of https monitors once an hour', function () {
    $monitor = uptime_monitor($this->organization->id);

    expect($monitor->tlsDue($this->now))->toBeTrue();
    $monitor->forceFill(['tls_checked_at' => $this->now->subMinutes(10)])->save();
    expect($monitor->tlsDue($this->now))->toBeFalse()
        ->and(uptime_monitor($this->organization->id, ['url' => 'http://plain.example.com/'])->tlsDue($this->now))->toBeFalse();
});

it('prunes old checks, rollups and incidents', function () {
    $monitor = uptime_monitor($this->organization->id);
    Check::query()->create(['monitor_id' => $monitor->id, 'checked_at' => $this->now->subDays(8), 'ok' => true]);
    Check::query()->create(['monitor_id' => $monitor->id, 'checked_at' => $this->now->subDays(6), 'ok' => true]);
    DB::table('uptime_rollups')->insert([
        ['monitor_id' => $monitor->id, 'period' => 'hour', 'bucket_start' => $this->now->subDays(31)],
        ['monitor_id' => $monitor->id, 'period' => 'day', 'bucket_start' => $this->now->subDays(31)],
        ['monitor_id' => $monitor->id, 'period' => 'day', 'bucket_start' => $this->now->subDays(91)],
    ]);
    Incident::query()->create(['organization_id' => $this->organization->id, 'monitor_id' => $monitor->id, 'started_at' => $this->now->subDays(100), 'confirmed_at' => $this->now->subDays(100), 'resolved_at' => $this->now->subDays(99)]);

    (new PruneUptime)->handle();

    expect(Check::query()->count())->toBe(1)
        ->and(DB::table('uptime_rollups')->pluck('period')->all())->toBe(['day'])
        ->and(Incident::query()->count())->toBe(0);
});
