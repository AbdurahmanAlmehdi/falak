<?php

use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Contracts\ConfigSources;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Http\Presenters;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Listeners\DeployOnPush;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Events\PushReceived;

require_once __DIR__.'/../Support/helpers.php';

function config_e2e_file(string $feature = 'on', string $health = '/healthz', int $processes = 2): string
{
    return <<<YAML
        version: 1
        units:
          web:
            type: app
            runtime: frankenphp
            php: "8.4"
            env:
              FEATURE: "{$feature}"
            deploy:
              health: { path: {$health} }
            workers:
              queue: { command: php artisan queue:work, processes: {$processes} }
        environments:
          production: { branch: main }
        YAML;
}

function config_env(string $siteId): array
{
    return EnvironmentVersion::query()->where('site_id', $siteId)->orderByDesc('version')->first()->variables;
}

it('applies a push: phase A before the build, a deployment, phase B and the config on the release when it goes live', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file());

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applying)
        ->and($run->trigger)->toBe(RunTrigger::Push)
        ->and($run->plan['deploy'])->toBe(['web'])
        ->and(SiteSettings::query()->find($site->id)?->health_path)->toBe('/healthz')   // phase A
        ->and(config_env($site->id)['FEATURE'])->toBe('on')
        ->and(Worker::query()->where('site_id', $site->id)->exists())->toBeFalse()      // phase B waits for the release
        // The first push after enabling: push to deploy queued the site before phase A; Config replaced it.
        ->and(Deployment::query()->where('site_id', $site->id)->where('status', '!=', 'cancelled')->sole()->commit)->toBe(config_sha('b'));

    $world->settle();
    $run->refresh();

    expect($run->status)->toBe(RunStatus::Applied)
        ->and($run->deploy[0])->toMatchArray(['unit' => 'web', 'status' => 'released'])
        ->and(Worker::query()->where('site_id', $site->id)->get(['name', 'processes'])->map(fn ($w) => [$w->name, $w->processes])->all())->toBe([['queue', 2]])
        ->and(Release::current($site->id)?->config)->toMatchArray(['env' => ['FEATURE' => 'on'], 'workers' => ['queue' => ['command' => 'php artisan queue:work', 'processes' => 2]]])
        ->and(ConfigOwnership::query()->where('resource_type', 'site')->where('resource_id', $site->id)->pluck('path')->all())->toContain('/env/FEATURE', '/deploy/health/path')
        ->and(ConfigOwnership::query()->where('resource_type', 'worker')->pluck('path')->all())->toBe(['/workers/queue']);
});

it('deploys only the units whose files or config changed', function () {
    $world = config_world();
    $docs = projects_site($world->deploy->organization, 'Docs', ['APP_ENV' => 'production'], $world->environment, $world->deploy->servers, [
        'source_connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'branch' => 'main', 'push_to_deploy' => true,
    ]);
    Service::query()->where('ref_id', $docs->id)->update(['name' => 'docs']);
    $file = "version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\", root: apps/web }\n  docs: { type: app, runtime: frankenphp, php: \"8.4\", root: apps/docs }\nenvironments:\n  production: { branch: main }\n";
    $world->file($file);

    $world->push(config_sha('b'));  // the first run manages both units: both deploy
    $world->settle();
    expect(Deployment::query()->where('status', '!=', 'cancelled')->count())->toBe(2);

    $world->push(config_sha('c'), paths: ['apps/docs/index.md', 'README.md']);
    $world->settle();
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($run->status)->toBe(RunStatus::Applied)
        ->and($run->plan['deploy'])->toBe(['docs'])
        ->and(Deployment::query()->where('commit', config_sha('c'))->pluck('site_id')->all())->toBe([$docs->id]);

    $world->push(config_sha('d'), paths: ['README.md']);
    expect(Deployment::query()->where('commit', config_sha('d'))->count())->toBe(0)
        ->and(ConfigRun::query()->where('commit', config_sha('d'))->sole()->status)->toBe(RunStatus::Applied);
});

it('a risky plan waits for an approval: nothing is applied or deployed until then', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file("version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    env: { FEATURE: \"on\" }\n    deploy: { strategy: in-place }\nenvironments:\n  production: { branch: main }\n");

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and($run->approval_expires_at?->isFuture())->toBeTrue()
        ->and(collect($run->plan['changes'])->firstWhere('path', '/units/web/deploy/strategy'))->toMatchArray(['risky' => true, 'rule' => 'production.strategy'])
        ->and(SiteSettings::query()->find($site->id)?->strategy)->toBeNull()
        ->and(config_env($site->id))->not->toHaveKey('FEATURE')
        ->and($run->deploy)->toBeNull()
        // The file was never applied here yet: push to deploy still deployed the commit, the old way.
        ->and(Deployment::query()->sole()->trigger->value)->toBe('push');
});

