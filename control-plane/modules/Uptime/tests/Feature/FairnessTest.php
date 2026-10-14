<?php

use Carbon\CarbonImmutable;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\Role;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Actions\SyncSiteMonitors;
use Falak\Uptime\Application\Jobs\DispatchChecks;
use Falak\Uptime\Application\Jobs\RunCheck;
use Falak\Uptime\Application\Jobs\SyncAllSiteMonitors;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/helpers.php';

/*
 * One organization's monitors must not starve the others: in-flight cap per organization, leases, unique jobs,
 * a load budget on settings, check delay measured and alerted.
 */

beforeEach(function () {
    ['prober' => $this->prober, 'alerts' => $this->alerts] = uptime_fakes();
    [, $this->noisy] = memberOf(null, Role::Owner);
    [, $this->quiet] = memberOf(null, Role::Owner);
    $this->now = CarbonImmutable::parse('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow($this->now);
    Carbon::setTestNow($this->now);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function uptime_dispatch(): int
{
    return app()->call([new DispatchChecks, 'handle']);
}

it('caps queued and running checks per organization, oldest due first, and serves every organization', function () {
    Queue::fake();
    config(['uptime.max_in_flight_per_org' => 2]);

    foreach (range(1, 10) as $i) {
        uptime_monitor($this->noisy->id, ['name' => "n{$i}", 'next_check_at' => $this->now->subMinutes(20 - $i)]);
    }
    $quiet = uptime_monitor($this->quiet->id, ['next_check_at' => $this->now->subSecond()]);

    expect(uptime_dispatch())->toBe(3);
    Queue::assertPushedOn('uptime', RunCheck::class, fn (RunCheck $job) => $job->monitorId === $quiet->id);
    expect(Monitor::query()->where('organization_id', $this->noisy->id)->whereNotNull('claimed_at')->orderBy('name')->pluck('name')->all())->toBe(['n1', 'n2']);

    // Both noisy slots are taken: nothing more for it until a check is recorded.
    expect(uptime_dispatch())->toBe(0);

    app(RecordCheck::class)(Monitor::query()->where('name', 'n1')->sole(), new ProbeResult(true, totalMs: 50), 1);
    expect(Monitor::query()->where('name', 'n1')->sole()->claimed_at)->toBeNull()
        ->and(uptime_dispatch())->toBe(1)
        ->and(Monitor::query()->where('name', 'n3')->sole()->claimed_at)->not->toBeNull();
});

it('releases a lost claim when its lease runs out', function () {
    Queue::fake();
    config(['uptime.max_in_flight_per_org' => 1]);
    $monitor = uptime_monitor($this->noisy->id);
    uptime_dispatch();

    $this->now = $this->now->addSeconds(RunCheck::leaseSeconds() + 1);
    CarbonImmutable::setTestNow($this->now);

    expect(uptime_dispatch())->toBe(1)->and($monitor->refresh()->claimed_at->equalTo($this->now))->toBeTrue();
});

it('keeps at most one check per monitor queued or running', function () {
    $job = new RunCheck('01J8MONITOR000000000000000');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01J8MONITOR000000000000000')
        ->and($job->uniqueFor())->toBe(RunCheck::leaseSeconds())
        // Two attempts at the longest timeout, the retry delay and DNS fit in the job timeout, below retry_after (90).
        ->and($job->timeout)->toBeGreaterThan(2 * (int) config('uptime.max_timeout') + 1)
        ->and($job->timeout)->toBeLessThan(90);
});

it('measures each organization\'s check delay and alerts when it stays high', function () {
    Queue::fake();
    config(['uptime.max_in_flight_per_org' => 1, 'uptime.lag_alert_seconds' => 120]);
    uptime_monitor($this->noisy->id, ['next_check_at' => $this->now->subSeconds(10)]);
    uptime_monitor($this->noisy->id, ['next_check_at' => $this->now->subSeconds(200)]);
    uptime_monitor($this->quiet->id, ['next_check_at' => $this->now->addSeconds(30)]);

    uptime_dispatch();
    expect(DispatchChecks::lag($this->noisy->id))->toBe(200)->and(DispatchChecks::lag($this->quiet->id))->toBe(0);

    $this->now = $this->now->addSeconds(301);
    CarbonImmutable::setTestNow($this->now);
    Carbon::setTestNow($this->now);
    uptime_dispatch();

    $alerts = $this->alerts->ofType(DispatchChecks::ALERT_DELAYED);
    expect($alerts)->toHaveCount(1)->and($alerts[0]->organizationId)->toBe($this->noisy->id);
});

it('refuses monitor settings past the organization\'s load budget', function () {
    config(['uptime.org_budget' => 3]);
    [$user] = memberOf($this->noisy, Role::Developer);
    $this->actingAs($user);

    // Each: 2 attempts x 20 s / 30 s = 1.33 workers in the worst case.
    $input = ['type' => 'http', 'name' => 'A', 'url' => 'https://a.example.com/', 'interval_seconds' => 30, 'timeout_seconds' => 20];
    $first = $this->postJson('/uptime/monitors', $input)->assertCreated()->json('data.id');
    $this->postJson('/uptime/monitors', $input)->assertCreated();
    $this->postJson('/uptime/monitors', $input)->assertUnprocessable()->assertJsonValidationErrors('interval_seconds')
        ->assertJsonPath('errors.interval_seconds.0', fn (string $message) => str_contains($message, 'keep 4.0 workers busy (limit 3)'));

    // A slower interval fits.
    $this->postJson('/uptime/monitors', [...$input, 'interval_seconds' => 120])->assertCreated();

    // Raising the first one's timeout would not.
    $this->putJson("/uptime/monitors/{$first}", ['interval_seconds' => 30, 'timeout_seconds' => 20])->assertOk();
    $this->putJson("/uptime/monitors/{$first}", ['interval_seconds' => 30, 'timeout_seconds' => 20, 'name' => 'A2'])->assertOk();
    $this->postJson("/uptime/monitors/{$first}/pause")->assertOk();
    $this->postJson('/uptime/monitors', $input)->assertCreated();
    $this->postJson("/uptime/monitors/{$first}/resume")->assertUnprocessable()->assertJsonValidationErrors('interval_seconds');

    $this->getJson('/uptime/monitors/'.$first.'/data')->assertOk();
    $this->get('/observability/uptime')->assertInertia(fn ($page) => $page->where('limits.budget', 3)->where('limits.load', 3));
});

it('runs its scheduler jobs and the per-organization sync on the uptime queue', function () {
    Queue::fake();

    (new SyncAllSiteMonitors)->handle(app(OrganizationDirectory::class), app(SyncSiteMonitors::class));

    Queue::assertPushedOn('uptime', SyncAllSiteMonitors::class, fn (SyncAllSiteMonitors $job) => $job->organizationId === $this->noisy->id);
    Queue::assertPushedOn('uptime', SyncAllSiteMonitors::class, fn (SyncAllSiteMonitors $job) => $job->organizationId === $this->quiet->id);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => in_array($event->description, ['uptime:dispatch', 'uptime:sync'], true));
    expect($events)->toHaveCount(2);
});

it('keeps an organization within its budget while monitors stay up', function () {
    $monitor = uptime_monitor($this->noisy->id, ['status' => MonitorStatus::Up, 'armed' => true, 'interval_seconds' => 60]);

    expect(DispatchChecks::load($this->noisy->id))->toBe(2 * 10 / 60)
        ->and(round(DispatchChecks::load($this->noisy->id, $monitor->forceFill(['interval_seconds' => 30])), 3))->toBe(round(2 * 10 / 30, 3));
});
