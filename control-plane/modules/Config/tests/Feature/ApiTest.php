<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_API_FILE = <<<'YAML'
    version: 1
    units:
      web:
        type: app
        runtime: frankenphp
        php: "8.4"
        deploy:
          strategy: rollin
    environments:
      production: { branch: main }
    YAML;

/**
 * @param  list<string>  $abilities
 */
function config_api(ConfigWorld $world, array $abilities = ['*']): void
{
    test()->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', $abilities)->plainTextToken);
}

it('validates a file: 200 with issues carrying line and column', function () {
    $world = config_world(actingAs: false);
    config_api($world);

    $response = $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/validate", ['file' => CONFIG_API_FILE])
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.environments', ['production']);

    $issue = collect($response->json('data.errors'))->firstWhere('pointer', '/units/web/deploy/strategy');
    expect($issue)->toMatchArray(['code' => 'schema', 'line' => 8, 'column' => 17, 'severity' => 'error']);

    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/validate", ['file' => str_replace('rollin', 'rolling', CONFIG_API_FILE)])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.errors', []);
});

it('runs the organization checks when validating for an environment', function () {
    $world = config_world(actingAs: false);
    config_api($world);
    $file = "version: 1\nunits:\n  web: { type: app, runtime: node, servers: [nope], env: { K: \"\${{ secrets.MISSING }}\" } }\nenvironments:\n  production: { branch: main }\n";

    $codes = collect($this->postJson("/api/v1/projects/{$world->environment->project_id}/config/validate", ['file' => $file, 'environment' => 'production'])
        ->assertOk()->json('data.errors'))->pluck('code')->all();

    expect($codes)->toContain('name.server', 'name.secret');
});

it('answers 422 in the contract shape for an invalid file and 409 without an enabled source', function () {
    $world = config_world(actingAs: false);
    config_api($world);
    $project = $world->environment->project_id;

    $this->postJson("/api/v1/projects/{$project}/config/plan", ['file' => CONFIG_API_FILE, 'environment' => 'production'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'falak.yaml is invalid (1 error).')
        ->assertJsonPath('errors.file.0', '/units/web/deploy/strategy: The data should match one item from enum.')
        ->assertJsonPath('config_errors.0.line', 8)
        ->assertJsonPath('config_errors.0.column', 17)
        ->assertJsonStructure(['config_warnings']);

    $world->source->forceFill(['enabled' => false])->save();

    $this->postJson("/api/v1/projects/{$project}/config/plan", ['file' => CONFIG_API_FILE, 'environment' => 'production'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Config as code is not enabled for this project.');
});

it('needs exactly one of file and commit, and a known environment', function () {
    $world = config_world(actingAs: false);
    config_api($world);
    $project = $world->environment->project_id;

    $this->postJson("/api/v1/projects/{$project}/config/plan", ['environment' => 'production'])->assertStatus(422)->assertJsonValidationErrors(['file', 'commit']);
    $this->postJson("/api/v1/projects/{$project}/config/plan", ['file' => 'x', 'commit' => config_sha('a'), 'environment' => 'production'])->assertStatus(422)->assertJsonValidationErrors(['file']);
    $this->postJson("/api/v1/projects/{$project}/config/plan", ['file' => 'version: 1', 'environment' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['environment']);
});

it('keeps projects of other organizations hidden and checks the token abilities', function () {
    $world = config_world(actingAs: false);
    $other = config_world(actingAs: false);
    config_api($world, ['config.view']);

    $this->postJson("/api/v1/projects/{$other->environment->project_id}/config/validate", ['file' => 'version: 1'])->assertNotFound();
    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/runs", ['file' => CONFIG_API_FILE, 'environment' => 'production'])->assertForbidden();
});

it('lists, shows and cancels runs; approve answers 409 unless the run awaits approval', function () {
    $world = config_world(actingAs: false);
    config_api($world);
    $run = ConfigRun::query()->create([
        'organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id, 'environment_id' => $world->environment->id,
        'environment' => 'production', 'trigger' => RunTrigger::Cli, 'status' => RunStatus::AwaitingApproval, 'file' => 'version: 1',
    ]);

    $this->getJson("/api/v1/projects/{$world->environment->project_id}/config/runs?environment=production")
        ->assertOk()->assertJsonPath('data.0.id', $run->id)->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/config/runs/{$run->id}")->assertOk()->assertJsonPath('data.status', 'awaiting_approval')->assertJsonPath('data.error_code', null);
    $this->postJson("/api/v1/config/runs/{$run->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/config/runs/{$run->id}/cancel")->assertStatus(409);
    $this->postJson("/api/v1/config/runs/{$run->id}/approve")->assertStatus(409)->assertJsonPath('message', 'This run is not awaiting approval.');
});

it('creating a run with an invalid file answers 422 and keeps the failed run', function () {
    $world = config_world(actingAs: false);
    config_api($world);

    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/runs", ['file' => CONFIG_API_FILE, 'environment' => 'production'])
        ->assertStatus(422)
        ->assertJsonPath('config_errors.0.pointer', '/units/web/deploy/strategy')
        ->assertJsonPath('run.status', 'failed')
        ->assertJsonPath('run.error_code', 'invalid')
        ->assertJsonPath('run.trigger', 'cli');

    expect(ConfigRun::query()->count())->toBe(1);
});

it('allow_destroy needs config.destroy', function () {
    $world = config_world(role: Role::Developer, actingAs: false);
    config_api($world);

    $this->postJson("/api/v1/projects/{$world->environment->project_id}/config/runs", ['file' => CONFIG_API_FILE, 'environment' => 'production', 'allow_destroy' => true])
        ->assertForbidden();
});