it('a rollback restores the config saved on the release it returns to', function () {
    $world = config_world();
    $site = $world->deploy->site;

    $world->file(config_e2e_file('one', '/one', 1));
    $world->push(config_sha('b'));
    $world->settle();
    $first = Release::current($site->id);

    $world->file(config_e2e_file('two', '/two', 3));
    $world->push(config_sha('c'));
    $world->settle();

    expect(config_env($site->id)['FEATURE'])->toBe('two')
        ->and(Worker::query()->where('site_id', $site->id)->sole()->processes)->toBe(3);

    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($site->id), Trigger::Rollback, releaseId: $first->id);
    $world->settle();

    $restore = ConfigRun::query()->where('trigger', RunTrigger::Rollback)->sole();
    expect($restore->status)->toBe(RunStatus::Applied)
        ->and(config_env($site->id)['FEATURE'])->toBe('one')
        ->and(SiteSettings::query()->find($site->id)?->health_path)->toBe('/one')
        ->and(Worker::query()->where('site_id', $site->id)->sole()->processes)->toBe(1);
});

it('a failed deployment fails the run with deployment_failed and leaves phase B unapplied', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file());

    $world->push(config_sha('b'));
    $world->deploy->builds->fail();
    deploy_run_all($world->deploy->agents);
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(Presenters::run($run)['error_code'])->toBe('deployment_failed')
        ->and(Worker::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('fields the file manages are read-only in the dashboard until the source is disabled', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();

    $this->getJson("/sites/{$site->id}/deploy-settings")->assertOk()
        ->assertJsonPath('data.config.short', 'bbbbbbb')
        ->assertJsonPath('data.config.url', 'https://github.com/acme/shop/blob/'.config_sha('b').'/falak.yaml');

    $settings = SiteSettings::query()->find($site->id);
    $body = ['strategy' => 'zero-downtime', 'batch_size' => $settings->batch_size, 'keep_releases' => $settings->keep_releases, 'health_enabled' => true,
        'health_path' => '/other', 'health_status' => 200, 'health_timeout_s' => 10, 'health_retries' => 3, 'health_retry_delay_s' => 5];

    $this->putJson("/sites/{$site->id}/deploy-settings", $body)->assertStatus(422)->assertJsonValidationErrors(['health_path']);
    $this->putJson("/sites/{$site->id}/deploy-settings", [...$body, 'health_path' => '/healthz', 'keep_releases' => 9])->assertSessionHasNoErrors();
    expect(SiteSettings::query()->find($site->id)?->keep_releases)->toBe(9);

    $world->source->forceFill(['enabled' => false])->save();
    $this->putJson("/sites/{$site->id}/deploy-settings", $body)->assertSessionHasNoErrors();
    expect(SiteSettings::query()->find($site->id)?->health_path)->toBe('/other');
});

