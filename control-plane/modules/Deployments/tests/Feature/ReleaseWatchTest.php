<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Jobs\EvaluateReleaseWatch;
use Falak\Deployments\Application\Jobs\EvaluateReleaseWatches;
use Falak\Deployments\Application\Watch\Migrations;
use Falak\Deployments\Application\Watch\ReleaseWatcher;
use Falak\Deployments\Contracts\DeploymentBadges;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Enums\WatchTrigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Events\DeploymentRolledBack;
use Falak\Deployments\Events\ReleaseWatchTriggered;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Events\IssueOpened;
use Falak\Limits\Events\ServiceOomKilled;
use Falak\Limits\Events\ServiceRestartLoop;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Falak\Telemetry\Contracts\Data\RequestCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Edge access log counts by release id (the baseline reads the previous release, a round the new one).
 */
final class FakeAccessLogCounts implements AccessLogCounts
{
    /** @var array<string, RequestCounts> */
    public array $byRelease = [];

    public bool $unavailable = false;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(AccessLogCounts::class, $fake);

        return $fake;
    }

    public function forRelease(string $organizationId, string $siteId, string $releaseId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
    {
        if ($this->unavailable) {
            throw new RuntimeException('Loki is not configured.');
        }

        return $this->byRelease[$releaseId] ?? new RequestCounts(0, 0);
    }

    public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to): RequestCounts
    {
        return new RequestCounts(0, 0);
    }
}

function watch_settings(DeployWorld $world, array $attributes = []): void
{
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['watch_enabled' => true, ...$attributes])->save();
}

function watch_deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $releaseId = null): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, releaseId: $releaseId);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

/** One evaluation round of every open window, 30 s after the last one (the schedule). */
function watch_round(): void
{
    test()->travel(30)->seconds();
    app()->call([new EvaluateReleaseWatches, 'handle']);
}

function watch_of(Deployment $deployment): ?ReleaseWatch
{
    return ReleaseWatch::query()->find($deployment->id);
}

function watch_oom(DeployWorld $world, ?string $siteId = null, ?string $organizationId = null): void
{
    ServiceOomKilled::dispatch($organizationId ?? $world->organization->id, $world->servers[0]->id, 'web-1', 'site', $siteId ?? $world->site->id, $siteId ?? $world->site->id,
        'shop', 1, 512, '/sites/x', now()->toIso8601String());
}

it('opens no window when the site does not watch releases, for a first deployment, or for a rollback', function () {
    $world = deploy_world();
    $first = watch_deploy($world);
    $second = watch_deploy($world);

    expect(watch_of($second))->toBeNull(); // off by default

    watch_settings($world);
    $rollback = watch_deploy($world, Trigger::Rollback, $first->release_id);
    expect($rollback->status)->toBe(DeploymentStatus::Succeeded)
        ->and(watch_of($rollback))->toBeNull();

    $fresh = deploy_world();
    watch_settings($fresh);

    expect(watch_of(watch_deploy($fresh)))->toBeNull(); // first deployment: nothing to go back to
});

it('opens a window after a successful deployment and closes it when the time is up', function () {
    $world = deploy_world();
    watch_settings($world, ['watch_minutes' => 10]);
    $first = watch_deploy($world);
    $second = watch_deploy($world);

    $watch = watch_of($second);
    expect($watch->status)->toBe(WatchStatus::Watching)
        ->and($watch->release_id)->toBe($second->release_id)
        ->and($watch->previous_release_id)->toBe($first->release_id)
        ->and($watch->on_trigger)->toBe('rollback')
        ->and($watch->triggers)->toBe(['health' => true, 'health_failures' => 3, 'crashes' => true, 'errors' => true, 'issues' => false])
        ->and((int) $watch->started_at->diffInMinutes($watch->ends_at))->toBe(10);

    watch_round();
    expect(watch_of($second)->status)->toBe(WatchStatus::Watching)
        ->and(watch_of($second)->checks['health'])->toMatchArray(['ok' => true, 'failures' => 0, 'threshold' => 3])
        // Loki is not configured in tests: the 5xx trigger reports it instead of firing.
        ->and(watch_of($second)->checks['errors'])->toHaveKey('unavailable');

    $this->travel(11)->minutes();
    watch_round();

    expect(watch_of($second)->status)->toBe(WatchStatus::Passed)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBeFalse()
        ->and($second->refresh()->rolled_back)->toBeFalse();
});

