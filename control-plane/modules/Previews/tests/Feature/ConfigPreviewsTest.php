<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Identity\Contracts\Role;
use Falak\Previews\Application\PreviewLifecycle;
use Falak\Previews\Domain\Models\Preview;
use Falak\Processes\Domain\Models\Worker;
use Falak\Projects\Domain\Models\Environment;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Sites/tests/Support/config.php';

/*
| Previews of a project that follows a falak.yaml: built from the pull request's file (a fork's: the base branch's).
*/

const PREVIEW_PR_FILE = <<<'YAML'
version: 1
units:
  web:
    type: app
    runtime: php-fpm
    php: "8.4"
    domains: [shop.example.com]
    env: { FEATURE_CHECKOUT: "v2" }
    workers:
      queue: { command: php artisan queue:work, processes: 2 }
    deploy: { strategy: in-place }
    limits: { memory: 512m }
  api:
    type: app
    runtime: node
    node: "22"
    root: api
  docs:
    type: app
    runtime: static
environments:
  production: { branch: main }
  staging:
    branch: develop
    servers: [web-1]
previews:
  enabled: true
  base: staging
  units: { docs: omit }

YAML;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->sc = sites_fake_source_control();
    $this->connection = $this->sc->addConnection($this->organization->id);
    ['domains' => $this->domains, 'databases' => $this->databases, 'deployments' => $this->deployments] = previews_fakes();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    sites_fake_agent_memory([$this->server->id => 8 * 1024 ** 3]);
    $this->production = projects_default_env($this->organization);
    $this->staging = projects_environment($this->organization, 'staging');
    $this->web = projects_site($this->organization, 'web', ['APP_KEY' => 'base64:staging'], $this->staging, [$this->server], [
        'runtime' => 'php-fpm', 'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'develop',
    ]);
    $this->settings = previews_settings($this->organization, $this->staging->project_id, $this->staging->id);
    $this->source = config_source($this->organization, $this->staging, connectionId: $this->connection->id);
    $this->sc->filesByRef[str_repeat('c', 40)]['falak.yaml'] = PREVIEW_PR_FILE;
});

function config_preview_open(object $test, bool $fork = false, string $sha = 'c'): Preview
{
    PullRequestOpened::dispatch($test->organization->id, $test->connection->id, 'github', previews_pr(sha: $sha, fork: $fork));

    return previews_sole();
}

it('builds a preview from the pull request\'s file: processes, settings, limits and new units, never the base\'s', function () {
    $preview = config_preview_open($this);
    $environment = Environment::query()->findOrFail($preview->environment_id);

    // docs is omitted by the file; api is a unit the base doesn't have yet.
    expect(array_keys($preview->sites))->toEqualCanonicalizing(['web', 'api'])
        ->and($preview->config_ref)->toBe(str_repeat('c', 40));

    $copy = Site::query()->findOrFail($preview->sites['web']);
    $api = Site::query()->with('targets')->findOrFail($preview->sites['api']);
    $variables = fn (Site $site) => EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first()?->variables ?? [];

    expect(Worker::query()->where('site_id', $copy->id)->sole()->only(['name', 'command', 'processes']))
        ->toBe(['name' => 'queue', 'command' => 'php artisan queue:work', 'processes' => 2])
        ->and(SiteSettings::query()->find($copy->id)?->strategy?->value)->toBe('in-place')
        ->and($copy->refresh()->limits)->toMatchArray(['memory_limit' => 512])
        ->and($variables($copy))->toMatchArray(['FEATURE_CHECKOUT' => 'v2'])
        // The new unit lives in the preview only: isolated, on the preview server, never deployed on push.
        ->and($api->runtime->value)->toBe('node')
        ->and($api->root_directory)->toBe('api')
        ->and($api->branch)->toBe('feature/checkout')
        ->and($api->push_to_deploy)->toBeFalse()
        ->and($api->isolated)->toBeTrue()
        ->and($api->targets->pluck('server_id')->all())->toBe([$this->server->id])
        ->and(projects_service('site', $api->id)->environment_id)->toBe($environment->id)
        // Preview hosts, not the file's domains.
        ->and(array_values($this->domains->routes))->toEqualCanonicalizing([$copy->id, $api->id])
        ->and(array_keys($this->domains->routes))->not->toContain('shop.example.com')
        ->and(ConfigRun::query()->where('environment_id', $environment->id)->sole())
        ->trigger->toBe(RunTrigger::PullRequest)->status->toBe(RunStatus::Applied);

    // The base environment is untouched.
    expect(Worker::query()->where('site_id', $this->web->id)->exists())->toBeFalse()
        ->and(SiteSettings::query()->find($this->web->id))->toBeNull()
        ->and($variables($this->web))->not->toHaveKey('FEATURE_CHECKOUT')
        ->and(ConfigOwnership::query()->where('environment_id', $this->staging->id)->exists())->toBeFalse();

    // Everything deploys at the head.
    expect(collect($this->deployments->deployed)->pluck('site_id')->all())->toEqualCanonicalizing([$copy->id, $api->id]);

    // A new head with a changed file: applied before the redeploy.
    $this->sc->filesByRef[str_repeat('d', 40)]['falak.yaml'] = str_replace('processes: 2', 'processes: 3', PREVIEW_PR_FILE);
    PullRequestUpdated::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'd'));

    expect(Worker::query()->where('site_id', $copy->id)->sole()->processes)->toBe(3)
        ->and($preview->refresh()->config_ref)->toBe(str_repeat('d', 40));

    // Closing removes the preview's sites, the file's included, and what the file managed there; nothing of the base.
    PullRequestClosed::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'd'), false);

    expect(Site::query()->whereKey([$copy->id, $api->id])->exists())->toBeFalse()
        ->and(Site::query()->find($this->web->id))->not->toBeNull()
        ->and(ConfigOwnership::query()->where('environment_id', $environment->id)->exists())->toBeFalse()
        ->and(Environment::query()->find($environment->id))->toBeNull();
});

