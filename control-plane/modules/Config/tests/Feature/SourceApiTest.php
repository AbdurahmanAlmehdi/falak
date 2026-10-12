<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

/**
 * @param  list<string>  $abilities
 */
function config_source_token(ConfigWorld $world, $user = null, array $abilities = ['*']): string
{
    app('auth')->forgetGuards(); // the next request resolves this token, not the previous one

    return app(CreateApiToken::class)($user ?? $world->deploy->user, $world->deploy->organization->id, 'cli', $abilities)->plainTextToken;
}

it('shows the source over the API; 404 when none, 401 without a token', function () {
    $world = config_world(actingAs: false);
    $project = $world->environment->project_id;

    $this->getJson("/api/v1/projects/{$project}/config/source")->assertUnauthorized();

    $this->withToken(config_source_token($world, abilities: ['config.view']))->getJson("/api/v1/projects/{$project}/config/source")->assertOk()
        ->assertJsonPath('data.id', $world->source->id)
        ->assertJsonPath('data.connection_id', $world->source->source_connection_id)
        ->assertJsonPath('data.repository', 'acme/shop')
        ->assertJsonPath('data.path', 'falak.yaml')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.experimental', true)
        ->assertJsonPath('data.separate_approver', false)
        ->assertJsonPath('data.allowed_credentials', [])
        ->assertJsonStructure(['data' => ['created_at', 'updated_at']]);

    $world->source->delete();
    $this->getJson("/api/v1/projects/{$project}/config/source")->assertNotFound()->assertJsonPath('message', 'Config as code is not connected for this project.');
});

it('connects a project over the API like the dashboard does', function () {
    $world = config_world(actingAs: false);
    $project = $world->environment->project_id;
    $connection = $world->source->source_connection_id;
    $world->source->delete();

    $this->withToken(config_source_token($world))->putJson("/api/v1/projects/{$project}/config/source", [
        'connection_id' => $connection, 'repository' => 'acme/shop', 'path' => 'deploy/falak.yml', 'enabled' => true, 'allowed_credentials' => ['cf-acme'],
    ])->assertOk()
        ->assertJsonPath('data.repository', 'acme/shop')
        ->assertJsonPath('data.path', 'deploy/falak.yml')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.allowed_credentials', ['cf-acme']);

    expect(ConfigSource::enabledFor($project)?->path)->toBe('deploy/falak.yml');
});

it('validates the source like the dashboard does', function () {
    $world = config_world(actingAs: false);
    $project = $world->environment->project_id;
    $this->withToken(config_source_token($world));

    $this->putJson("/api/v1/projects/{$project}/config/source", [])->assertStatus(422)->assertJsonValidationErrors(['connection_id', 'repository', 'enabled']);
    $this->putJson("/api/v1/projects/{$project}/config/source", ['connection_id' => $world->source->source_connection_id, 'repository' => 'not a repo', 'enabled' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['repository']);
    $this->putJson("/api/v1/projects/{$project}/config/source", ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'path' => 'deploy.yaml', 'enabled' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['path']);
    // Another organization's connection is unknown here.
    $this->putJson("/api/v1/projects/{$project}/config/source", ['connection_id' => $world->deploy->sourceControl->addConnection((string) Str::ulid())->id, 'repository' => 'acme/shop', 'enabled' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['connection_id']);
});

it('checks permissions and hides projects of other organizations', function () {
    $other = config_world(actingAs: false);
    $world = config_world(actingAs: false); // the last world's fakes are the bound ones
    $project = $world->environment->project_id;
    $body = ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'enabled' => true];

    $this->withToken(config_source_token($world))->getJson("/api/v1/projects/{$other->environment->project_id}/config/source")->assertNotFound();
    $this->putJson("/api/v1/projects/{$other->environment->project_id}/config/source", $body)->assertNotFound();

    // A token without config.apply reads but can't change the source.
    $this->withToken(config_source_token($world, abilities: ['config.view']))->getJson("/api/v1/projects/{$project}/config/source")->assertOk();
    $this->putJson("/api/v1/projects/{$project}/config/source", $body)->assertForbidden();

    // Only admins change the approver rule or the credentials the file may use.
    [$developer] = memberOf($world->deploy->organization, Role::Developer);
    $this->withToken(config_source_token($world, $developer))->putJson("/api/v1/projects/{$project}/config/source", $body + ['separate_approver' => true])->assertForbidden();
    $this->putJson("/api/v1/projects/{$project}/config/source", $body + ['allowed_credentials' => ['cf-acme']])->assertForbidden();
});

it('disabling over the API cancels the waiting runs', function () {
    $world = config_world(actingAs: false);
    $run = ConfigRun::query()->create([
        'organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id, 'environment_id' => $world->environment->id,
        'environment' => 'production', 'trigger' => RunTrigger::Cli, 'status' => RunStatus::AwaitingApproval, 'file' => 'version: 1',
    ]);

    $this->withToken(config_source_token($world))->putJson("/api/v1/projects/{$world->environment->project_id}/config/source", [
        'connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'enabled' => false,
    ])->assertOk()->assertJsonPath('data.enabled', false);

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)->and($run->cancel_reason)->toBe('source_disabled');
});
