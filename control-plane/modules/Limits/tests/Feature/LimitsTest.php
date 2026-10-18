<?php

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Fleet\Events\AgentServiceEventsReported;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Identity\Contracts\Role;
use Falak\Limits\Application\Listeners\RecordServiceEvents;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Limits\Contracts\ServiceHealth;
use Falak\Limits\Domain\Models\ServiceState;
use Falak\Limits\Events\ServiceOomKilled;
use Falak\Limits\Events\ServiceRestartLoop;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Domain\Enums\ApplyStatus;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Domain\Models\Worker;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->agents = FakeAgentGateway::install();
    $this->server = limits_server($this->organization, memoryMb: 2048, cpus: 2);
});

function limits_docker_site(object $test, array $attributes = []): Site
{
    return projects_site($test->organization, 'Api', servers: [$test->server], attributes: [
        'runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3100, ...$attributes,
    ]);
}

// ---- Validation and live updates --------------------------------------------------------------------------------

it('validates site limits against their shape and the servers’ memory and CPUs', function () {
    $site = limits_docker_site($this);

    foreach ([
        [['memory_limit' => 16], 'limits.memory_limit'],
        [['memory_limit' => 4096], 'limits.memory_limit'],
        [['cpus' => 3], 'limits.cpus'],
        [['memory_limit' => 256, 'memory_reservation' => 512], 'limits.memory_reservation'],
        [['restart_policy' => 'always', 'max_restarts' => 3], 'limits.max_restarts'],
        [['restart_policy' => 'sometimes'], 'limits.restart_policy'],
        [['oom' => 'never'], 'limits.oom'],
        [['log_max_files' => 3], 'limits.log_max_size'],
    ] as [$limits, $error]) {
        $this->putJson("/sites/{$site->id}/limits", ['limits' => $limits])->assertUnprocessable()->assertJsonValidationErrors($error);
    }

    expect($site->refresh()->limits)->toBeNull();
    $this->agents->assertNothingDispatched('docker.update');
});

it('applies a Docker site’s live limits with docker.update and the rest on the next deploy', function () {
    $site = limits_docker_site($this);

    $this->putJson("/sites/{$site->id}/limits", ['limits' => ['memory_limit' => 512, 'cpus' => 1, 'restart_policy' => 'on-failure', 'max_restarts' => 5]])
        ->assertOk()->assertJsonPath('data.applied', 'live')->assertJsonPath('data.limits.memory_limit', 512);

    $update = $this->agents->last('docker.update', $this->server->id);
    expect(limits_schema_errors($update))->toBe([])
        ->and($update['payload'])->toEqual(['site' => $site->slug, 'memory_bytes' => 512 * 1024 ** 2, 'cpus' => 1, 'restart_policy' => 'on-failure', 'max_restarts' => 5])
        ->and($site->refresh()->limits)->toBe(['memory_limit' => 512, 'cpus' => 1, 'restart_policy' => 'on-failure', 'max_restarts' => 5]);

    // Log caps need a new container; a removed memory limit can't be lifted in place.
    $count = count($this->agents->dispatched('docker.update'));
    $this->putJson("/sites/{$site->id}/limits", ['limits' => ['memory_limit' => 512, 'cpus' => 1, 'restart_policy' => 'on-failure', 'max_restarts' => 5, 'log_max_size' => 10]])
        ->assertOk()->assertJsonPath('data.applied', 'redeploy');
    $this->putJson("/sites/{$site->id}/limits", ['limits' => ['cpus' => 1]])->assertOk()->assertJsonPath('data.applied', 'redeploy');
    // Unchanged: nothing to do.
    $this->putJson("/sites/{$site->id}/limits", ['limits' => ['cpus' => 1]])->assertOk()->assertJsonPath('data.applied', 'none');

    expect($this->agents->dispatched('docker.update'))->toHaveCount($count);
});