it('rolls back when the health check fails N times in a row, and says why', function () {
    Event::fake([DeploymentRolledBack::class]);
    $world = deploy_world();
    watch_settings($world);
    $first = watch_deploy($world);
    $second = watch_deploy($world);

    deploy_http(['http://203.0.113.1' => 500]);
    watch_round();
    watch_round();
    expect(watch_of($second)->health_failures)->toBe(2);

    // A healthy answer starts the count over.
    deploy_http([]);
    watch_round();
    expect(watch_of($second)->health_failures)->toBe(0);

    deploy_http(['http://203.0.113.1' => 500]);
    watch_round();
    watch_round();
    watch_round();

    $watch = watch_of($second);
    $rollback = Deployment::query()->where('trigger', Trigger::Rollback)->sole();

    expect($watch->status)->toBe(WatchStatus::RolledBack)
        ->and($watch->trigger)->toBe(WatchTrigger::Health)
        ->and($watch->reason)->toContain('failed 3 times in a row')->toContain('→ 500')
        ->and($watch->rollback_deployment_id)->toBe($rollback->id)
        ->and($rollback->target_release_id)->toBe($first->release_id)
        ->and($rollback->auto_rollback_of)->toBe($second->id)
        ->and($second->refresh()->rolled_back)->toBeTrue()
        ->and($second->rolled_back_reason)->toBe($watch->reason)
        ->and(Release::query()->find($second->release_id)->auto_rolled_back_at)->not->toBeNull();

    // The previous release answers again.
    deploy_http([]);
    deploy_run_all($world->agents);

    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id)
        ->and(app(DeploymentBadges::class)->forSites([$world->site->id]))->toBe([$world->site->id => ['Rolled back']]);

    Event::assertDispatched(DeploymentRolledBack::class, fn (DeploymentRolledBack $e) => $e->automatic && $e->deploymentId === $rollback->id
        && $e->toReleaseId === $first->release_id && str_contains((string) $e->reason, 'failed 3 times')
        && str_contains($e->toAlert()->title, 'after its release went live'));
});

it('rolls back when the 5xx rate is above three times the previous release\'s', function (array $baseline, array $now, bool $trips) {
    $counts = FakeAccessLogCounts::install();
    $world = deploy_world();
    watch_settings($world, ['watch_health' => false]);
    $first = watch_deploy($world);
    $counts->byRelease[$first->release_id] = new RequestCounts(...$baseline);
    $second = watch_deploy($world);
    $counts->byRelease[$second->release_id] = new RequestCounts(...$now);

    watch_round();

    $watch = watch_of($second);
    expect($watch->status)->toBe($trips ? WatchStatus::RolledBack : WatchStatus::Watching)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBe($trips);

    if ($trips) {
        expect($watch->trigger)->toBe(WatchTrigger::Errors)->and($watch->reason)->toContain('5xx rate');
    }
})->with([
    'baseline 1% → 5% floor, 6% trips' => [[1000, 10], [100, 6], true],
    'baseline 1% → 5% floor, 5% does not' => [[1000, 10], [100, 5], false],
    'baseline 4% → 12%, 10% does not' => [[1000, 40], [100, 10], false],
    'baseline 4% → 12%, 13% trips' => [[1000, 40], [100, 13], true],
    'no baseline → 5% absolute' => [[0, 0], [100, 6], true],
    'too few requests' => [[1000, 10], [10, 9], false],
    'too few 5xx (6.7% of 60, but 4 errors)' => [[1000, 10], [60, 4], false],
]);

it('records the baseline it compares against', function () {
    $counts = FakeAccessLogCounts::install();
    $world = deploy_world();
    watch_settings($world);
    $first = watch_deploy($world);
    $counts->byRelease[$first->release_id] = new RequestCounts(400, 8);

    expect(watch_of(watch_deploy($world))->baseline)->toBe(['total' => 400, 'errors' => 8, 'rate' => 0.02]);

    // Without access log data (Loki down), the 5% absolute rule applies.
    $counts->unavailable = true;
    expect(watch_of(watch_deploy($world))->baseline)->toBeNull();
});

