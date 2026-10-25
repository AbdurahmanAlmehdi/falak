<?php

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Application\Compose\RepoComposeInspection;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\ComposeRewrites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Exceptions\ComposeRenderException;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\ComposeServicesUnpublished;
use Falak\SourceControl\Contracts\ProviderType;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md): the user names the compose files, Falak reads them
 * through the provider API, lists services and variables, and adjusts the project at render time.
 */
const SHOP_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:1.27-alpine
    container_name: shop-web
    ports: ["8080:80"]
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [app]
  app:
    build: ./app
    env_file: .env
    environment:
      APP_KEY: ${APP_KEY:?set APP_KEY}
      DATABASE_URL: postgres://shop:secret@db:5432/shop
      LOG_LEVEL: ${LOG_LEVEL:-info}
    volumes:
      - ./storage:/var/www/storage
    depends_on: [db]
  db:
    image: postgres:17.2
    volumes: [pg:/var/lib/postgresql/data]
  mailpit:
    image: axllent/mailpit
    profiles: [dev]
volumes:
  pg:
YAML;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->connection = $this->git->addConnection($this->organization->id);
    $this->git->files = [
        'docker/compose.yml' => SHOP_COMPOSE,
        'docker/nginx.conf' => "server { listen 80; }\n",
        'docker/app/Dockerfile' => "FROM php:8.4\n",
        'docker/compose.override.yml' => "services:\n  web:\n    image: nginx:1.28-alpine\n",
        'compose.yaml' => "services: {x: {image: busybox}}\n",
    ];
    config(['sites.test_domain' => 'falak.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
});

function compose_app_input(object $test, array $overrides = []): array
{
    return [
        'name' => 'shop',
        'runtime' => 'compose',
        'server_ids' => [$test->server->id],
        'source_connection_id' => $test->connection->id,
        'repository' => 'acme/shop',
        'branch' => 'main',
        'compose_source' => 'repo',
        'compose_files' => ['docker/compose.yml', 'docker/compose.override.yml'],
        'public_services' => [['service' => 'web', 'port' => 80]],
        'variables' => ['APP_KEY' => 'base64:abc'],
        ...$overrides,
    ];
}

it('suggests the compose files of a repository', function () {
    $this->postJson('/sites/compose/candidates', ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('data.files', ['compose.yaml', 'docker/compose.override.yml', 'docker/compose.yml']);
});

it('inspects a repository compose project: services, variables and adjustments', function () {
    $data = $this->postJson('/sites/compose/inspect', [
        'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main',
        'compose_files' => ['docker/compose.yml', 'docker/compose.override.yml'],
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->assertOk()->json('data');

    $services = collect($data['services'])->keyBy('name');

    expect($services->keys()->all())->toBe(['web', 'app', 'db'])
        ->and($services['web']['image'])->toBe('nginx:1.28-alpine')
        ->and($services['web']['binds'][0])->toMatchArray(['source' => './docker/nginx.conf', 'in_repo' => true])
        ->and($services['app']['build_context'])->toBe('./docker/app')
        ->and($services['app']['binds'][0])->toMatchArray(['source' => './docker/storage', 'in_repo' => false, 'key' => 'app:./docker/storage'])
        ->and($services['app']['env_files'][0])->toBe(['path' => 'docker/.env', 'in_repo' => false])
        ->and($services['db']['database_engine'])->toBe('postgresql')
        ->and($services['app']['database_engine'])->toBeNull()
        ->and($data['files'])->toBe(['docker/compose.yml', 'docker/compose.override.yml'])
        ->and($data['missing'])->toBe(['docker/.env', 'docker/storage']);

    $variables = collect($data['variables'])->keyBy('name');
    expect($variables['APP_KEY'])->toMatchArray(['required' => true, 'default' => null, 'services' => ['app']])
        ->and($variables['LOG_LEVEL'])->toMatchArray(['required' => false, 'default' => 'info']);

    $kinds = collect($data['adjustments'])->pluck('kind')->all();
    expect($kinds)->toContain('container_name', 'bind_to_volume', 'env_file_missing', 'restart', 'repo_files')
        ->and(Yaml::parse($data['adjusted'])['services']['web']['volumes'])->toBe(['./repo/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro'])
        ->and(Yaml::parse($data['adjusted'])['services']['app']['volumes'])->toBe(['app-docker-storage:/var/www/storage'])
        ->and($data['warnings'])->toContain('Public service web has no healthcheck: Falak can only check it through its domain.');
});

it('reports plain git servers and unknown connections', function () {
    $custom = $this->git->addConnection($this->organization->id, ProviderType::Custom, 'ssh');

    $this->postJson('/sites/compose/inspect', ['source_connection_id' => $custom->id, 'repository' => 'git@example.com:a/b.git', 'branch' => 'main'])
        ->assertOk()->assertJsonPath('data.no_api', true);

    $other = $this->git->addConnection(memberOf()[1]->id);
    $this->postJson('/sites/compose/inspect', ['source_connection_id' => $other->id, 'repository' => 'acme/shop', 'branch' => 'main'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_connection_id');
});

it('creates a compose app from several files and refuses missing required variables', function () {
    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, ['variables' => []])))
        ->toThrow(ValidationException::class, 'APP_KEY');

    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, ['public_services' => [['service' => 'mailpit', 'port' => 8025]]])))
        ->toThrow(ValidationException::class, 'no service mailpit');

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_profiles' => ['dev'],
        'compose_adjustments' => ['keep_binds' => ['app:./docker/storage']],
    ]));
    $compose = $created->site->compose;

    expect($compose->files)->toBe(['docker/compose.yml', 'docker/compose.override.yml'])
        ->and($compose->file)->toBe('docker/compose.yml')
        ->and($compose->profiles)->toBe(['dev'])
        ->and($compose->adjustments)->toBe(['keep_binds' => ['app:./docker/storage']]);
});