it('refuses limits on runtimes without a process of their own, and compose limits outside the project', function () {
    $static = projects_site($this->organization, 'Docs', servers: [$this->server], attributes: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    $franken = projects_site($this->organization, 'Shop', servers: [$this->server]);

    $this->putJson("/sites/{$static->id}/limits", ['limits' => ['memory_limit' => 128]])->assertUnprocessable()->assertJsonValidationErrors('limits');
    $this->putJson("/sites/{$franken->id}/limits", ['limits' => ['memory_limit' => 128]])->assertUnprocessable()->assertJsonValidationErrors('limits');
    // FrankenPHP sites keep restart / log / OOM preferences (Octane, workers).
    $this->putJson("/sites/{$franken->id}/limits", ['limits' => ['oom' => 'protect']])->assertOk();
    $this->putJson("/sites/{$franken->id}/compose/services/web/limits", ['limits' => ['memory_limit' => 128]])->assertUnprocessable()->assertJsonValidationErrors('service');
});

it('moves a limited PHP-FPM site into its own master in the site’s slice, and puts its programs there too', function () {
    $site = projects_site($this->organization, 'Blog', servers: [$this->server], attributes: ['runtime' => 'php-fpm', 'isolated' => true, 'laravel' => ['horizon' => true]]);
    processes_deploy($site, [$this->server]);
    $worker = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'queue' => 'default', 'limits' => ['memory_limit' => 256, 'pids_limit' => 64]]);
    Daemon::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Reverb', 'command' => 'php artisan reverb:start', 'instances' => 1, 'restart' => 'always', 'stop_signal' => 'TERM', 'stop_timeout' => 10]);

    $this->putJson("/sites/{$site->id}/limits", ['limits' => ['memory_limit' => 1024, 'cpus' => 1.5, 'oom' => 'protect']])->assertOk()->assertJsonPath('data.applied', 'live');

    $pool = $this->agents->last('runtime.fpm.pool', $this->server->id);
    expect(limits_schema_errors($pool))->toBe([])
        ->and($pool['payload']['slice'])->toBe('site_'.str_replace('-', '_', $site->slug))
        ->and($pool['payload']['oom_score_adj'])->toBe(-500);

    app(ServerConverger::class)->converge($this->server->id);
    $proc = $this->agents->last('proc.apply', $this->server->id);
    $programs = collect($proc['payload']['programs'])->keyBy('name');
    $slices = collect($proc['payload']['slices'])->keyBy('name');
    $siteSlice = 'site_'.str_replace('-', '_', $site->slug);
    $workerSlice = 'worker_'.$worker->id;

    expect(limits_schema_errors($proc))->toBe([])
        ->and($slices->keys()->sort()->values()->all())->toBe([$siteSlice, $workerSlice])
        ->and($slices[$siteSlice])->toMatchArray(['memory_max_bytes' => 1024 ** 3, 'cpu_quota_percent' => 150])
        ->and($slices[$workerSlice])->toMatchArray(['memory_max_bytes' => 256 * 1024 ** 2, 'tasks_max' => 64])
        ->and($programs->firstWhere('slice', $workerSlice))->not->toBeNull()
        // Horizon shares the site's slice; the daemon without limits runs in none.
        ->and($programs->first(fn ($p) => str_ends_with($p['name'], 'horizon'))['slice'])->toBe($siteSlice)
        ->and($programs->first(fn ($p) => str_ends_with($p['name'], 'horizon'))['oom_score_adj'])->toBe(-500)
        ->and($programs->first(fn ($p) => str_contains($p['name'], 'daemon'))['slice'] ?? null)->toBeNull();

    // Limits off again: back into the shared master.
    $this->putJson("/sites/{$site->id}/limits", ['limits' => null])->assertOk();
    expect($this->agents->last('runtime.fpm.pool', $this->server->id)['payload'])->not->toHaveKey('slice');
});

it('validates worker and daemon limits against the servers', function () {
    $site = projects_site($this->organization, 'Blog', servers: [$this->server], attributes: ['runtime' => 'php-fpm']);

    $this->post("/sites/{$site->id}/queues", ['processes' => 1, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128, 'limits' => ['memory_limit' => 8192]])
        ->assertSessionHasErrors('limits.memory_limit');
    $this->post("/sites/{$site->id}/queues", ['processes' => 2, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128, 'limits' => ['memory_limit' => 512, 'cpus' => 0.5]])
        ->assertSessionHasNoErrors();
    $this->post("/sites/{$site->id}/daemons", ['name' => 'Consumer', 'command' => 'bin/consume', 'instances' => 1, 'restart' => 'always', 'stop_signal' => 'TERM', 'stop_timeout' => 30, 'limits' => ['cpus' => 4]])
        ->assertSessionHasErrors('limits.cpus');

    expect(Worker::query()->where('site_id', $site->id)->sole()->limits)->toBe(['memory_limit' => 512, 'cpus' => 0.5]);
});

// ---- Defaults per environment -------------------------------------------------------------------------------------

