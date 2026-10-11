<?php

use Falak\Config\Application\Drift\DriftChecker;
use Falak\Config\Application\Jobs\CheckDrift;
use Falak\Config\Domain\Models\ConfigDrift;
use Falak\Config\Events\ConfigDriftDetected;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_DRIFT_FILE = "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    env: { FEATURE: \"secret-on\" }\n    deploy: { health: { path: /healthz } }\nenvironments:\n  production: { branch: main }\n";

it('finds drift against the last applied run, alerts once per signature, and again after it was back in sync', function () {
    Event::fake([ConfigDriftDetected::class]);
    $world = config_world(actingAs: false);
    $token = app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['config.view'])->plainTextToken;
    $project = $world->environment->project_id;

    $this->withToken($token)->getJson("/api/v1/projects/{$project}/config/drift?environment=production")->assertOk()
        ->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.changes', []);

    $world->file(CONFIG_DRIFT_FILE);
    $world->push(config_sha('b'));
    $world->settle();

    $this->postJson("/api/v1/projects/{$project}/config/drift", ['environment' => 'production'])->assertOk()
        ->assertJsonPath('data.status', 'in_sync')->assertJsonPath('data.baseline_commit', config_sha('b'));

    // Changed outside the dashboard's locks (the API, an agent, another module).
    SiteSettings::query()->whereKey($world->deploy->site->id)->update(['health_path' => '/other']);
    EnvironmentVersion::query()->create(['site_id' => $world->deploy->site->id, 'version' => 99,
        'variables' => [...EnvironmentVersion::query()->where('site_id', $world->deploy->site->id)->orderByDesc('version')->first()->variables, 'FEATURE' => 'tampered'],
        'exposed' => [], 'changed_keys' => [], 'created_at' => now()]);
    (new CheckDrift)->handle(app(DriftChecker::class), app(ProjectDirectory::class));

    $drift = $this->getJson("/api/v1/projects/{$project}/config/drift?environment=production")->assertOk()
        ->assertJsonPath('data.status', 'drifted')->json('data');
    expect(collect($drift['changes'])->pluck('path')->all())->toContain('/units/web/deploy/health/path', '/units/web/env/FEATURE')
        ->and(json_encode($drift))->not->toContain('tampered')->not->toContain('secret-on')
        ->and($drift['signature'])->not->toBeNull();
    Event::assertDispatchedTimes(ConfigDriftDetected::class, 1);

    // The same drift again: no new alert.
    $this->postJson("/api/v1/projects/{$project}/config/drift", ['environment' => 'production'])->assertJsonPath('data.status', 'drifted');
    Event::assertDispatchedTimes(ConfigDriftDetected::class, 1);

    // Back in sync, then the same drift once more: alerted again.
    SiteSettings::query()->whereKey($world->deploy->site->id)->update(['health_path' => '/healthz']);
    EnvironmentVersion::query()->where('site_id', $world->deploy->site->id)->where('version', 99)->delete();
    $this->postJson("/api/v1/projects/{$project}/config/drift", ['environment' => 'production'])->assertJsonPath('data.status', 'in_sync');
    SiteSettings::query()->whereKey($world->deploy->site->id)->update(['health_path' => '/other']);
    $this->postJson("/api/v1/projects/{$project}/config/drift", ['environment' => 'production'])->assertJsonPath('data.status', 'drifted');
    Event::assertDispatchedTimes(ConfigDriftDetected::class, 2);
    expect(ConfigDrift::query()->count())->toBe(1);
});

it('reports no verdict while a run is applying', function () {
    $world = config_world();
    $world->file(CONFIG_DRIFT_FILE);
    $world->push(config_sha('b'));
    $world->settle();
    $world->push(config_sha('c')); // applying: its deployment hasn't finished

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    expect(app(DriftChecker::class)->check($environment)->status)->toBe(ConfigDrift::UNKNOWN);
});