it('rolls back on an OOM kill or a restart loop of the site, not of another site or organization', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    $other = deploy_world();
    watch_settings($other);
    watch_deploy($other);
    $otherSecond = watch_deploy($other);

    // Another organization's event naming this site id, and another site's event: nothing happens here.
    watch_oom($world, organizationId: $other->organization->id);
    watch_oom($other);

    expect(watch_of($second)->status)->toBe(WatchStatus::Watching)
        ->and(watch_of($otherSecond)->status)->toBe(WatchStatus::RolledBack);

    ServiceRestartLoop::dispatch($world->organization->id, $world->servers[0]->id, 'web-1', 'worker', 'w1', $world->site->id, 'queue worker', 6, 10, '/x', now()->toIso8601String());

    expect(watch_of($second)->status)->toBe(WatchStatus::RolledBack)
        ->and(watch_of($second)->trigger)->toBe(WatchTrigger::Crash)
        ->and(watch_of($second)->reason)->toContain('restarted 6 times in 10 minutes');
});

it('ignores crashes when that trigger is off', function () {
    $world = deploy_world();
    watch_settings($world, ['watch_crashes' => false]);
    watch_deploy($world);
    $second = watch_deploy($world);

    watch_oom($world);

    expect(watch_of($second)->status)->toBe(WatchStatus::Watching);
});

it('rolls back on a new error in Insights only when that trigger is on', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    $issue = fn (string $kind) => IssueOpened::dispatch((string) Str::ulid(), $world->organization->id, $world->site->id, null, $kind, 'RuntimeException: boom', 'App\\Http\\Cart', 'high', '/insights/x');

    $issue('exception');
    expect(watch_of($second)->status)->toBe(WatchStatus::Watching); // off by default

    SiteSettings::query()->whereKey($world->site->id)->update(['watch_issues' => true]);
    $third = watch_deploy($world);
    expect(watch_of($second)->status)->toBe(WatchStatus::Stopped); // superseded by the newer release

    $issue('performance');
    expect(watch_of($third)->status)->toBe(WatchStatus::Watching);

    $issue('exception');
    expect(watch_of($third)->status)->toBe(WatchStatus::RolledBack)
        ->and(watch_of($third)->trigger)->toBe(WatchTrigger::Issue)
        ->and(watch_of($third)->reason)->toBe('New error in Insights: RuntimeException: boom (App\\Http\\Cart).');
});

it('only alerts in alert-only mode, and says the migrations stay', function () {
    Event::fake([ReleaseWatchTriggered::class, DeploymentRolledBack::class]);
    $world = deploy_world();
    watch_settings($world, ['watch_on_trigger' => 'alert_only']);
    watch_deploy($world);
    $second = watch_deploy($world);

    expect(watch_of($second)->migrations)->toBeTrue(); // `artisan migrate` in the deploy script

    watch_oom($world);

    $watch = watch_of($second);
    expect($watch->status)->toBe(WatchStatus::Alerted)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBeFalse()
        ->and($second->refresh()->rolled_back)->toBeFalse()
        ->and($second->rolled_back_reason)->toContain('killed for running out of memory');

    Event::assertDispatched(ReleaseWatchTriggered::class, fn (ReleaseWatchTriggered $e) => $e->heldBack === null && $e->trigger === 'crash'
        && str_contains($e->toAlert()->body, 'alert only') && str_contains($e->toAlert()->body, 'migrations'));
    Event::assertNotDispatched(DeploymentRolledBack::class);
});

it('never rolls back to a release that was itself rolled back automatically', function () {
    Event::fake([ReleaseWatchTriggered::class]);
    $world = deploy_world();
    watch_settings($world);
    $first = watch_deploy($world);
    Release::query()->whereKey($first->release_id)->update(['auto_rolled_back_at' => now()->subDay()]);
    $second = watch_deploy($world);

    watch_oom($world);

    expect(watch_of($second)->status)->toBe(WatchStatus::Alerted)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBeFalse();
    Event::assertDispatched(ReleaseWatchTriggered::class, fn (ReleaseWatchTriggered $e) => $e->heldBack === 'the previous release was itself rolled back automatically.');
});