it('writes the environment’s defaults on services created outside production, never on existing ones', function () {
    config(['limits.defaults.non_production' => ['memory_limit' => 384, 'cpus' => 0.5, 'pids_limit' => 256]]);
    $staging = projects_environment($this->organization);
    $docker = ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null];
    $prod = projects_site($this->organization, 'Live', environment: projects_default_env($this->organization), servers: [$this->server], attributes: [...$docker, 'app_port' => 3200]);
    $stage = projects_site($this->organization, 'Stage', environment: $staging, servers: [$this->server], attributes: [...$docker, 'app_port' => 3300]);
    $own = projects_site($this->organization, 'Own', environment: $staging, servers: [$this->server], attributes: [...$docker, 'app_port' => 3400, 'limits' => ['cpus' => 1]]);
    $stack = projects_site($this->organization, 'Stack', environment: $staging, servers: [$this->server], attributes: ['runtime' => 'compose', 'framework' => 'docker', 'php_version' => null]);
    // A site from before this release placed now (a backfill): untouched.
    $old = projects_site($this->organization, 'Old', servers: [$this->server], attributes: [...$docker, 'app_port' => 3500, 'created_at' => now()->subDay()]);
    app(LinkService::class)($staging, ServiceKind::Site, $old->id, 'Old');

    expect($stage->refresh()->limits)->toBe(['memory_limit' => 384, 'cpus' => 0.5, 'pids_limit' => 256])
        ->and($own->refresh()->limits)->toBe(['cpus' => 1])
        ->and($prod->refresh()->limits)->toBeNull()
        ->and($old->refresh()->limits)->toBeNull()
        ->and($stack->refresh()->compose_limits)->toBe(['*' => ['memory_limit' => 384, 'cpus' => 0.5, 'pids_limit' => 256]])
        ->and($stack->toData()->composeServiceLimits('db')->memoryLimit)->toBe(384);

    // What is stored is what runs: no merge at runtime.
    $limits = $this->getJson("/sites/{$own->id}/limits")->assertOk()->json('data');
    expect($limits['effective'])->toBe(['cpus' => 1])
        ->and($limits['defaults'])->toBe(['memory_limit' => 384, 'cpus' => 0.5, 'pids_limit' => 256]);
    $items = collect($this->getJson("/servers/{$this->server->id}/capacity")->json('data.items'))->keyBy('id');
    expect($items[$stage->id])->toMatchArray(['memory_limit_mb' => 384])
        ->and($items[$own->id])->toMatchArray(['memory_limit_mb' => null, 'cpus' => 1])
        ->and($items[$old->id])->toMatchArray(['memory_limit_mb' => null]);

    // A new worker starts with the defaults under its own values, validated together: a reservation above the
    // default limit is refused here instead of breaking the server's proc.apply.
    $blog = projects_site($this->organization, 'Blog', environment: $staging, servers: [$this->server], attributes: ['runtime' => 'php-fpm']);
    expect($this->agents->last('runtime.fpm.pool', $this->server->id)['payload']['slice'])->toBe('site_'.str_replace('-', '_', $blog->slug));
    $this->post("/sites/{$blog->id}/queues", ['processes' => 1, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128, 'limits' => ['memory_reservation' => 1024]])
        ->assertSessionHasErrors('limits.memory_reservation');
    $this->post("/sites/{$blog->id}/queues", ['processes' => 1, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128, 'limits' => ['cpus' => 0.25]])
        ->assertSessionHasNoErrors();
    expect(Worker::query()->where('site_id', $blog->id)->sole()->limits)->toBe(['memory_limit' => 384, 'cpus' => 0.25, 'pids_limit' => 256]);
});

it('never sends a reservation above the limit, whatever is stored', function () {
    $limits = ResourceLimits::fromArray(['memory_limit' => 256, 'memory_reservation' => 1024]);

    expect($limits->docker()['memory_reservation_bytes'])->toBe(256 * 1024 ** 2)
        ->and($limits->slice('site_x')['memory_low_bytes'])->toBe(256 * 1024 ** 2)
        ->and($limits->composeService()['mem_reservation'])->toBe('256M');
});

it('applies a compose service’s limits on the next deploy, never only live', function () {
    $stack = projects_site($this->organization, 'Stack', servers: [$this->server], attributes: ['runtime' => 'compose', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'inline']);
    ComposeVersion::query()->create(['site_id' => $stack->id, 'version' => 1, 'content' => "services:\n  db:\n    image: postgres:17\n", 'created_at' => now()]);

    $this->putJson("/sites/{$stack->id}/compose/services/db/limits", ['limits' => ['memory_limit' => 512]])->assertOk()->assertJsonPath('data.applied', 'redeploy');
    $this->agents->assertNothingDispatched('docker.update');
    expect($stack->refresh()->compose_limits)->toBe(['db' => ['memory_limit' => 512]]);
});