it('leaves a service in the stack with a warning when its extraction fails', function () {
    app()->instance(ComposeServiceExtraction::class, new class implements ComposeServiceExtraction
    {
        public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
        {
            throw ValidationException::withMessages(['engine' => 'The leader server has no PostgreSQL engine.']);
        }

        public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
        {
            throw new LogicException('not used');
        }

        public function rewrites(string $siteId): ComposeRewrites
        {
            return new ComposeRewrites;
        }
    });

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']],
    ]));

    expect($created->warnings)->toContain('db stays in the stack: The leader server has no PostgreSQL engine.')
        ->and($created->site->compose->mode('db'))->toBe('keep');
});

it('extracts services through the extraction contract and drops them from the public list', function () {
    $extraction = new class implements ComposeServiceExtraction
    {
        public array $calls = [];

        public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
        {
            $this->calls[] = [$service, $engine];
            $site = Site::query()->findOrFail($siteId);
            $site->forceFill(['compose_services' => [...(array) $site->compose_services, $service => ['mode' => 'database', 'database_id' => '01j9zq4n8v2m6r0t3w5y7b9d1f']]])->save();

            return new DatabaseData('01j9zq4n8v2m6r0t3w5y7b9d1f', $site->organization_id, $site->targets()->first()->server_id, 'shop', 'postgresql', '17', 5432, 'active', $siteId);
        }

        public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
        {
            throw new LogicException('not used');
        }

        public function rewrites(string $siteId): ComposeRewrites
        {
            return new ComposeRewrites(['app' => ['DATABASE_URL' => '${{ shop-db.DATABASE_URL }}']]);
        }
    };
    app()->instance(ComposeServiceExtraction::class, $extraction);

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']],
        'public_services' => [['service' => 'web', 'port' => 80], ['service' => 'db', 'port' => 5432]],
    ]));

    expect($extraction->calls)->toBe([['db', 'postgresql']])
        ->and($created->site->compose->extracted())->toBe(['db'])
        ->and(array_map(fn ($p) => $p->service, $created->site->compose->publicServices))->toBe(['web']);

    $project = Yaml::dump(Yaml::parse(SHOP_COMPOSE));
    $rendered = Yaml::parse(app(ComposeSites::class)->render($created->site->id, $project, ['app' => 'registry.falak.test/falak/shop/app@sha256:'.str_repeat('a', 64)], '01j9zq4n8v2m6r0t3w5y7b9d1f')->yaml);

    expect($rendered['services'])->not->toHaveKey('db')
        ->and($rendered['services']['app']['depends_on'] ?? [])->toBe([])
        ->and($rendered['services']['app']['environment']['DATABASE_URL'])->toBe('${FALAK_SVC_APP_DATABASE_URL}');
});

