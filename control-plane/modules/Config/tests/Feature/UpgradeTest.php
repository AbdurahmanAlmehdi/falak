<?php

use Falak\Config\Application\Exporter;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

/*
| Turning config as code on and off for a project that already runs: the export of today's state applied by a push
| changes nothing, disabling hands everything back to the dashboard and push to deploy, re-enabling follows the file
| again, and stored preview settings survive the round trip.
*/

/** A project made in the dashboard: a deployed site with a worker, a schedule and a domain; the source not enabled yet. */
function upgrade_world(): ConfigWorld
{
    $world = config_world();
    $world->source->forceFill(['enabled' => false])->save();
    $site = $world->deploy->site;
    $org = $world->deploy->organization->id;

    Worker::query()->create(['organization_id' => $org, 'site_id' => $site->id, 'queue' => 'default', 'processes' => 2]);
    Schedule::query()->create(['organization_id' => $org, 'site_id' => $site->id, 'name' => 'report', 'command' => 'php artisan report', 'expression' => '0 3 * * *']);
    Domain::query()->create(['organization_id' => $org, 'site_id' => $site->id, 'name' => 'shop.example.com', 'is_primary' => true,
        'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    return $world;
}

function upgrade_toggle(ConfigWorld $world, bool $enabled): void
{
    test()->putJson("/projects/{$world->environment->project_id}/settings/config", [
        'connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'path' => 'falak.yaml', 'enabled' => $enabled,
    ])->assertOk();
}

function upgrade_export(ConfigWorld $world): string
{
    $exporter = app(Exporter::class);

    return $exporter->render($exporter->document(app(ProjectDirectory::class)->environment($world->environment->id), $world->source->refresh()), 'yaml');
}

/** @return array<string, int> what the dashboard made, by kind */
function upgrade_inventory(): array
{
    return ['sites' => Site::query()->count(), 'workers' => Worker::query()->count(), 'schedules' => Schedule::query()->count(), 'domains' => Domain::query()->count()];
}

it('enabling the source and pushing the export applies an empty plan: no extra deploy, nothing deleted', function () {
    $world = upgrade_world();
    $before = upgrade_inventory();

    upgrade_toggle($world, true);
    $file = upgrade_export($world);
    expect($file)->toContain('branch: main')   // a push to main applies it
        ->toContain('worker-1')->toContain('report')->toContain('shop.example.com');

    $world->file($file);
    $world->push(config_sha('b'), paths: ['falak.yaml']);
    $world->settle();
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applied, (string) $run->error)
        ->and(collect($run->plan['changes'])->where('op', '!=', 'noop')->all())->toBe([])
        ->and(Deployment::query()->where('commit', config_sha('b'))->count())->toBe(1)   // the push's own, never twice
        ->and(upgrade_inventory())->toBe($before)
        ->and(ConfigDetached::query()->count())->toBe(0)
        ->and(Worker::query()->sole()->name)->toBe('worker-1');                          // adopted under its export key
});

it('disabling keeps everything, unlocks every field and gives the site back to push to deploy; re-enabling follows the file again', function () {
    $world = upgrade_world();
    $site = $world->deploy->site;
    upgrade_toggle($world, true);
    $world->file(upgrade_export($world));
    $world->push(config_sha('b'), paths: ['falak.yaml']);
    $world->settle();
    $before = upgrade_inventory();
    $worker = Worker::query()->sole();

    // Managed while enabled: the dashboard refuses a change.
    $this->putJson("/sites/{$site->id}/queues/{$worker->id}", ['queue' => 'default', 'processes' => 5, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128])
        ->assertStatus(422);

    upgrade_toggle($world, false);

    $this->put("/sites/{$site->id}/queues/{$worker->id}", ['queue' => 'default', 'processes' => 5, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 128])
        ->assertSessionHasNoErrors();
    $world->push(config_sha('c'), paths: ['app/Http/Kernel.php']);
    $world->settle();

    expect(upgrade_inventory())->toBe($before)
        ->and($worker->refresh()->processes)->toBe(5)
        ->and(ConfigRun::query()->where('commit', config_sha('c'))->exists())->toBeFalse()
        ->and(Deployment::query()->where('commit', config_sha('c'))->sole()->trigger->value)->toBe('push');

    // Re-enabled: the next push plans the file against today's state; the file wins for what it sets.
    upgrade_toggle($world, true);
    $world->push(config_sha('d'), paths: ['app/Http/Kernel.php']);
    $world->settle();
    $run = ConfigRun::query()->where('commit', config_sha('d'))->sole();

    expect($run->status)->toBe(RunStatus::Applied, (string) $run->error)
        ->and(collect($run->plan['changes'])->where('op', '!=', 'noop')->pluck('path')->all())->toBe(['/units/web/workers/worker-1/processes'])
        ->and($worker->refresh()->processes)->toBe(2)
        ->and(Deployment::query()->where('commit', config_sha('d'))->count())->toBe(1)
        ->and(upgrade_inventory())->toBe($before);
});

it('keeps a project\'s preview settings through export and apply, and leaves them alone for a file without previews', function () {
    $world = upgrade_world();
    $settings = PreviewSettings::query()->forceCreate(['organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id,
        'enabled' => true, 'base_environment_id' => $world->environment->id, 'max_concurrent' => 3, 'idle_ttl_hours' => 48, 'access' => 'public',
        'domain_pattern' => 'pr-{number}-{service}', 'services' => ['web' => 'include']]);
    $stored = fn () => PreviewSettings::query()->sole()->only(['enabled', 'base_environment_id', 'max_concurrent', 'idle_ttl_hours', 'access', 'domain_pattern', 'services']);
    $before = $stored();

    upgrade_toggle($world, true);
    $file = upgrade_export($world);
    expect($file)->toContain('previews:')->toContain('max_concurrent: 3')->toContain('idle_ttl: 2d');

    $world->file($file);
    $world->push(config_sha('b'), paths: ['falak.yaml']);
    $world->settle();
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applied, (string) $run->error)
        ->and(collect($run->plan['changes'])->where('op', '!=', 'noop')->all())->toBe([])
        ->and($stored())->toBe($before);

    // A file without `previews` neither changes nor turns off what the dashboard set.
    $world->file(preg_replace('/^previews:.*?(?=^\S|\z)/ms', '', $file));
    $world->push(config_sha('c'), paths: ['falak.yaml']);
    $world->settle();

    expect(ConfigRun::query()->where('commit', config_sha('c'))->sole()->status)->toBe(RunStatus::Applied)
        ->and($stored())->toBe($before)
        ->and($settings->refresh()->enabled)->toBeTrue();
});