// ---- Capacity ---------------------------------------------------------------------------------------------------

it('sums every service’s limits on a server against its memory and cores, database instances and functions included', function () {
    $api = limits_docker_site($this, ['limits' => ['memory_limit' => 512, 'memory_reservation' => 256, 'cpus' => 1]]);
    $blog = projects_site($this->organization, 'Blog', servers: [$this->server], attributes: ['runtime' => 'php-fpm', 'limits' => ['memory_limit' => 256]]);
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'queue' => 'default', 'limits' => ['memory_limit' => 128, 'cpus' => 0.5]]);
    // Runs on another server only: not counted here.
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'queue' => 'other', 'limits' => ['memory_limit' => 999], 'server_ids' => ['01hzyother0000000000000001']]);
    $instance = databases_instance($this->organization, 'postgresql', $this->server, ['memory_bytes' => 1024 * 1024 ** 2, 'cpus' => 1.5]);
    databases_active_db($instance, 'app');
    databases_active_db($instance, 'analytics');
    $fn = projects_site($this->organization, 'Hook', servers: [$this->server], attributes: ['runtime' => 'function', 'framework' => 'node', 'php_version' => null]);
    CloudFunction::query()->create(['organization_id' => $this->organization->id, 'site_id' => $fn->id, 'runtime' => 'node', 'entrypoint' => 'index.js', 'min_instances' => 0, 'max_instances' => 2,
        'concurrency' => 10, 'idle_timeout_s' => 60, 'memory_mb' => 128, 'cpus' => 0.25, 'request_timeout_s' => 30]);

    $capacity = $this->getJson("/servers/{$this->server->id}/capacity")->assertOk()->json('data');
    $items = collect($capacity['items'])->keyBy('id');

    expect($capacity['server'])->toMatchArray(['memory_mb' => 2048, 'cpus' => 2])
        ->and($items)->toHaveCount(5)
        ->and($items[$instance->id])->toMatchArray(['kind' => 'database', 'memory_limit_mb' => 1024, 'memory_reservation_mb' => 1024, 'cpus' => 1.5])
        ->and($items[$fn->id])->toMatchArray(['kind' => 'function', 'memory_limit_mb' => 256, 'cpus' => 0.5])
        ->and($capacity['totals'])->toBe(['memory_limit_mb' => 512 + 256 + 128 + 1024 + 256, 'memory_reservation_mb' => 256 + 1024, 'cpus' => 3.5])
        ->and($capacity['overcommitted'])->toBe(['memory' => true, 'reservations' => false, 'cpus' => true])
        ->and($capacity['warnings'])->toHaveCount(2)
        ->and($capacity['unlimited'])->toBe(['memory' => 0, 'cpus' => 1]);

    // The API answers the same.
    $this->getJson("/api/v1/servers/{$this->server->id}/capacity")->assertOk()->assertJsonPath('data.totals.cpus', 3.5);
});

it('subtracts the control plane\'s reserve from the memory of its own machine', function () {
    config(['servers.control_plane_reserve_mb' => 1536]);
    $this->server->forceFill(['control_plane_host' => true])->save();
    limits_docker_site($this, ['limits' => ['memory_limit' => 768]]);

    $capacity = $this->getJson("/servers/{$this->server->id}/capacity")->assertOk()->json('data');

    expect($capacity['server'])->toMatchArray(['memory_mb' => 512, 'total_memory_mb' => 2048, 'reserved_memory_mb' => 1536])
        ->and($capacity['overcommitted']['memory'])->toBeTrue();
});

it('keeps capacity views and limits to their organization', function () {
    [$other, $otherOrg] = memberOf();
    $theirs = limits_server($otherOrg);
    $theirSite = projects_site($otherOrg, 'Theirs', servers: [$theirs], attributes: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3400]);

    $this->getJson("/servers/{$theirs->id}/capacity")->assertNotFound();
    $this->getJson("/sites/{$theirSite->id}/limits")->assertNotFound();
    $this->putJson("/sites/{$theirSite->id}/limits", ['limits' => ['cpus' => 1]])->assertNotFound();

    // A viewer sees, but can't change.
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $mine = limits_docker_site($this);
    $this->actingAs($viewer)->getJson("/servers/{$this->server->id}/capacity")->assertOk();
    $this->actingAs($viewer)->putJson("/sites/{$mine->id}/limits", ['limits' => ['cpus' => 1]])->assertStatus(403);
});