it('rolls a site back automatically at most once an hour', function () {
    Event::fake([ReleaseWatchTriggered::class]);
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    watch_oom($world);
    deploy_run_all($world->agents);
    expect(watch_of($second)->status)->toBe(WatchStatus::RolledBack);

    $third = watch_deploy($world);
    expect(watch_of($third)->status)->toBe(WatchStatus::Watching);
    watch_oom($world);

    expect(watch_of($third)->status)->toBe(WatchStatus::Alerted)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->count())->toBe(1);
    Event::assertDispatched(ReleaseWatchTriggered::class, fn (ReleaseWatchTriggered $e) => str_contains((string) $e->heldBack, 'already rolled back automatically'));

    // An hour later it may again.
    $this->travel(61)->minutes();
    $fourth = watch_deploy($world);
    watch_oom($world);
    expect(watch_of($fourth)->status)->toBe(WatchStatus::RolledBack);
});

it('never rolls back while another deployment of the site is in progress, and a new deployment ends the window', function () {
    Event::fake([ReleaseWatchTriggered::class]);
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);

    // Queued behind nothing: it starts (building) and ends the window of the live release.
    $building = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    expect($building->status)->toBe(DeploymentStatus::Building)
        ->and(watch_of($second)->status)->toBe(WatchStatus::Stopped);

    // Even a window still open (e.g. the event was lost) holds the rollback back.
    ReleaseWatch::query()->whereKey($second->id)->update(['status' => WatchStatus::Watching]);
    watch_oom($world);

    expect(watch_of($second)->status)->toBe(WatchStatus::Alerted)
        ->and(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBeFalse();
    Event::assertDispatched(ReleaseWatchTriggered::class, fn (ReleaseWatchTriggered $e) => $e->heldBack === 'another deployment of the site is in progress.');
});

it('detects migrations in deploy scripts', function (string $script, bool $migrates) {
    expect(Migrations::inScript($script))->toBe($migrates);
})->with([
    ['$FALAK_PHP artisan migrate --force', true],
    ['php bin/console doctrine:migrations:migrate --no-interaction', true],
    ['bundle exec rails db:migrate', true],
    ['npx prisma migrate deploy', true],
    ['alembic upgrade head', true],
    ["# php artisan migrate\nnpm run build", false],
    ['composer install --no-dev', false],
]);

it('shows the watch settings with the migration warning and saves them (manage permission)', function () {
    $world = deploy_world();

    $this->getJson("/sites/{$world->site->id}/deploy-settings")->assertOk()
        ->assertJsonPath('data.watch.enabled', false)
        ->assertJsonPath('data.watch.minutes', 5)
        ->assertJsonPath('data.watch.health_failures', 3)
        ->assertJsonPath('data.watch.issues', false)
        ->assertJsonPath('data.watch.on_trigger', 'rollback')
        ->assertJsonPath('data.watch.migrations', true)
        ->assertJsonPath('data.watch.production', true);

    $this->putJson("/sites/{$world->site->id}/deploy-settings/watch", ['enabled' => true, 'minutes' => 61])->assertUnprocessable();
    $this->putJson("/sites/{$world->site->id}/deploy-settings/watch", ['on_trigger' => 'explode'])->assertUnprocessable();
    $this->put("/sites/{$world->site->id}/deploy-settings/watch", ['enabled' => true, 'minutes' => 15, 'issues' => true, 'on_trigger' => 'alert_only'])->assertRedirect();

    expect(SiteSettings::query()->find($world->site->id)->watch())->toMatchArray(['enabled' => true, 'minutes' => 15, 'issues' => true, 'on_trigger' => 'alert_only', 'health' => true]);

    [$viewer] = memberOf($world->organization, Role::Viewer);
    $this->actingAs($viewer)->put("/sites/{$world->site->id}/deploy-settings/watch", ['enabled' => false])->assertForbidden();

    [$stranger] = memberOf(role: Role::Owner);
    $this->actingAs($stranger)->getJson("/sites/{$world->site->id}/deploy-settings")->assertNotFound();
});