it('exporting an environment then planning the export gives an empty plan', function () {
    $world = config_world();
    $world->file(config_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $exporter = app(Exporter::class);
    $yaml = $exporter->render($exporter->document($environment, $world->source), 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml');

    expect($validation->errors)->toBe([])
        ->and($plan?->changes->actionable())->toBe([]);
});

it('exports dashboard names as keys that validate, and planning the export maps them back with no changes', function () {
    $world = config_world();
    $site = $world->deploy->site;
    Service::query()->where('ref_id', $site->id)->update(['name' => 'Storefront_App']);
    Schedule::query()->create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'name' => 'Nightly report', 'command' => 'php artisan report', 'expression' => '0 3 * * *']);

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $exporter = app(Exporter::class);
    $yaml = $exporter->render($exporter->document($environment, $world->source), 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml');

    expect($yaml)->toContain("  storefront-app:\n")->toContain("Nightly-report:\n")
        ->and($validation->errors)->toBe([])
        ->and($plan?->changes->actionable())->toBe([]);
});

it('a unit the file adds becomes a site through the Laravel preset: APP_KEY is generated', function () {
    $world = config_world();
    $world->file(<<<'YAML'
        version: 1
        units:
          api:
            type: app
            runtime: frankenphp
            framework: laravel
            php: "8.4"
            env: { APP_ENV: production }
        environments:
          production: { branch: main, servers: [web-1] }
        YAML);

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();
    $site = Site::query()->where('name', 'api')->sole();
    $env = config_env($site->id);

    expect($run->status)->toBe(RunStatus::Applying)
        ->and($run->plan['deploy'])->toBe(['api'])
        ->and($env['APP_ENV'])->toBe('production')
        ->and($env['APP_KEY'])->toStartWith('base64:')
        ->and(app(ManagedResources::class)->createdByFile('site', $site->id))->toBeTrue()
        ->and(Service::query()->where('ref_id', $site->id)->sole()->name)->toBe('api');

    $world->settle();
    expect($run->refresh()->status)->toBe(RunStatus::Applied);
});

it('a phase B that fails when the release is live fails the run (it never ends applied without phase B)', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file("version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    workers:\n      queue: { processes: 2 }\nenvironments:\n  production: { branch: main }\n");

    $world->push(config_sha('b'));
    // Valid when planned (a Laravel site runs queue:work); no longer by the time the release is live.
    Site::query()->whereKey($site->id)->update(['framework' => 'php']);
    $world->settle();
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->deploy[0])->toMatchArray(['unit' => 'web', 'status' => 'failed'])
        ->and($run->deploy[0]['error'])->toStartWith('Phase B failed: Only Laravel sites run queue:work')
        ->and(Presenters::run($run)['error_code'])->toBe('apply_failed')
        ->and(Worker::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('after a first run whose deployment failed, the next push applies the file before deploying', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file('one'));
    $world->push(config_sha('b'));
    $world->deploy->builds->fail();
    deploy_run_all($world->deploy->agents);
    expect(ConfigRun::query()->sole()->status)->toBe(RunStatus::Failed);

    // Fixing the file: the push is Config's, even when push to deploy sees it first (queued listeners race).
    $world->file(config_e2e_file('two', '/two'));
    app(DeployOnPush::class)->handle(new PushReceived($world->deploy->organization->id, $world->source->source_connection_id, 'github', 'acme/shop', 'main',
        new CommitData(config_sha('c'), 'Fix', 'Ada', 'ada@example.com'), 'ada', str_repeat('0', 39).'1', null));
    expect(Deployment::query()->where('site_id', $site->id)->where('commit', config_sha('c'))->exists())->toBeFalse();

    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();
    $deployments = Deployment::query()->where('site_id', $site->id)->where('commit', config_sha('c'))->get();

    expect($run->phase_a_at)->not->toBeNull()
        ->and($deployments)->toHaveCount(1)
        ->and($run->deploy[0]['deployment_id'])->toBe($deployments->first()->id)
        ->and(config_env($site->id)['FEATURE'])->toBe('two')
        ->and(SiteSettings::query()->find($site->id)?->health_path)->toBe('/two');
});

it('a push deployment queued before phase A is replaced by one queued after it', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file('one'));
    $world->push(config_sha('b'));
    $world->settle();

    // Push to deploy got the push first (e.g. a site the file manages only from this run on).
    $legacy = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($site->id), Trigger::Manual, branch: 'main', commit: config_sha('c'),
        variables: ['FALAK_VAR_REASON' => 'hotfix'], requestedBy: $world->deploy->user->id);
    $world->file(config_e2e_file('two'));
    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($legacy->refresh()->status->value)->toBe('cancelled')
        ->and($run->deploy[0]['deployment_id'])->not->toBe($legacy->id)
        ->and(strcmp($run->deploy[0]['deployment_id'], $legacy->id))->toBeGreaterThan(0);

    // The replacement is the same deploy: its trigger, requester and variables carry over.
    $replacement = Deployment::query()->findOrFail($run->deploy[0]['deployment_id']);
    expect($replacement->trigger)->toBe(Trigger::Manual)
        ->and($replacement->requested_by)->toBe($world->deploy->user->id)
        ->and($replacement->variables)->toBe(['FALAK_VAR_REASON' => 'hotfix']);
});

it('the deploy health check uses the unit health_path when deploy.health.path is unset', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\", health_path: /up }\nenvironments:\n  production: { branch: main }\n");
    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect(Deployment::query()->findOrFail($run->deploy[0]['deployment_id'])->setting('health')['path'])->toBe('/up');
});

it('after a first run whose deployment failed, a push with an unreadable file records a failed run and deploys the old way', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file('one'));
    $world->push(config_sha('b'));
    $world->deploy->builds->fail();
    deploy_run_all($world->deploy->agents);

    $world->file("version: 1\nunits: [oops\n");
    $world->push(config_sha('c'));

    expect(ConfigRun::query()->where('commit', config_sha('c'))->sole()->status)->toBe(RunStatus::Failed)
        ->and(Deployment::query()->where('site_id', $site->id)->where('commit', config_sha('c'))->exists())->toBeTrue();
});

it('after a first run whose deployment failed, dropping the environment from the file releases it', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $world->file(config_e2e_file('one'));
    $world->push(config_sha('b'));
    $world->deploy->builds->fail();
    deploy_run_all($world->deploy->agents);
    expect(app(ConfigSources::class)->managesSite($site->id))->toBeTrue();

    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  staging: { branch: main }\n");
    $world->push(config_sha('c'));

    expect(ConfigOwnership::query()->where('environment_id', $world->environment->id)->exists())->toBeFalse()
        ->and(app(ConfigSources::class)->managesSite($site->id))->toBeFalse()
        ->and(Deployment::query()->where('site_id', $site->id)->where('commit', config_sha('c'))->exists())->toBeTrue();
});