it('requires only the variables of services that stay in the stack', function () {
    $rewrites = new ComposeRewrites;
    app()->instance(ComposeServiceExtraction::class, new class($rewrites) implements ComposeServiceExtraction
    {
        public function __construct(public ComposeRewrites $rewrites) {}

        public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
        {
            $site = Site::query()->findOrFail($siteId);
            $site->forceFill(['compose_services' => [$service => ['mode' => 'database', 'database_id' => '01j9zq4n8v2m6r0t3w5y7b9d1f']]])->save();

            return new DatabaseData('01j9zq4n8v2m6r0t3w5y7b9d1f', $site->organization_id, $site->targets()->first()->server_id, 'shop', 'postgresql', '17', 5432, 'active', $siteId);
        }

        public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
        {
            throw new LogicException('not used');
        }

        public function rewrites(string $siteId): ComposeRewrites
        {
            return $this->rewrites;
        }
    });
    $this->git->files['docker/compose.yml'] = "services:\n  app:\n    image: nginx\n    environment: {APP_KEY: \"\${APP_KEY:?set it}\"}\n  db:\n    image: postgres:17\n    environment: {POSTGRES_PASSWORD: \"\${DB_PASSWORD:?set it}\"}\n";
    $input = compose_app_input($this, ['compose_files' => ['docker/compose.yml'], 'public_services' => [['service' => 'app', 'port' => 80]]]);

    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, $input))->toThrow(ValidationException::class, 'DB_PASSWORD');

    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [...$input, 'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']]])->site;

    expect($site->compose->extracted())->toBe(['db']);

    // Settings → Compose: the app reads the password too, but its DATABASE_URL now points at the Falak database.
    $this->git->files['docker/compose.yml'] = "services:\n  app:\n    image: nginx\n    environment: {APP_KEY: \"\${APP_KEY:?set it}\", DATABASE_URL: \"postgres://shop:\${DB_PASSWORD:?set it}@db/shop\"}\n  db:\n    image: postgres:17\n    environment: {POSTGRES_PASSWORD: \"\${DB_PASSWORD:?set it}\"}\n";
    $save = ['compose_source' => 'repo', 'compose_files' => ['docker/compose.yml'], 'compose_profiles' => [], 'public_services' => [['service' => 'app', 'port' => 80]]];

    $this->putJson("/sites/{$site->id}/compose", $save)->assertUnprocessable()->assertJsonValidationErrors(['variables' => 'DB_PASSWORD']);

    $this->app->make(ComposeServiceExtraction::class)->rewrites = new ComposeRewrites(['app' => ['DATABASE_URL' => '${{ shop-db.DATABASE_URL }}']]);

    $this->putJson("/sites/{$site->id}/compose", $save)->assertOk();
});

it('renders repository projects against the files shipped with the release', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this))->site;
    $project = <<<'YAML'
services:
  web:
    image: nginx:1.28-alpine
    container_name: shop-web
    volumes: ["./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro", "./docker/storage:/data"]
    env_file: [./docker/.env.example, ./docker/.env]
    healthcheck: {test: ["CMD", "true"]}
configs:
  site: {file: ./docker/site.conf}