// ---- OOM kills and restart loops ----------------------------------------------------------------------------------

function limits_report(object $test, array $events, ?string $organizationId = null, ?string $serverId = null): void
{
    AgentServiceEventsReported::dispatch('agent', $organizationId ?? $test->organization->id, $serverId ?? $test->server->id, array_map(fn (array $e) => [
        'site' => null, 'project' => null, 'service' => null, 'instance' => null, 'count' => 1, 'at' => now()->toIso8601String(), ...$e,
    ], $events));
}

it('raises ServiceOomKilled for containers, slices and programs, alertable and shown as card badges', function () {
    Event::fake([ServiceOomKilled::class, ServiceRestartLoop::class]);
    $api = limits_docker_site($this, ['limits' => ['memory_limit' => 512]]);
    $blog = projects_site($this->organization, 'Blog', servers: [$this->server], attributes: ['runtime' => 'php-fpm']);
    $worker = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'queue' => 'default', 'limits' => ['memory_limit' => 128]]);
    $instance = databases_instance($this->organization, 'postgresql', $this->server);
    databases_active_db($instance);

    limits_report($this, [
        ['kind' => 'oom_kill', 'source' => 'container', 'name' => "falak-{$api->slug}-blue", 'site' => $api->slug, 'count' => 2],
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => "worker_{$worker->id}"],
        ['kind' => 'oom_kill', 'source' => 'container', 'name' => "falak-db-{$instance->id}", 'instance' => $instance->id],
        // Unknown services are ignored.
        ['kind' => 'oom_kill', 'source' => 'container', 'name' => 'random', 'site' => 'nope'],
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => 'worker_01hzyunknown00000000000001'],
    ]);

    Event::assertDispatchedTimes(ServiceOomKilled::class, 3);
    Event::assertDispatched(ServiceOomKilled::class, fn (ServiceOomKilled $e) => $e->serviceKind === 'site' && $e->serviceId === $api->id && $e->kills === 2 && $e->memoryLimitMb === 512);
    Event::assertDispatched(ServiceOomKilled::class, fn (ServiceOomKilled $e) => $e->serviceKind === 'worker' && $e->serviceId === $worker->id && $e->siteId === $blog->id && $e->memoryLimitMb === 128
        && $e->url === "/sites/{$blog->id}/queues");
    Event::assertDispatched(ServiceOomKilled::class, fn (ServiceOomKilled $e) => $e->serviceKind === 'database' && $e->serviceId === $instance->id);

    $alert = (new ServiceOomKilled($this->organization->id, $this->server->id, 'app-1', 'site', $api->id, $api->id, 'Api', 1, 512, "/sites/{$api->id}", now()->toIso8601String()))->toAlert();
    expect($alert->type)->toBe(ServiceOomKilled::ALERT_TYPE)
        ->and($alert->title)->toContain('Api')
        ->and(new ServiceOomKilled($this->organization->id, $this->server->id, 'x', 'site', 'x', null, 'x', 1, null, '/', now()->toIso8601String()))->toBeInstanceOf(Alertable::class)
        ->and(collect(app(AlertTypes::class)->all())->pluck('type')->all())->toContain(ServiceOomKilled::ALERT_TYPE, ServiceRestartLoop::ALERT_TYPE);

    $badges = app(ServiceHealth::class)->badgesForSites([$api->id, $blog->id]);
    expect($badges)->toBe([$api->id => ['OOM killed'], $blog->id => ['OOM killed']])
        ->and(app(ServiceHealth::class)->badgesForInstances([$instance->id]))->toBe([$instance->id => ['OOM killed']]);
});

