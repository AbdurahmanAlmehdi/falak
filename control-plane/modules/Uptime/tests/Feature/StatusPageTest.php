<?php

use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Uptime\Application\Actions\RecordCheck;
use Falak\Uptime\Domain\Enums\ErrorKind;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Models\StatusPage;
use Falak\Uptime\Domain\Probing\ProbeResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    uptime_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->project = app(ProjectDirectory::class)->forOrganization($this->organization->id)[0];
    $this->api = uptime_monitor($this->organization->id, [
        'name' => 'internal-api-prod', 'url' => 'https://api.secret-host.example.com/health', 'status' => MonitorStatus::Up, 'armed' => true,
    ]);
    $this->web = uptime_monitor($this->organization->id, ['name' => 'web', 'url' => 'https://www.example.com/', 'status' => MonitorStatus::Up, 'armed' => true]);
});

function uptime_publish(object $test, array $overrides = []): void
{
    $test->putJson("/uptime/status-pages/{$test->project->id}", [
        'slug' => 'acme',
        'title' => 'Acme status',
        'description' => 'Our services',
        'enabled' => true,
        'monitors' => [['id' => $test->api->id, 'display_name' => 'API'], ['id' => $test->web->id, 'display_name' => 'Website']],
        ...$overrides,
    ])->assertOk();
}

it('publishes only the chosen names, states, bars and incidents', function () {
    $down = ProbeResult::failure(ErrorKind::Connect, 'connect to 10.9.8.7 refused', ['ip' => '93.184.216.34', 'statusCode' => 502]);
    app(RecordCheck::class)($this->api, $down, 2);
    app(RecordCheck::class)($this->api, $down, 2);
    uptime_publish($this);
    auth()->logout();

    $response = $this->get('/status/acme')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('Acme status')
        ->assertSee('API')
        ->assertSee('Website')
        ->assertSee('Partial outage')
        ->assertSee('Ongoing');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')
        ->and($response->headers->getCookies())->toBe([]);

    foreach (['internal-api-prod', 'secret-host', '10.9.8.7', '93.184.216.34', '502', 'refused', $this->api->id, $this->organization->id] as $leak) {
        $response->assertDontSee($leak);
    }
});

it('serves from cache, and 404s for disabled or unknown pages', function () {
    uptime_publish($this, ['enabled' => false]);
    $this->get('/status/acme')->assertNotFound();
    $this->get('/status/nope')->assertNotFound();
    $this->get('/status/..%2Fetc')->assertNotFound();

    uptime_publish($this);
    $this->get('/status/acme')->assertOk()->assertSee('All systems operational');

    // Cached for a minute: a change shows up after the cache expires (saving the page clears it).
    $this->api->forceFill(['status' => MonitorStatus::Down])->save();
    $this->get('/status/acme')->assertSee('All systems operational');
    Cache::forget('uptime:status-page:acme');
    $this->get('/status/acme')->assertSee('Partial outage');
});

it('rate-limits the public page per address', function () {
    config(['uptime.status_page.rate_per_minute' => 2]);
    uptime_publish($this);

    $this->get('/status/acme')->assertOk();
    $this->get('/status/acme')->assertOk();
    $this->get('/status/acme')->assertTooManyRequests();
});

it('validates slugs and monitors of other organizations', function () {
    [, $other] = memberOf(null, Role::Owner);
    $foreign = uptime_monitor($other->id);

    $this->putJson("/uptime/status-pages/{$this->project->id}", ['slug' => 'Bad Slug', 'title' => 'x', 'enabled' => true, 'monitors' => []])->assertJsonValidationErrors('slug');
    $this->putJson("/uptime/status-pages/{$this->project->id}", ['slug' => 'acme', 'title' => 'x', 'enabled' => true, 'monitors' => [['id' => $foreign->id, 'display_name' => 'x']]])
        ->assertJsonValidationErrors('monitors.0.id');
});

it('refuses reserved and taken addresses with the same answer, also when the race is lost', function () {
    [, $other] = memberOf(null, Role::Owner);
    $theirs = app(ProjectDirectory::class)->forOrganization($other->id)[0];
    StatusPage::query()->create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'slug' => 'theirs', 'title' => 'x', 'enabled' => true]);

    foreach (['stripe', 'falak', 'github-status', 'status', 'theirs'] as $slug) {
        $this->putJson("/uptime/status-pages/{$this->project->id}", ['slug' => $slug, 'title' => 'x', 'enabled' => true, 'monitors' => []])
            ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'This address is not available.');
    }

    // Another organization takes the address between the check and the insert.
    StatusPage::creating(function (StatusPage $page) use ($other) {
        if ($page->slug === 'raced') {
            DB::table('uptime_status_pages')->insert(['id' => (string) str()->ulid(), 'organization_id' => $other->id, 'project_id' => (string) str()->ulid(), 'slug' => 'raced', 'title' => 'x', 'enabled' => true]);
        }
    });
    $this->putJson("/uptime/status-pages/{$this->project->id}", ['slug' => 'raced', 'title' => 'x', 'enabled' => true, 'monitors' => []])
        ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'This address is not available.');
    expect(StatusPage::query()->where('organization_id', $this->organization->id)->count())->toBe(0);
});

it('says how often the shown monitors are checked', function () {
    $this->api->forceFill(['interval_seconds' => 120])->save();
    $this->web->forceFill(['interval_seconds' => 300])->save();
    uptime_publish($this);

    $this->get('/status/acme')->assertSee('checked every 2 minutes')->assertDontSee('every minute');
});

it('needs uptime.publish to change status pages', function () {
    uptime_publish($this);
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);

    $this->get('/observability/uptime/status-pages')->assertOk()->assertInertia(fn ($page) => $page->component('Uptime/StatusPages', false)->where('can.publish', false)
        ->where('projects.0.page.slug', 'acme'));
    $this->putJson("/uptime/status-pages/{$this->project->id}", ['slug' => 'x1', 'title' => 'x', 'enabled' => true, 'monitors' => []])->assertForbidden();
    $this->deleteJson('/uptime/status-pages/'.StatusPage::query()->sole()->id)->assertForbidden();

    $this->actingAs($this->user);
    $this->deleteJson('/uptime/status-pages/'.StatusPage::query()->sole()->id)->assertNoContent();
    $this->get('/status/acme')->assertNotFound();
});