YAML;

    expect(fn () => app(ComposeSites::class)->render($site->id, $project, [], '01j9zq4n8v2m6r0t3w5y7b9d1f', ['docker/nginx.conf', 'docker/.env.example']))
        ->toThrow(ComposeRenderException::class, 'configs.site: docker/site.conf is not in the repository');

    $rendered = Yaml::parse(app(ComposeSites::class)->render($site->id, $project, [], '01j9zq4n8v2m6r0t3w5y7b9d1f', ['docker/nginx.conf', 'docker/.env.example', 'docker/site.conf'])->yaml);
    $web = $rendered['services']['web'];

    expect($web)->not->toHaveKey('container_name')
        ->and($web['restart'])->toBe('unless-stopped')
        ->and($web['volumes'])->toBe(['./repo/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro', 'web-docker-storage:/data'])
        ->and($web['env_file'])->toBe(['./repo/docker/.env.example', '.env'])
        ->and($rendered['configs']['site']['file'])->toBe('./repo/docker/site.conf')
        ->and($rendered['volumes'])->toHaveKey('web-docker-storage');

    // Builders that don't merge projects (no shipped file list) keep the old behaviour.
    $legacy = Yaml::parse(app(ComposeSites::class)->render($site->id, "services:\n  web:\n    image: nginx\n    container_name: x\n    volumes: [./conf:/c]\n", [], '01j9zq4n8v2m6r0t3w5y7b9d1f')->yaml);
    expect($legacy['services']['web']['container_name'])->toBe('x')
        ->and($legacy['services']['web']['volumes'])->toBe(['./conf:/c']);
});

it('saves compose project settings and reports services that are no longer public', function () {
    Event::fake([ComposeServicesUnpublished::class]);
    $site = Site::query()->findOrFail(app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'public_services' => [['service' => 'web', 'port' => 80], ['service' => 'app', 'port' => 9000]],
    ]))->site->id);

    $this->putJson("/sites/{$site->id}/compose", [
        'compose_source' => 'repo',
        'compose_files' => ['docker/compose.yml'],
        'compose_profiles' => [],
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->assertOk();

    expect($site->refresh()->composeFiles())->toBe(['docker/compose.yml']);
    Event::assertDispatched(ComposeServicesUnpublished::class, fn ($e) => $e->siteId === $site->id && $e->services === ['app']);

    $this->postJson("/sites/{$site->id}/compose/inspect", ['compose_profiles' => ['dev']])
        ->assertOk()
        ->assertJsonPath('data.services.3.name', 'mailpit');
});

it('lets viewers see the saved project only: no overrides, no YAML, no env-file values', function () {
    $this->git->files['docker/app.env'] = "SECRET_TOKEN=hunter2\n";
    $this->git->files['docker/compose.yml'] = SHOP_COMPOSE."\n";
    $this->git->files['docker/compose.yml'] = str_replace('env_file: .env', 'env_file: app.env', SHOP_COMPOSE);
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this))->site;

    $full = $this->postJson("/sites/{$site->id}/compose/inspect")->assertOk()->json('data');
    expect($full)->toHaveKeys(['original', 'adjusted'])
        ->and(collect($full['variables'])->firstWhere('name', 'SECRET_TOKEN')['default'])->toBe('hunter2');

    actingAsMember(Role::Viewer, $this->organization);
    $viewer = $this->postJson("/sites/{$site->id}/compose/inspect")->assertOk()->json('data');
    expect($viewer)->not->toHaveKeys(['original', 'adjusted'])
        ->and(collect($viewer['variables'])->firstWhere('name', 'SECRET_TOKEN')['default'])->toBeNull()
        ->and(json_encode($viewer))->not->toContain('hunter2');

    $this->postJson("/sites/{$site->id}/compose/inspect", ['compose_files' => ['compose.yaml']])->assertForbidden();
    $this->postJson("/sites/{$site->id}/compose/candidates")->assertForbidden();
});

it('reads the project under the site root directory, like the builder', function () {
    $this->git->files = [
        'apps/shop/compose.yaml' => "services:\n  web:\n    image: nginx\n    volumes: [./conf:/etc/nginx/conf.d]\n",
        'apps/shop/conf/default.conf' => "server {}\n",
        'compose.yaml' => "services: {other: {image: busybox}}\n",
    ];
    $base = ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main', 'root_directory' => 'apps/shop'];

    $this->postJson('/sites/compose/candidates', $base)->assertOk()->assertJsonPath('data.files', ['compose.yaml']);
    $data = $this->postJson('/sites/compose/inspect', [...$base, 'compose_files' => ['compose.yaml']])->assertOk()->json('data');

    expect(array_column($data['services'], 'name'))->toBe(['web'])
        ->and($data['services'][0]['binds'][0])->toMatchArray(['source' => './conf', 'in_repo' => true])
        ->and($data['missing'])->toBe([])
        ->and($this->git->existsCalls)->toContain('apps/shop/conf');
});

