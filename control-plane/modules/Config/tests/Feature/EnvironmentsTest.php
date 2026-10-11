<?php

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_TWO_ENVS = <<<'YAML'
version: 1
units:
  web:
    type: app
    runtime: frankenphp
    php: "8.4"
    env: { APP_ENV: production }
environments:
  production: { branch: main }
  staging:
    branch: develop
    servers: [web-1]
    units:
      web:
        env: { APP_ENV: staging }

YAML;

it('creates the environments the file declares, never production, and runs each bound to the pushed branch', function () {
    $world = config_world();
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  staging: { branch: main }\n  qa: { branch: main, servers: [web-1] }\n  dev: { branch: develop }\n");

    $world->push(config_sha('b'));

    $runs = ConfigRun::query()->orderBy('environment')->get();
    $qa = Environment::query()->where('slug', 'qa')->sole();

    expect($runs->pluck('environment')->all())->toBe(['production', 'qa', 'staging'])
        ->and($runs->firstWhere('environment', 'qa')->environment_id)->toBe($qa->id)
        ->and($runs->firstWhere('environment', 'qa')->status)->not->toBe(RunStatus::Failed)
        ->and($qa->is_production)->toBeFalse()
        ->and(Environment::query()->where('slug', 'dev')->exists())->toBeFalse(); // bound to another branch: not yet

    // The file created `web` in qa (on the environment's servers).
    $site = Site::query()->whereKey(Service::query()->where('environment_id', $qa->id)->where('name', 'web')->value('ref_id'))->sole();
    expect($site->branch)->toBe('main');
});

it('flags production only through an approval; a reserved or taken name fails the run', function () {
    $world = config_world(experimental: false);
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  eu: { branch: main, production: true, servers: [web-1] }\n");

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->where('environment', 'eu')->sole();
    $eu = Environment::query()->where('slug', 'eu')->sole();

    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and(collect($run->plan['changes'])->firstWhere('path', '/environments/eu/production'))->toMatchArray(['op' => 'update', 'before' => false, 'after' => true, 'rule' => 'environment.production'])
        ->and($eu->is_production)->toBeFalse();

    app(ApproveRun::class)($run, $world->deploy->user->id);

    expect($eu->refresh()->is_production)->toBeTrue()
        ->and(ConfigOwnership::query()->where('resource_type', 'environment')->where('resource_id', $eu->id)->pluck('path')->all())->toBe(['/production']);

    // A preview's environment name, or a reserved one, is never taken over.
    Environment::query()->create(['organization_id' => $eu->organization_id, 'project_id' => $eu->project_id, 'name' => 'PR #4', 'slug' => 'pr-4', 'is_preview' => true]);
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  pr-4: { branch: main }\n  settings: { branch: main }\n");
    $world->push(config_sha('c'));

    expect(ConfigRun::query()->where('commit', config_sha('c'))->where('environment', 'pr-4')->sole())
        ->status->toBe(RunStatus::Failed)
        ->error->toContain('preview environment')
        ->and(ConfigRun::query()->where('commit', config_sha('c'))->where('environment', 'settings')->sole()->error)->toContain('reserved');
});

it('a push to each bound branch applies only its environment, with its overrides', function () {
    $world = config_world();
    $world->file(CONFIG_TWO_ENVS);

    // develop → staging only: created, the unit created there with the staging override.
    $world->push(config_sha('b'), 'develop');
    $world->settle();
    $staging = Environment::query()->where('slug', 'staging')->sole();
    $stagingSite = Site::query()->whereKey(Service::query()->where('environment_id', $staging->id)->where('name', 'web')->value('ref_id'))->sole();
    $variables = fn (Site $site) => EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first()?->variables;

    expect(ConfigRun::query()->pluck('environment')->all())->toBe(['staging'])
        ->and($stagingSite->branch)->toBe('develop')
        ->and($variables($stagingSite))->toMatchArray(['APP_ENV' => 'staging'])
        ->and(Deployment::query()->where('site_id', $stagingSite->id)->where('commit', config_sha('b'))->count())->toBe(1)
        ->and(Deployment::query()->where('site_id', $world->deploy->site->id)->count())->toBe(0);

    // main → production only, with the base value.
    $world->push(config_sha('c'));
    $world->settle();

    expect(ConfigRun::query()->where('commit', config_sha('c'))->pluck('environment')->all())->toBe(['production'])
        ->and(ConfigRun::query()->where('commit', config_sha('c'))->sole()->status)->toBe(RunStatus::Applied)
        ->and($variables($world->deploy->site->refresh()))->toMatchArray(['APP_ENV' => 'production'])
        ->and(Deployment::query()->where('site_id', $stagingSite->id)->count())->toBe(1);

    // Each environment exports its own resolved file, and planning that export changes nothing.
    $directory = app(ProjectDirectory::class);
    $exporter = app(Exporter::class);

    foreach (['production', 'staging'] as $slug) {
        $environment = collect($directory->environments($world->environment->project_id))->firstWhere('slug', $slug);
        $document = $exporter->document($environment, $world->source);
        [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $exporter->render($document, 'yaml'), 'yaml');

        expect($document['environments'][$slug]['branch'])->toBe($slug === 'production' ? 'main' : 'develop')
            ->and($validation->errors)->toBe([])
            ->and($plan?->changes->actionable())->toBe([]);
    }
});

it('an environment gone from the file is released, never deleted', function () {
    $world = config_world();
    $world->file(CONFIG_TWO_ENVS);
    $world->push(config_sha('b'), 'develop');
    $world->settle();
    $staging = Environment::query()->where('slug', 'staging')->sole();
    expect(ConfigOwnership::query()->where('environment_id', $staging->id)->exists())->toBeTrue();

    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n");
    $world->push(config_sha('c'), 'develop');
    $world->settle();
    $site = Service::query()->where('environment_id', $staging->id)->where('name', 'web')->value('ref_id');

    expect($staging->fresh())->not->toBeNull()
        ->and(ConfigOwnership::query()->where('environment_id', $staging->id)->exists())->toBeFalse()
        ->and(ConfigRun::query()->where('commit', config_sha('c'))->exists())->toBeFalse()
        // Released to push to deploy: the push still deploys it.
        ->and(Deployment::query()->where('site_id', $site)->where('commit', config_sha('c'))->count())->toBe(1);
});

it('falak apply creates an environment its file declares, for those who may manage projects', function () {
    $world = config_world(actingAs: false);
    $token = fn (array $abilities) => app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', $abilities)->plainTextToken;
    $file = "version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  qa: { branch: qa, servers: [web-1] }\n";
    $url = "/api/v1/projects/{$world->environment->project_id}/config/runs";

    $this->withToken($token(['config.view', 'config.apply']))->postJson($url, ['file' => $file, 'environment' => 'qa'])->assertStatus(422)->assertJsonValidationErrors('environment');
    app('auth')->forgetGuards();
    $this->withToken($token(['*']))->postJson($url, ['file' => $file, 'environment' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('environment');

    expect(Environment::query()->where('slug', 'qa')->exists())->toBeFalse();

    $this->withToken($token(['*']))->postJson($url, ['file' => $file, 'environment' => 'qa'])->assertCreated()->assertJsonPath('data.environment', 'qa');

    expect(Environment::query()->where('slug', 'qa')->sole()->is_production)->toBeFalse();
});
