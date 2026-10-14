<?php

use Carbon\CarbonImmutable;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Tests\Support\FakeSiteDirectory;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Actions\SyncSiteMonitors;
use Falak\Uptime\Application\Jobs\RunCheck;
use Falak\Uptime\Application\Probing\Connectivity;
use Falak\Uptime\Application\Probing\ConnectOutcome;
use Falak\Uptime\Application\Probing\Transport;
use Falak\Uptime\Application\Queries\UptimeStats;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Tests\Support\FakeDomainRecords;
use Falak\Uptime\Tests\Support\FakeTransport;
use Falak\Uptime\Tests\Support\PreviewProjects;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/PreviewProjects.php';
require_once __DIR__.'/../../../Edge/tests/Support/helpers.php';

/*
 * Time-weighted uptime, backoff of never-up site monitors, incidents after a resume, an offline control plane,
 * preview environments.
 */

beforeEach(function () {
    ['prober' => $this->prober, 'alerts' => $this->alerts] = uptime_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->now = CarbonImmutable::parse('2026-10-15 00:00:00');
    uptime_at($this, $this->now);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function uptime_at(object $test, CarbonImmutable $at): void
{
    $test->now = $at;
    CarbonImmutable::setTestNow($at);
    Carbon::setTestNow($at);
}

function uptime_fail(): ProbeResult
{
    return ProbeResult::failure(ErrorKind::Connect, 'refused', ['totalMs' => 5]);
}

it('weighs uptime by time, not by the number of checks', function () {
    // One hour up (checked every 60 s), then one hour down (checked every 30 s while failing).
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true, 'interval_seconds' => 60]);
    $record = app(RecordCheck::class);

    foreach (range(1, 60) as $_) {
        $record($monitor, new ProbeResult(true, totalMs: 100), 1);
        uptime_at($this, $this->now->addSeconds(60));
    }

    uptime_at($this, $this->now->subSeconds(30));

    foreach (range(1, 120) as $_) {
        $record($monitor, uptime_fail(), 2);
        uptime_at($this, $this->now->addSeconds(30));
    }

    $stats = app(UptimeStats::class);
    $uptime = $stats->percentages([$monitor->id])[$monitor->id]['24h'];
    $today = collect($stats->days([$monitor->id], 1)[$monitor->id])->last();

    // 60 x 60 s up + 1 unconfirmed failure x 30 s, then 119 x 30 s down: 3,570 of 7,200 seconds down.
    // By count it would be 1 - 119/180 = 33.9%.
    expect($uptime)->toBe(50.417)
        ->and($today['down_minutes'])->toBe(60)
        ->and(Check::query()->count())->toBe(180);
});

it('backs off a site domain monitor that has never been up, and checks others at their interval', function () {
    $auto = uptime_monitor($this->organization->id, ['source' => MonitorSource::Auto, 'auto_key' => 'site:x:new.example.com', 'interval_seconds' => 60]);
    $record = app(RecordCheck::class);
    $delays = [];

    foreach (range(1, 9) as $_) {
        $record($auto, uptime_fail(), 2);
        $delays[] = (int) $this->now->diffInSeconds($auto->refresh()->next_check_at);
    }

    expect($delays)->toBe([60, 120, 240, 480, 960, 1920, 3600, 3600, 3600]);

    // Once up, the normal interval; failures of an armed monitor are confirmed after 30 s.
    $record($auto, new ProbeResult(true, totalMs: 80), 1);
    expect((int) $this->now->diffInSeconds($auto->refresh()->next_check_at))->toBe(60);
    $record($auto, uptime_fail(), 2);
    expect((int) $this->now->diffInSeconds($auto->refresh()->next_check_at))->toBe(30);
});

it('never starts an incident before the monitor was resumed', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $record = app(RecordCheck::class);

    $record($monitor, new ProbeResult(true, totalMs: 50), 1);
    uptime_at($this, $this->now->addMinute());
    $record($monitor, uptime_fail(), 2); // one unconfirmed failure, then paused

    $this->postJson("/uptime/monitors/{$monitor->id}/pause")->assertOk();
    uptime_at($this, $this->now->addHours(5));
    $this->postJson("/uptime/monitors/{$monitor->id}/resume")->assertOk();
    $resumed = $this->now;

    uptime_at($this, $this->now->addMinute());
    $record($monitor->refresh(), uptime_fail(), 2);
    uptime_at($this, $this->now->addSeconds(30));
    $record($monitor->refresh(), uptime_fail(), 2);

    expect(Incident::query()->sole()->started_at->equalTo($resumed->addMinute()))->toBeTrue()
        ->and($monitor->refresh()->resumed_at->equalTo($resumed))->toBeTrue();
});

it('records checks as unknown while the control plane is offline, without incidents or alerts', function () {
    config(['uptime.canaries' => ['192.0.2.10:443', '192.0.2.11:443']]);
    app()->instance(Transport::class, new FakeTransport(tcp: new ConnectOutcome(false, 3000, ErrorKind::Timeout, 'timed out')));
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $this->prober->queue(uptime_fail(), uptime_fail());

    foreach (range(1, 3) as $_) {
        app()->call([new RunCheck($monitor->id), 'handle']);
    }

    expect(Check::query()->pluck('error_kind')->unique()->all())->toBe([ErrorKind::Offline->value])
        ->and($monitor->refresh()->status)->toBe(MonitorStatus::Up)
        ->and($monitor->consecutive_failures)->toBe(0)
        ->and(Incident::query()->count())->toBe(0)
        ->and($this->alerts->raised)->toBe([])
        ->and(Connectivity::offlineSince())->not->toBeNull();

    $this->get('/observability/uptime')->assertInertia(fn ($page) => $page->where('limits.offline_since', fn ($value) => $value !== null));
});

it('counts failures normally while a canary answers', function () {
    config(['uptime.canaries' => ['192.0.2.10:443']]);
    app()->instance(Transport::class, new FakeTransport);
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $this->prober->queue(uptime_fail());

    app()->call([new RunCheck($monitor->id), 'handle']);

    expect(Check::query()->sole()->error_kind)->toBe('connect')->and($monitor->refresh()->consecutive_failures)->toBe(1);
});

it('does not monitor sites of preview environments', function () {
    $sites = new FakeSiteDirectory;
    app()->instance(SiteDirectory::class, $sites);
    $production = edge_site($sites, $this->organization->id, [], ['name' => 'Shop', 'testDomain' => 'shop.test.falak.dev']);
    $preview = edge_site($sites, $this->organization->id, [], ['name' => 'Shop PR 1', 'testDomain' => 'shop-pr-1.test.falak.dev']);
    app()->instance(DomainRecords::class, new FakeDomainRecords([['site_id' => $preview->id, 'name' => 'pr-1.example.com']]));
    app()->instance(ProjectDirectory::class, new PreviewProjects(app(ProjectDirectory::class), [$preview->id]));

    app(SyncSiteMonitors::class)->organization($this->organization->id);

    expect(Monitor::query()->pluck('site_id')->all())->toBe([$production->id]);
});
