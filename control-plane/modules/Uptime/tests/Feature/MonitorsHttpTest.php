<?php

use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Events\DomainAdded;
use Falak\Edge\Tests\Support\FakeSiteDirectory;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Events\SiteDeleted;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Application\Actions\SyncSiteMonitors;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Domain\Probing\AddressGuard;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Falak\Uptime\Tests\Support\FakeDomainRecords;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Edge/tests/Support/helpers.php';

beforeEach(function () {
    ['prober' => $this->prober, 'alerts' => $this->alerts] = uptime_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->sites = new FakeSiteDirectory;
    app()->instance(SiteDirectory::class, $this->sites);
    $this->domains = new FakeDomainRecords;
    app()->instance(DomainRecords::class, $this->domains);
    $this->site = edge_site($this->sites, $this->organization->id, [], ['name' => 'Shop', 'testDomain' => 'shop.test.falak.dev']);
});

it('creates one monitor per site domain and removes those whose domain is gone', function () {
    $this->domains->domains = [
        ['site_id' => $this->site->id, 'name' => 'shop.example.com'],
        ['site_id' => $this->site->id, 'name' => '*.shop.example.com'],
    ];
    app(SyncSiteMonitors::class)->organization($this->organization->id);
    app(SyncSiteMonitors::class)->organization($this->organization->id);

    expect(Monitor::query()->orderBy('url')->pluck('url')->all())->toBe(['https://shop.example.com/', 'https://shop.test.falak.dev/'])
        ->and(Monitor::query()->where('url', 'https://shop.example.com/')->sole()->toArray())->toMatchArray(['name' => 'Shop (shop.example.com)', 'source' => 'auto', 'site_id' => $this->site->id]);

    $this->domains->domains = [];
    DomainAdded::dispatch('dom', $this->site->id, $this->organization->id, 'x', false); // queued sync (sync queue in tests)

    expect(Monitor::query()->pluck('url')->all())->toBe(['https://shop.test.falak.dev/']);
});

it('drops automatic monitors with their site and detaches user ones', function () {
    app(SyncSiteMonitors::class)->organization($this->organization->id);
    $mine = uptime_monitor($this->organization->id, ['site_id' => $this->site->id]);

    SiteDeleted::dispatch($this->site->id, $this->organization->id, $this->site->slug, []);

    expect(Monitor::query()->pluck('id')->all())->toBe([$mine->id])->and($mine->refresh()->site_id)->toBeNull();
});

it('serves the site tab with uptime and incidents', function () {
    $monitor = uptime_monitor($this->organization->id, ['site_id' => $this->site->id, 'status' => MonitorStatus::Up, 'armed' => true]);
    app(RecordCheck::class)($monitor, new ProbeResult(true, statusCode: 200, totalMs: 90), 1);

    $this->getJson("/uptime/sites/{$this->site->id}")->assertOk()
        ->assertJsonPath('data.monitors.0.id', $monitor->id)
        ->assertJsonPath('data.monitors.0.uptime.24h', 100)
        ->assertJsonPath('data.monitors.0.last_total_ms', 90)
        ->assertJsonPath('data.can.manage', true);

    $this->getJson("/uptime/monitors/{$monitor->id}/data?range=24h")->assertOk()
        ->assertJsonCount(1, 'data.series')
        ->assertJsonCount(90, 'data.days')
        ->assertJsonPath('data.range', '24h');

    $this->get('/observability/uptime')->assertOk()->assertInertia(fn ($page) => $page->component('Uptime/Index', false)->has('monitors', 1));
});

it('creates, updates, pauses, resumes and deletes user monitors', function () {
    $id = $this->postJson('/uptime/monitors', [
        'type' => 'http', 'name' => 'API', 'url' => 'https://api.example.com/health', 'keyword' => 'ok', 'interval_seconds' => 30, 'site_id' => $this->site->id,
    ])->assertCreated()->assertJsonPath('data.target', 'https://api.example.com/health')->json('data.id');

    $this->postJson('/uptime/monitors', ['type' => 'tcp', 'name' => 'SMTP', 'host' => 'mail.example.com', 'port' => 25])->assertCreated();
    $this->postJson('/uptime/monitors', ['type' => 'tls', 'name' => 'IMAPS', 'host' => 'mail.example.com'])->assertCreated()->assertJsonPath('data.port', 443);

    $this->putJson("/uptime/monitors/{$id}", ['interval_seconds' => 120, 'expected_status' => '200', 'slow_ms' => 800])->assertOk()
        ->assertJsonPath('data.interval_seconds', 120)->assertJsonPath('data.expected_status', '200');

    $this->postJson("/uptime/monitors/{$id}/pause")->assertOk()->assertJsonPath('data.paused', true);
    $this->postJson("/uptime/monitors/{$id}/resume")->assertOk()->assertJsonPath('data.paused', false);
    $this->deleteJson("/uptime/monitors/{$id}")->assertNoContent();

    expect(Monitor::query()->count())->toBe(2)
        ->and(AuditEntry::query()->where('action', 'like', 'uptime.monitor.%')->pluck('action')->all())
        ->toContain('uptime.monitor.created', 'uptime.monitor.updated', 'uptime.monitor.paused', 'uptime.monitor.resumed', 'uptime.monitor.deleted');
});