it('fails the preview with the reason when the pull request\'s file is invalid', function () {
    $this->sc->filesByRef[str_repeat('c', 40)]['falak.yaml'] = "version: 1\nunits:\n  web: { type: app, runtime: nope }\nenvironments:\n  staging: { branch: develop }\n";

    $preview = config_preview_open($this);

    expect($preview->status)->toBe(Preview::FAILED)
        ->and($preview->status_message)->toContain('falak.yaml is invalid')->toContain('/units/web/runtime');
});

it('builds a fork\'s preview from the base branch\'s file, after the approval, and never adds its units', function () {
    $forkServer = sites_server($this->organization->id, ['name' => 'previews-1'], docker: true);
    Site::query()->whereKey($this->web->id)->update([
        'runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'docker_image' => 'ghcr.io/acme/shop:1', 'container_port' => 8080, 'app_port' => 3100,
    ]);
    $this->settings->forceFill(['fork_server_id' => $forkServer->id])->save();
    $base = "version: 1\nunits:\n  web: { type: app, runtime: docker, image: \"ghcr.io/acme/shop:1\", port: 8080, env: { FROM_FILE: base }, deploy: { strategy: rolling } }\n"
        ."environments:\n  staging: { branch: develop, servers: [web-1] }\npreviews: { enabled: true, base: staging }\n";
    // The fake provider's newest base commit is aaaa…; the fork's head (cccc…) carries its own file.
    $this->sc->filesByRef[str_repeat('a', 40)]['falak.yaml'] = $base;
    $this->sc->filesByRef[str_repeat('c', 40)]['falak.yaml'] = str_replace(['FROM_FILE: base', 'deploy: { strategy: rolling } }'], ['FROM_FILE: fork', "deploy: { strategy: rolling } }\n  api: { type: app, runtime: node, node: \"22\" }"], $base);

    $preview = config_preview_open($this, fork: true);

    expect($preview->status)->toBe(Preview::WAITING_APPROVAL)
        ->and(ConfigRun::query()->exists())->toBeFalse();

    app(PreviewLifecycle::class)->approve($preview, $this->user->id, 'ui', $preview->head_sha);
    $preview->refresh();
    $copy = Site::query()->findOrFail($preview->sites['web']);

    expect(array_keys($preview->sites))->toBe(['web'])
        ->and($preview->config_ref)->toBe(str_repeat('a', 40))
        ->and(EnvironmentVersion::query()->where('site_id', $copy->id)->orderByDesc('version')->first()->variables)->toMatchArray(['FROM_FILE' => 'base'])
        ->and(SiteSettings::query()->find($copy->id)?->strategy?->value)->toBe('rolling')
        ->and(Environment::query()->findOrFail($preview->environment_id)->is_fork_preview)->toBeTrue();
});