it('raises ServiceRestartLoop once per window when a service restarts too often', function () {
    Event::fake([ServiceRestartLoop::class]);
    config(['limits.restart_loop' => ['restarts' => 5, 'window_minutes' => 60]]);
    $site = projects_site($this->organization, 'Stack', servers: [$this->server], attributes: ['runtime' => 'compose', 'framework' => 'docker', 'php_version' => null]);

    // A compose service is resolved by its project (the site's slug) and service.
    $restart = fn (int $count) => limits_report($this, [['kind' => 'restart', 'source' => 'container', 'name' => "{$site->slug}-db-1", 'project' => $site->slug, 'service' => 'db', 'count' => $count]]);
    $restart(3);
    Event::assertNotDispatched(ServiceRestartLoop::class);
    $restart(2);
    Event::assertDispatchedTimes(ServiceRestartLoop::class, 1);
    Event::assertDispatched(ServiceRestartLoop::class, fn (ServiceRestartLoop $e) => $e->serviceKind === 'compose_service' && $e->serviceId === "{$site->id}:db" && $e->restarts === 5);
    $restart(4);
    Event::assertDispatchedTimes(ServiceRestartLoop::class, 1);

    expect(app(ServiceHealth::class)->badgesForSites([$site->id]))->toBe([$site->id => ['Restarting']]);

    // A new window raises it again.
    $this->travel(2)->hours();
    $restart(6);
    Event::assertDispatchedTimes(ServiceRestartLoop::class, 2);
});

it('ignores service events naming another organization’s services', function () {
    Event::fake([ServiceOomKilled::class]);
    [, $otherOrg] = memberOf();
    $theirs = limits_server($otherOrg);
    $theirSite = projects_site($otherOrg, 'Theirs', servers: [$theirs], attributes: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3500]);

    // Our agent naming their slug (their site is not on our server), and their server reported under our organization.
    limits_report($this, [['kind' => 'oom_kill', 'source' => 'container', 'name' => 'x', 'site' => $theirSite->slug]]);
    limits_report($this, [['kind' => 'oom_kill', 'source' => 'container', 'name' => 'x', 'site' => $theirSite->slug]], serverId: $theirs->id);

    Event::assertNotDispatched(ServiceOomKilled::class);
    expect(app(ServiceHealth::class)->badgesForSites([$theirSite->id]))->toBe([]);
});

it('counts compose services and FrankenPHP sites in the capacity view as their runtime allows', function () {
    $franken = projects_site($this->organization, 'Shop', servers: [$this->server]);
    $stack = projects_site($this->organization, 'Stack', servers: [$this->server], attributes: ['runtime' => SiteRuntime::Compose->value, 'framework' => 'docker', 'php_version' => null]);

    $ids = collect($this->getJson("/servers/{$this->server->id}/capacity")->json('data.items'))->pluck('id')->all();
    expect($ids)->not->toContain($franken->id)->not->toContain($stack->id);
});

it('trusts neither the agent’s clock, its counts, nor slices of workers that don’t run on it', function () {
    Event::fake([ServiceOomKilled::class]);
    $other = limits_server($this->organization);
    $blog = projects_site($this->organization, 'Blog', servers: [$this->server, $other], attributes: ['runtime' => 'php-fpm']);
    $elsewhere = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'queue' => 'default', 'server_ids' => [$other->id]]);
    $here = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'queue' => 'high']);

    limits_report($this, [
        // Runs on the other server only.
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => "worker_{$elsewhere->id}"],
        // From the future, and from yesterday's queue.
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => "worker_{$here->id}", 'at' => now()->addHour()->toIso8601String()],
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => "worker_{$here->id}", 'at' => now()->subDays(2)->toIso8601String()],
        // A count gone wild.
        ['kind' => 'oom_kill', 'source' => 'slice', 'name' => "worker_{$here->id}", 'count' => 10 ** 9, 'at' => now()->subMinute()->toIso8601String()],
    ]);

    Event::assertDispatchedTimes(ServiceOomKilled::class, 1);
    Event::assertDispatched(ServiceOomKilled::class, fn (ServiceOomKilled $e) => $e->serviceId === $here->id && $e->kills === RecordServiceEvents::MAX_COUNT
        && $e->at === now()->toIso8601String());
    expect(ServiceState::query()->count())->toBe(1);
});

it('records the slices an agent skipped as the apply’s error, the rest applied', function () {
    $blog = projects_site($this->organization, 'Blog', servers: [$this->server], attributes: ['runtime' => 'php-fpm', 'limits' => ['memory_limit' => 256]]);
    processes_deploy($blog, [$this->server]);
    app(ServerConverger::class)->converge($this->server->id);
    $apply = $this->agents->last('proc.apply', $this->server->id);

    $this->agents->succeed($apply['handle'], ['changed' => true, 'started' => [], 'stopped' => [], 'restarted' => [], 'unchanged' => [], 'slice_errors' => ['site_blog' => 'set-property failed']]);

    $state = ServerState::query()->find($this->server->id);
    expect($state->proc_status)->toBe(ApplyStatus::Applied)
        ->and($state->proc_error)->toBe('Limits not applied: site_blog: set-property failed');
});
