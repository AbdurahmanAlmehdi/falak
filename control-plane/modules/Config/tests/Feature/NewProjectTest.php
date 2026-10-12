<?php

use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Application\Actions\CreateProject;
use Falak\Projects\Domain\Models\Environment;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

/**
 * A project made for the file: it hosts nothing yet, so no server is attached to it.
 *
 * @return array{0: ConfigWorld, 1: Environment, 2: string}
 */
function config_new_project(): array
{
    $world = config_world(actingAs: false);
    $project = app(CreateProject::class)($world->deploy->organization->id, $world->deploy->user->id, ['name' => 'Fresh']);
    $environment = $project->production();
    ConfigSource::query()->create([
        'organization_id' => $world->deploy->organization->id, 'project_id' => $project->id,
        'source_connection_id' => $world->source->source_connection_id, 'repository' => 'acme/fresh', 'path' => 'falak.yaml', 'enabled' => true,
    ]);

    return [$world, $environment, $world->deploy->servers[0]->name];
}

function config_new_project_file(string $server): string
{
    return "version: 1\nunits:\n  web: { type: app, runtime: node }\nenvironments:\n  production: { branch: main, servers: [{$server}] }\n";
}

it('a new project places its first unit on a server an admin allows', function () {
    [$world, $environment, $server] = config_new_project();
    $project = $environment->project_id;
    $this->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken);

    $codes = fn () => collect($this->postJson("/api/v1/projects/{$project}/config/validate", ['file' => config_new_project_file($server), 'environment' => 'production'])
        ->assertOk()->json('data.errors'))->pluck('code')->all();

    expect($codes())->toContain('name.server');

    $this->putJson("/api/v1/projects/{$project}/config/source", ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/fresh', 'enabled' => true, 'allowed_servers' => [$server]])
        ->assertOk()->assertJsonPath('data.allowed_servers', [$server]);

    expect($codes())->toBe([]);

    $this->postJson("/api/v1/projects/{$project}/config/runs", ['file' => config_new_project_file($server), 'environment' => 'production'])->assertStatus(201);
    $world->settle();

    $site = Site::query()->where('name', 'web')->whereKeyNot($world->deploy->site->id)->sole();
    expect($site->targets()->pluck('server_id')->all())->toBe([$world->deploy->servers[0]->id]);
});

it('only admins change the allowed servers, and only to servers of the organization', function () {
    [$world, $environment, $server] = config_new_project();
    $project = $environment->project_id;
    $body = ['connection_id' => $world->source->source_connection_id, 'repository' => 'acme/fresh', 'enabled' => true];

    [$developer] = memberOf($world->deploy->organization, Role::Developer);
    $this->withToken(app(CreateApiToken::class)($developer, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken)
        ->putJson("/api/v1/projects/{$project}/config/source", $body + ['allowed_servers' => [$server]])->assertForbidden();
    // Sending the list as it is is fine.
    $this->putJson("/api/v1/projects/{$project}/config/source", $body + ['allowed_servers' => []])->assertOk();

    app('auth')->forgetGuards();
    $this->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken)
        ->putJson("/api/v1/projects/{$project}/config/source", $body + ['allowed_servers' => ['nope']])
        ->assertStatus(422)->assertJsonValidationErrors(['allowed_servers']);
});