it('reads and updates the watch through the API, and shows it on sites and deployments', function () {
    $world = deploy_world(actingAs: false);
    $token = app(CreateApiToken::class)($world->user, $world->organization->id, 'cli', ['*'])->plainTextToken;
    $readOnly = app(CreateApiToken::class)($world->user, $world->organization->id, 'ro', ['deployments.view', 'sites.view'])->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->slug}/release-watch")->assertOk()->assertJsonPath('data.enabled', false);
    app('auth')->forgetGuards();
    $this->withToken($readOnly)->putJson("/api/v1/sites/{$world->site->slug}/release-watch", ['enabled' => true])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->putJson("/api/v1/sites/{$world->site->slug}/release-watch", ['enabled' => true, 'minutes' => 3])->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.minutes', 3)->assertJsonPath('data.health', true);

    watch_deploy($world);
    $second = watch_deploy($world);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->slug}")->assertOk()
        ->assertJsonPath('data.release_watch.enabled', true)->assertJsonPath('data.release_watch.minutes', 3);
    $this->withToken($token)->getJson("/api/v1/deployments/{$second->id}")->assertOk()
        ->assertJsonPath('data.watch.status', 'watching')
        ->assertJsonPath('data.watch.on_trigger', 'rollback')
        ->assertJsonPath('data.rolled_back_reason', null);

    watch_oom($world);

    $this->withToken($token)->getJson("/api/v1/deployments/{$second->id}")->assertOk()
        ->assertJsonPath('data.watch.status', 'rolled_back')
        ->assertJsonPath('data.watch.trigger', 'crash')
        ->assertJsonPath('data.rolled_back', true)
        ->assertJsonPath('data.rolled_back_reason', fn (string $reason) => str_contains($reason, 'out of memory'));

    // Another organization's token sees neither.
    $other = deploy_world(actingAs: false);
    $otherToken = app(CreateApiToken::class)($other->user, $other->organization->id, 'cli', ['*'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($otherToken)->getJson("/api/v1/sites/{$world->site->id}/release-watch")->assertNotFound();
    $this->withToken($otherToken)->putJson("/api/v1/sites/{$world->site->id}/release-watch", ['enabled' => false])->assertNotFound();
    $this->withToken($otherToken)->getJson("/api/v1/deployments/{$second->id}")->assertNotFound();
});

it('badges the canvas card while watching', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    watch_deploy($world);

    expect(app(DeploymentBadges::class)->forSites([$world->site->id]))->toBe([$world->site->id => ['Watching']]);
});

it('skips the health trigger when the site\'s health check is off', function () {
    $world = deploy_world();
    watch_settings($world);
    SiteSettings::query()->whereKey($world->site->id)->update(['health_enabled' => false]);
    watch_deploy($world);
    $second = watch_deploy($world);

    // A login wall answering 302 (or anything) never rolls the release back.
    deploy_http(['http://203.0.113.1' => 302]);
    foreach (range(1, 5) as $round) {
        watch_round();
    }

    $watch = watch_of($second);
    expect($watch->status)->toBe(WatchStatus::Watching)
        ->and($watch->triggers['health'])->toBeFalse()
        ->and($watch->health_failures)->toBe(0)
        ->and($watch->checks['health']['unavailable'])->toContain('health check is off');
});

it('counts a health failure once per probe interval, and queues one round per window', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    deploy_http(['http://203.0.113.1' => 500]);

    // Overlapping rounds (a slow one meets the next): the second is skipped.
    $this->travel(30)->seconds();
    app(ReleaseWatcher::class)->evaluate(watch_of($second));
    app(ReleaseWatcher::class)->evaluate(watch_of($second));
    app(ReleaseWatcher::class)->evaluate(watch_of($second));
    expect(watch_of($second)->health_failures)->toBe(1);

    Queue::fake();
    app()->call([new EvaluateReleaseWatches, 'handle']);
    app()->call([new EvaluateReleaseWatches, 'handle']);
    Queue::assertPushed(EvaluateReleaseWatch::class, 1);
});

it('skips a server without an address instead of counting it as a failure', function () {
    $world = deploy_world(servers: 2);
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    $world->servers[1]->forceFill(['ipv4' => null, 'private_ipv4' => null])->save();

    watch_round();
    watch_round();
    watch_round();

    expect(watch_of($second)->status)->toBe(WatchStatus::Watching)
        ->and(watch_of($second)->checks['health']['ok'])->toBeTrue()
        ->and(watch_of($second)->health_failures)->toBe(0);
});