it('keeps Falak release files bound by existing stacks and adds Falak variables only where an env file is missing', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this))->site;
    $project = <<<'YAML'
services:
  web:
    image: nginx
  app:
    image: shop
    volumes: ["./.env:/app/.env:ro", "./compose.yaml:/srv/compose.yaml:ro"]
    env_file: [./.env]
  worker:
    image: shop
    env_file: [./docker/worker.env]
  mailer:
    image: third/party
    env_file: [./docker/missing.env]
YAML;

    $rendered = Yaml::parse(app(ComposeSites::class)->render($site->id, $project, [], '01j9zq4n8v2m6r0t3w5y7b9d1f', ['docker/worker.env'])->yaml);

    expect($rendered['services']['app']['volumes'])->toBe(['./.env:/app/.env:ro', './compose.yaml:/srv/compose.yaml:ro'])
        ->and($rendered['services']['app']['env_file'])->toBe(['./.env'])
        ->and($rendered['services']['worker']['env_file'])->toBe(['./repo/docker/worker.env'])
        ->and($rendered['services']['mailer']['env_file'])->toBe(['.env'])
        ->and($rendered)->not->toHaveKey('volumes');
});

it('marks only ${VAR:?} and ${VAR?} as required and ignores services moved out of the stack', function () {
    expect(RepoComposeInspection::interpolations('${A} ${B:?need B} ${C?} ${D:-x} $E ${F:+y}'))->toBe([
        'A' => [null, false], 'B' => [null, true], 'C' => [null, true], 'D' => ['x', false], 'E' => [null, false], 'F' => [null, false],
    ]);

    $this->git->files['docker/compose.yml'] = "services:\n  web:\n    image: nginx\n  db:\n    image: postgres:17\n    environment: {POSTGRES_PASSWORD: \"\${DB_PASSWORD:?set it}\"}\n";
    $data = $this->postJson('/sites/compose/inspect', [
        'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main',
        'compose_files' => ['docker/compose.yml'], 'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']],
    ])->assertOk()->json('data');

    expect(array_column($data['variables'], 'name'))->not->toContain('DB_PASSWORD');
});

it('runs a service as its own site from its build context, with the compose file in a subfolder or at the root', function (array $files, array $input, string $service, string $expected) {
    $this->git->files = $files;
    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        ...$input,
        'compose_services' => [$service => ['mode' => 'site', 'site' => ['name' => "split-{$service}", 'runtime' => 'docker', 'framework' => 'docker']]],
        'public_services' => [],
    ]));

    $split = Site::query()->where('name', "split-{$service}")->firstOrFail();

    expect($created->warnings)->toBe([])
        ->and($split->root_directory)->toBe($expected)
        ->and($created->site->compose->mode($service))->toBe('site');

    // Without the merged project (Settings → Compose without compose_files), the repository is read the same way.
    Site::query()->whereKey($created->site->id)->update(['compose_services' => null]);
    $again = app(ComposeServiceExtraction::class)->toSite($created->site->id, $service, ['name' => "again-{$service}", 'runtime' => 'docker', 'framework' => 'docker']);

    expect($again->rootDirectory)->toBe($expected);
})->with([
    'compose file in a subfolder' => [
        ['docker/compose.yml' => SHOP_COMPOSE, 'docker/compose.override.yml' => "services: {}\n", 'docker/app/Dockerfile' => "FROM php:8.4\n"],
        [], 'app', 'docker/app',
    ],
    'context above the compose file' => [
        ['deploy/compose.yml' => "services:\n  api:\n    build: {context: ../api}\n"],
        ['compose_files' => ['deploy/compose.yml'], 'variables' => []], 'api', 'api',
    ],
    'compose file at the root' => [
        ['compose.yaml' => "services:\n  api:\n    build: ./api\n    ports: ['8000:8000']\n"],
        ['compose_files' => ['compose.yaml'], 'variables' => []], 'api', 'api',
    ],
    'stack in a root directory' => [
        ['apps/shop/deploy/compose.yml' => "services:\n  api:\n    build: ../api\n", 'deploy/compose.yml' => "services: {other: {build: ./nope}}\n"],
        ['root_directory' => 'apps/shop', 'compose_files' => ['deploy/compose.yml'], 'variables' => []], 'api', 'apps/shop/api',
    ],
]);