it('refuses private targets, bad input and monitors beyond the limit', function (array $input, string $field) {
    $this->postJson('/uptime/monitors', ['name' => 'X', ...$input])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'loopback url' => [['type' => 'http', 'url' => 'http://127.0.0.1:8080/'], 'url'],
    'metadata' => [['type' => 'http', 'url' => 'http://169.254.169.254/latest/meta-data/'], 'url'],
    'internal name' => [['type' => 'http', 'url' => 'http://redis.internal/'], 'url'],
    'credentials' => [['type' => 'http', 'url' => 'https://u:p@example.com/'], 'url'],
    'scheme' => [['type' => 'http', 'url' => 'file:///etc/passwd'], 'url'],
    'encoded host' => [['type' => 'http', 'url' => 'http://127%2d0%2d0%2d1.1-1-1-1.sslip.io:8765/secret.txt'], 'url'],
    'encoded dots' => [['type' => 'http', 'url' => 'http://127%2e0%2e0%2e1.nip.io/'], 'url'],
    'encoded tcp host' => [['type' => 'tcp', 'host' => '127%2e0%2e0%2e1.nip.io', 'port' => 22], 'host'],
    'alibaba metadata' => [['type' => 'tcp', 'host' => '100.100.100.200', 'port' => 80], 'host'],
    'control plane' => [['type' => 'http', 'url' => 'https://localhost/'], 'url'],
    'private host' => [['type' => 'tcp', 'host' => '10.0.0.5', 'port' => 5432], 'host'],
    'mapped' => [['type' => 'tcp', 'host' => '::ffff:10.0.0.5', 'port' => 5432], 'host'],
    'no port' => [['type' => 'tcp', 'host' => 'mail.example.com'], 'port'],
    'interval' => [['type' => 'http', 'url' => 'https://a.example.com/', 'interval_seconds' => 5], 'interval_seconds'],
    'status' => [['type' => 'http', 'url' => 'https://a.example.com/', 'expected_status' => '2xx,9xx'], 'expected_status'],
    'site' => [['type' => 'http', 'url' => 'https://a.example.com/', 'site_id' => '01J8SITE000000000000000000'], 'site_id'],
]);

it('limits user monitors per organization, not site monitors', function () {
    config(['uptime.max_monitors' => 1]);
    app(SyncSiteMonitors::class)->organization($this->organization->id);
    $this->postJson('/uptime/monitors', ['type' => 'http', 'name' => 'A', 'url' => 'https://a.example.com/'])->assertCreated();
    $this->postJson('/uptime/monitors', ['type' => 'http', 'name' => 'B', 'url' => 'https://b.example.com/'])->assertJsonValidationErrors('name');
});

it('lets operators allow private targets', function () {
    config(['uptime.allow_private_targets' => true]);
    app()->forgetInstance(AddressGuard::class);

    $this->postJson('/uptime/monitors', ['type' => 'tcp', 'name' => 'DB', 'host' => '10.0.0.5', 'port' => 5432])->assertCreated();
});

it('keeps site monitors from being deleted, only paused', function () {
    app(SyncSiteMonitors::class)->organization($this->organization->id);
    $auto = Monitor::query()->sole();

    $this->deleteJson("/uptime/monitors/{$auto->id}")->assertUnprocessable();
    $this->putJson("/uptime/monitors/{$auto->id}", ['url' => 'https://evil.example.com/', 'keyword' => 'Shop'])->assertOk();
    expect($auto->refresh()->url)->toBe('https://shop.test.falak.dev/')->and($auto->keyword)->toBe('Shop');
});

it('pausing closes the open incident without a recovery alert storm', function () {
    $monitor = uptime_monitor($this->organization->id, ['status' => MonitorStatus::Up, 'armed' => true]);
    $down = ProbeResult::failure(ErrorKind::Connect, 'refused');
    app(RecordCheck::class)($monitor, $down, 2);
    app(RecordCheck::class)($monitor, $down, 2);

    $this->postJson("/uptime/monitors/{$monitor->id}/pause")->assertOk();
    app(RecordCheck::class)($monitor->refresh(), $down, 2);

    expect($monitor->incidents()->sole()->resolution)->toBe('paused')
        ->and(Check::query()->count())->toBe(2)
        ->and($this->alerts->ofType(RecordCheck::ALERT_RECOVERED)[0]->title)->toBe('Monitor paused: Shop');
});

it('lets viewers read but not change, and hides other organizations', function () {
    $monitor = uptime_monitor($this->organization->id);
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->getJson("/uptime/monitors/{$monitor->id}/data")->assertOk();
    $this->putJson("/uptime/monitors/{$monitor->id}", ['interval_seconds' => 120])->assertForbidden();
    $this->postJson('/uptime/monitors', ['type' => 'http', 'name' => 'A', 'url' => 'https://a.example.com/'])->assertForbidden();

    actingAsMember(Role::Owner);
    $this->getJson("/uptime/monitors/{$monitor->id}/data")->assertNotFound();
    $this->getJson("/uptime/sites/{$this->site->id}")->assertNotFound();
});