it('ignores crashes from before the release went live', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    $before = now()->subMinutes(2)->toIso8601String();

    ServiceOomKilled::dispatch($world->organization->id, $world->servers[0]->id, 'web-1', 'site', $world->site->id, $world->site->id, 'shop', 1, 512, '/x', $before);
    ServiceRestartLoop::dispatch($world->organization->id, $world->servers[0]->id, 'web-1', 'site', $world->site->id, $world->site->id, 'shop', 6, 10, '/x', $before);

    expect(watch_of($second)->status)->toBe(WatchStatus::Watching);

    $this->travel(1)->minutes();
    ServiceRestartLoop::dispatch($world->organization->id, $world->servers[0]->id, 'web-1', 'site', $world->site->id, $world->site->id, 'shop', 6, 10, '/x', now()->toIso8601String());
    expect(watch_of($second)->status)->toBe(WatchStatus::RolledBack);
});

it('opens no window for a release that is no longer live or with a deployment queued behind it, and never reopens one', function () {
    $world = deploy_world();
    watch_settings($world);
    watch_deploy($world);
    $second = watch_deploy($world);
    $watcher = app(ReleaseWatcher::class);

    watch_oom($world);
    deploy_run_all($world->agents);
    expect(watch_of($second)->status)->toBe(WatchStatus::RolledBack);

    // A re-delivered DeploymentSucceeded: the tripped window stays as it is.
    expect($watcher->start($second->id)->status)->toBe(WatchStatus::RolledBack);

    $this->travel(61)->minutes();
    $third = watch_deploy($world);
    ReleaseWatch::query()->whereKey($third->id)->delete();
    Deployment::query()->forceCreate(['organization_id' => $world->organization->id, 'site_id' => $world->site->id, 'site_slug' => $world->site->slug,
        'number' => 99, 'trigger' => Trigger::Push, 'status' => DeploymentStatus::Queued]);
    expect($watcher->start($third->id))->toBeNull();

    Deployment::query()->where('number', 99)->delete();
    $fourth = watch_deploy($world);
    ReleaseWatch::query()->whereKey($third->id)->delete();
    expect($watcher->start($third->id))->toBeNull() // no longer the live release
        ->and(watch_of($fourth)->status)->toBe(WatchStatus::Watching);
});

it('refuses an automatic rollback when the site moved on: under the trigger lock and when it starts', function () {
    $world = deploy_world();
    watch_settings($world);
    $first = watch_deploy($world);
    $second = watch_deploy($world);
    $site = app(SiteDirectory::class)->find($world->site->id);

    // The guard runs under the site's trigger lock: a reason refuses the rollback.
    expect(fn () => app(TriggerDeployment::class)($site, Trigger::Rollback, releaseId: $first->release_id, rollbackOf: $second->id, guard: fn () => 'the site moved on.'))
        ->toThrow(ValidationException::class, 'the site moved on.');
    expect(Deployment::query()->where('trigger', Trigger::Rollback)->exists())->toBeFalse();

    // A rollback queued for $second that only starts once $third is live is cancelled with the reason.
    $third = watch_deploy($world);
    Release::query()->whereKey($second->release_id)->update(['auto_rolled_back_at' => now()]);
    $second->forceFill(['rolled_back' => true, 'rolled_back_at' => now()])->save();
    $rollback = app(TriggerDeployment::class)($site, Trigger::Rollback, releaseId: $first->release_id, rollbackOf: $second->id);

    expect($rollback->status)->toBe(DeploymentStatus::Cancelled)
        ->and($rollback->error)->toContain('no longer runs the release')
        ->and(Release::current($world->site->id)->id)->toBe($third->release_id)
        ->and($second->refresh()->rolled_back)->toBeFalse()
        ->and(Release::query()->find($second->release_id)->auto_rolled_back_at)->toBeNull();
    $world->agents->assertNothingDispatched('deploy.rollback');
});

it('loads the watches of a deployment list in one query', function () {
    $world = deploy_world();
    watch_settings($world);
    foreach (range(1, 4) as $i) {
        watch_deploy($world);
    }

    DB::enableQueryLog();
    $this->getJson("/sites/{$world->site->id}/deployments")->assertOk()->assertJsonPath('data.history.data.0.watch.status', 'watching');
    $watchQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'deployments_release_watches'));

    expect(count($watchQueries))->toBeLessThanOrEqual(2); // history + queued
});