it('leaves services in the stack with a warning when the repository cannot be read (plain git servers)', function () {
    $custom = $this->git->addConnection($this->organization->id, ProviderType::Custom, 'ssh');

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'source_connection_id' => $custom->id,
        'repository' => 'git@example.com:acme/shop.git',
        'compose_services' => [
            'db' => ['mode' => 'database', 'engine' => 'postgresql'],
            'app' => ['mode' => 'site', 'site' => ['name' => 'shop-app', 'runtime' => 'docker', 'framework' => 'docker']],
        ],
    ]));

    expect($created->warnings)->toHaveCount(2)
        ->and($created->warnings[0])->toStartWith('db stays in the stack: ')
        ->and($created->warnings[1])->toStartWith('app stays in the stack: ')
        ->and($created->site->compose->mode('db'))->toBe('keep')
        ->and($created->site->compose->mode('app'))->toBe('keep');
});

it('keeps the project read at creation for the canvas, before the first deploy', function () {
    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this));

    $project = app(ComposeSites::class)->project($created->site->id);
    expect($project)->not->toBeNull()
        ->and(array_keys(Yaml::parse((string) $project)['services']))->toContain('web');
});

it('offers official Redis and Valkey images as Falak databases, not redis-stack, bitnami or built images', function () {
    $this->git->files['caches/compose.yml'] = <<<'YAML'
services:
  app: {image: nginx}
  cache: {image: redis:7.4-alpine}
  library: {image: docker.io/library/redis@sha256:0123}
  sessions: {image: valkey/valkey:8}
  stack: {image: redis/redis-stack:latest}
  bitnami: {image: bitnami/redis:7.2}
  mirror: {image: registry.example.com/redis:7}
  custom: {image: redis:7, build: ./redis}
YAML;

    $services = collect($this->postJson('/sites/compose/inspect', [
        'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main',
        'compose_files' => ['caches/compose.yml'], 'public_services' => [['service' => 'app', 'port' => 80]],
    ])->assertOk()->json('data.services'))->pluck('database_engine', 'name')->all();

    expect($services)->toBe(['app' => null, 'cache' => 'redis', 'library' => 'redis', 'sessions' => 'valkey', 'stack' => null, 'bitnami' => null, 'mirror' => null, 'custom' => null]);
});

it('gives inline projects a restart policy at deploy where none is set, and nothing else of the repository adjustments', function () {
    $inline = <<<'YAML'
services:
  web:
    image: nginx:1.27-alpine
    container_name: shop-web
    volumes: [./conf:/etc/nginx/conf.d:ro]
  worker:
    image: busybox
    restart: on-failure
  cron:
    image: busybox
    deploy: {restart_policy: {condition: any}}
YAML;
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'inline', 'runtime' => 'compose', 'server_ids' => [$this->server->id], 'compose_source' => 'inline', 'compose_content' => $inline,
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->site;

    // The stored file stays as written; the policy is added at render, so existing inline sites get it on their next deploy.
    expect(Site::query()->findOrFail($site->id)->composeConfig()?->source->value)->toBe('inline');
    $rendered = app(ComposeSites::class)->render($site->id, $inline, [], '01j9zq4n8v2m6r0t3w5y7b9d1f');
    $services = Yaml::parse($rendered->yaml)['services'];

    expect($services['web']['restart'])->toBe('unless-stopped')
        ->and($services['web']['container_name'])->toBe('shop-web')
        ->and($services['web']['volumes'])->toBe(['./conf:/etc/nginx/conf.d:ro'])
        ->and($services['worker']['restart'])->toBe('on-failure')
        ->and($services['cron'])->not->toHaveKey('restart');
});
