<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Processes\Domain\Models\Worker;
use Falak\Projects\Application\Actions\CreateProject;
use Falak\Projects\Domain\Models\Environment;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Sites\Contracts\Framework;
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

// What `falak init` writes for a Laravel repository validates on the server too (they diverged in rc.1: a new unit's
// queue worker was refused because the unit wasn't known to be Laravel).
it('validates falak init\'s Laravel files on a new project', function (string $golden) {
    [$world, $environment, $server] = config_new_project();
    ConfigSource::query()->where('project_id', $environment->project_id)->update(['allowed_servers' => json_encode([$server])]);
    $this->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken);

    // The server has what the file asks for (the next test covers a server that doesn't).
    $php = preg_match('/php: "([0-9.]+)"/', (string) file_get_contents($golden), $m) ? $m[1] : null;

    if ($php !== null && ! $world->deploy->servers[0]->phpVersions()->where('version', $php)->exists()) {
        $world->deploy->servers[0]->phpVersions()->forceCreate(['version' => $php, 'status' => PhpVersionStatus::Installed, 'is_default' => false, 'ini' => [], 'fpm' => PhpVersion::defaultFpm(null)]);
    }

    // The one step the user adds: where production runs.
    $file = file_get_contents($golden)."environments:\n  production:\n    branch: main\n    servers: [{$server}]\n";
    $response = $this->postJson("/api/v1/projects/{$environment->project_id}/config/validate", ['file' => $file, 'environment' => 'production'])->assertOk();

    expect($response->json('data.errors'))->toBe([]);
})->with(fn () => array_map(fn (string $f) => [$f], glob(__DIR__.'/../../../../../agent/internal/cli/testdata/init/*laravel*/expected.falak.yaml') ?: []));

it('creates a unit with a `laravel` block on a PHP runtime as Laravel, with its queue worker', function () {
    [$world, $environment, $server] = config_new_project();
    ConfigSource::query()->where('project_id', $environment->project_id)->update(['allowed_servers' => json_encode([$server])]);
    $this->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken);
    // What rc.1's `falak init` wrote: no `framework`.
    $file = "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    laravel: { scheduler: true }\n"
        ."    workers:\n      queue: { connection: database }\nenvironments:\n  production: { branch: main, servers: [{$server}] }\n";

    $this->postJson("/api/v1/projects/{$environment->project_id}/config/validate", ['file' => $file, 'environment' => 'production'])->assertOk()->assertJsonPath('data.errors', []);
    $this->postJson("/api/v1/projects/{$environment->project_id}/config/runs", ['file' => $file, 'environment' => 'production'])->assertStatus(201);
    $world->settle();

    $site = Site::query()->where('name', 'web')->whereKeyNot($world->deploy->site->id)->sole();
    expect($site->framework)->toBe(Framework::Laravel)
        ->and(Worker::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(ConfigRun::query()->where('project_id', $environment->project_id)->sole()->status)->toBe(RunStatus::Applied);
});

it('reports a PHP version the unit\'s servers lack when validating and planning, not in phase A', function () {
    [$world, $environment, $server] = config_new_project();
    ConfigSource::query()->where('project_id', $environment->project_id)->update(['allowed_servers' => json_encode([$server])]);
    $this->withToken(app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['*'])->plainTextToken);
    $file = fn (string $php) => "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"{$php}\"\nenvironments:\n  production: { branch: main, servers: [{$server}] }\n";

    $this->postJson("/api/v1/projects/{$environment->project_id}/config/validate", ['file' => $file('8.3'), 'environment' => 'production'])->assertOk()
        ->assertJsonPath('data.errors.0.code', 'value.unsupported')
        ->assertJsonPath('data.errors.0.pointer', '/units/web/php')
        ->assertJsonPath('data.errors.0.message', "PHP 8.3 is not installed on {$server} (install it in Servers → {$server} → PHP).");
    $this->postJson("/api/v1/projects/{$environment->project_id}/config/plan", ['file' => $file('8.3'), 'environment' => 'production'])->assertStatus(422);

    $this->postJson("/api/v1/projects/{$environment->project_id}/config/validate", ['file' => $file('8.4'), 'environment' => 'production'])->assertOk()->assertJsonPath('data.errors', []);
});
