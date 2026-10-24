<?php

use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\ComposeSource;
use Falak\Sites\Contracts\Exceptions\ComposeRenderException;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\OrganizationSettings;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

const N8N_COMPOSE = <<<'YAML'
services:
  n8n:
    image: n8nio/n8n:1.64.0
    ports: ["5678:5678"]
    environment:
      N8N_ENCRYPTION_KEY: ${N8N_ENCRYPTION_KEY}
      WEBHOOK_URL: ${WEBHOOK_URL}
    volumes: [n8n-data:/home/node/.n8n]
    labels:
      falak.deploy.leader_command: "n8n migrate --flag 'two words'"
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:5678/healthz"]
  postgres:
    image: postgres:17.2
    expose: ["5432"]
    volumes:
      - pg-data:/var/lib/postgresql/data
      - ./init:/docker-entrypoint-initdb.d:ro
volumes:
  n8n-data:
  pg-data: {}
YAML;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'falak.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
});

function compose_input(array $servers, array $overrides = []): array
{
    return [
        'name' => 'n8n',
        'runtime' => 'compose',
        'server_ids' => $servers,
        'compose_source' => 'inline',
        'compose_content' => N8N_COMPOSE,
        'public_services' => [['service' => 'n8n', 'port' => 5678, 'domain' => null]],
        'variables' => ['N8N_ENCRYPTION_KEY' => 's3cret', 'WEBHOOK_URL' => '${{ n8n.APP_URL }}'],
        'template' => ['slug' => 'n8n', 'version' => '1.0.0', 'source' => 'catalog'],
        ...$overrides,
    ];
}

it('parses services, ports, volumes and leader commands', function () {
    $summary = app(ComposeInspector::class)->parse(N8N_COMPOSE);

    expect($summary->valid())->toBeTrue()
        ->and($summary->passesPolicy())->toBeTrue()
        ->and($summary->serviceNames())->toBe(['n8n', 'postgres'])
        ->and($summary->volumes)->toBe(['n8n-data', 'pg-data'])
        ->and($summary->service('n8n')->ports)->toBe([5678])
        ->and($summary->service('n8n')->healthcheck)->toBeTrue()
        ->and($summary->service('n8n')->leaderCommand)->toBe("n8n migrate --flag 'two words'")
        ->and($summary->service('postgres')->ports)->toBe([5432])
        ->and($summary->service('postgres')->volumes)->toBe(['pg-data'])
        ->and($summary->service('postgres')->bindMounts)->toBe(['./init'])
        ->and($summary->warnings)->toHaveCount(1);

    expect(app(ComposeInspector::class)->parse('services: [')->errors)->not->toBeEmpty()
        ->and(app(ComposeInspector::class)->parse("version: '3'")->errors)->toContain('The compose file has no services.')
        ->and(app(ComposeInspector::class)->parse("services:\n  x: {}\n")->errors)->toContain('Service x has neither `image` nor `build`.');
});

it('reports every policy violation', function (string $service, string $expected) {
    $summary = app(ComposeInspector::class)->parse("services:\n  bad:\n    image: alpine:3\n".$service);

    expect($summary->valid())->toBeTrue()
        ->and(implode(' ', $summary->violations))->toContain($expected);
})->with([
    'privileged' => ["    privileged: true\n", 'runs privileged'],
    'host network' => ["    network_mode: host\n", 'host network namespace'],
    'host pid' => ["    pid: host\n", 'host PID namespace'],
    'capabilities' => ["    cap_add: [NET_ADMIN]\n", 'adds the capability NET_ADMIN'],
    'devices' => ["    devices: ['/dev/fuse:/dev/fuse']\n", 'maps host devices'],
    'host bind' => ["    volumes: ['/etc:/host-etc']\n", 'bind-mounts the host path /etc'],
    'escaping bind' => ["    volumes: ['../../secrets:/s']\n", 'bind-mounts the host path ../../secrets'],
    'long bind' => ["    volumes: [{type: bind, source: /srv, target: /srv}]\n", 'bind-mounts the host path /srv'],
    'docker socket' => ["    volumes: ['/var/run/docker.sock:/var/run/docker.sock']\n", 'mounts the Docker socket'],
    'unconfined' => ["    security_opt: ['seccomp:unconfined']\n", 'disables a security profile'],
]);

it('allows safe capabilities, named volumes and relative binds inside the release', function () {
    $summary = app(ComposeInspector::class)->parse("services:\n  ok:\n    image: alpine:3\n    cap_add: [CHOWN, cap_net_bind_service]\n    volumes: ['data:/data', './conf:/conf', 'x/../y:/y']\nvolumes:\n  data:\n");

    expect($summary->violations)->toBe([]);
    expect(app(ComposeInspector::class)->parse("services:\n  a: {image: x}\nvolumes:\n  v:\n    driver_opts: {type: none, o: bind, device: /etc}\n")->violations)
        ->toContain('Volume v binds the host path /etc (driver_opts.device).');
});

it('creates compose sites through the SiteFactory contract (§5)', function () {
    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id]));
    $site = Site::query()->with('latestEnvironment')->findOrFail($created->site->id);
    $compose = $created->site->compose;

    expect($created->site->runtime->value)->toBe('compose')
        ->and($created->site->framework->value)->toBe('docker')
        ->and($compose->source)->toBe(ComposeSource::Inline)
        ->and($compose->version)->toBe(1)
        ->and($compose->template)->toBe(['slug' => 'n8n', 'version' => '1.0.0', 'source' => 'catalog'])
        ->and($compose->primary()->service)->toBe('n8n')
        ->and($compose->primary()->hostPort)->toBe(3000)
        ->and($compose->primary()->testDomain)->toBe('n8n.falak.test')
        ->and($created->site->appPort)->toBe(3000)
        ->and($site->latestEnvironment->variables)->toMatchArray(['N8N_ENCRYPTION_KEY' => 's3cret', 'WEBHOOK_URL' => '${{ n8n.APP_URL }}'])
        ->and($site->latestEnvironment->variables)->not->toHaveKey('PORT')
        ->and(app(ComposeSites::class)->content($site->id)->content)->toBe(N8N_COMPOSE."\n");

    // Encrypted at rest.
    expect(ComposeVersion::query()->toBase()->where('site_id', $site->id)->value('content'))->not->toContain('n8nio');

    // A second compose site on the server gets the next free port.
    $other = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id], ['name' => 'n8n-2', 'template' => null]));
    expect($other->site->compose->primary()->hostPort)->toBe(3001)
        ->and(app(SiteDirectory::class)->find($other->site->id)->compose->template)->toBeNull();
});

it('rejects invalid compose sites', function (array $overrides, string $field) {
    try {
        app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id], $overrides));
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'builds inline' => [['compose_content' => "services:\n  app:\n    build: .\n"], 'compose_content'],
    'policy' => [['compose_content' => "services:\n  n8n:\n    image: x:1\n    privileged: true\n"], 'compose_content'],
    'unknown public service' => [['public_services' => [['service' => 'web', 'port' => 80]]], 'public_services.0.service'],
    'bad port' => [['public_services' => [['service' => 'n8n', 'port' => 0]]], 'public_services.0.port'],
    'bad domain' => [['public_services' => [['service' => 'n8n', 'port' => 5678, 'domain' => 'not a domain']]], 'public_services.0.domain'],
    'bad variable' => [['variables' => ['1BAD' => 'x']], 'variables'],
    'bad template' => [['template' => ['slug' => 'n8n', 'version' => '1', 'source' => 'elsewhere']], 'template.source'],
    'empty inline' => [['compose_content' => '   '], 'compose_content'],
]);

it('allows policy violations when the organization allows privileged compose', function () {
    OrganizationSettings::query()->create(['organization_id' => $this->organization->id, 'allow_privileged_compose' => true]);

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id], [
        'compose_content' => "services:\n  n8n:\n    image: x:1\n    privileged: true\n",
    ]));

    expect($created->site->compose->source)->toBe(ComposeSource::Inline);
});

it('refuses policy violations on a managed install, whatever the organization saved', function () {
    config(['controlplane.managed' => true]);
    OrganizationSettings::query()->create(['organization_id' => $this->organization->id, 'allow_privileged_compose' => true]);

    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id], [
        'compose_content' => "services:\n  n8n:\n    image: x:1\n    privileged: true\n",
    ])))->toThrow(ValidationException::class);
});

it('renders a fork preview under the strict policy, whatever the organization allows', function () {
    OrganizationSettings::query()->create(['organization_id' => $this->organization->id, 'allow_privileged_compose' => true]);
    $privileged = "services:\n  n8n:\n    image: x:1\n    privileged: true\n";
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id], ['compose_content' => $privileged]))->site;
    $project = Project::query()->where('organization_id', $this->organization->id)->firstOrFail();
    $fork = Environment::query()->forceCreate([
        'organization_id' => $this->organization->id, 'project_id' => $project->id, 'name' => 'PR #1', 'slug' => 'pr-1', 'is_preview' => true, 'is_fork_preview' => true,
    ]);
    Service::query()->where('ref_id', $site->id)->update(['environment_id' => $fork->id]);

    expect(fn () => app(ComposeSites::class)->render($site->id, $privileged, [], '01j9zq4n8v2m6r0t3w5y7b9d1f'))
        ->toThrow(ComposeRenderException::class, 'forks always run under the strict policy');
});

it('renders releases: loopback public ports, labels, built images, no other host ports', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id]))->site;
    $rendered = app(ComposeSites::class)->render($site->id, N8N_COMPOSE, ['postgres' => 'registry.falak.test/falak/n8n/postgres@sha256:'.str_repeat('a', 64)], '01j9zq4n8v2m6r0t3w5y7b9d1f');
    $doc = Yaml::parse($rendered->yaml);

    expect($doc['services']['n8n']['ports'])->toBe(['127.0.0.1:3000:5678'])
        ->and($doc['services']['postgres'])->not->toHaveKey('ports')
        ->and($doc['services']['postgres']['image'])->toStartWith('registry.falak.test/falak/n8n/postgres@sha256:')
        ->and($doc['services']['n8n']['labels'])->toMatchArray([
            'falak.site' => 'n8n', 'falak.release' => '01J9ZQ4N8V2M6R0T3W5Y7B9D1F', 'falak.service' => 'n8n',
            'falak.deploy.leader_command' => "n8n migrate --flag 'two words'",
        ])
        ->and($doc['services']['n8n']['environment']['N8N_ENCRYPTION_KEY'])->toBe('${N8N_ENCRYPTION_KEY}')
        ->and($doc['volumes'])->toHaveKeys(['n8n-data', 'pg-data'])
        ->and($rendered->leaderCommands)->toBe(['n8n' => ['n8n', 'migrate', '--flag', 'two words']])
        ->and($rendered->hostPorts)->toBe(['n8n' => 3000]);

    // `pg-data: {}` stays a mapping.
    expect($rendered->yaml)->not->toContain('pg-data: []');

    $pinned = app(ComposeSites::class)->pinDigests($rendered->yaml, ['n8n' => 'sha256:'.str_repeat('b', 64), 'postgres' => 'not-a-digest']);
    expect(Yaml::parse($pinned)['services']['n8n']['image'])->toBe('n8nio/n8n:1.64.0@sha256:'.str_repeat('b', 64));
});

it('refuses to render builds without images, unknown public services and policy violations', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id]))->site;
    $sites = app(ComposeSites::class);

    expect(fn () => $sites->render($site->id, "services:\n  n8n:\n    build: .\n", [], '01J9ZQ4N8V2M6R0T3W5Y7B9D1F'))->toThrow(ComposeRenderException::class, 'no image was built')
        ->and(fn () => $sites->render($site->id, "services:\n  web:\n    image: x:1\n", [], '01J9ZQ4N8V2M6R0T3W5Y7B9D1F'))->toThrow(ComposeRenderException::class, 'public service n8n is not in the compose file')
        ->and(fn () => $sites->render($site->id, "services:\n  n8n:\n    image: x:1\n    pid: host\n", [], '01J9ZQ4N8V2M6R0T3W5Y7B9D1F'))->toThrow(ComposeRenderException::class, 'compose policy');
});

it('records and reads compose service state', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_input([$this->server->id]))->site;
    app(ComposeSites::class)->recordStatus($site->id, $this->server->id, [
        ['service' => 'n8n', 'container_id' => 'c1', 'state' => 'running', 'health' => 'healthy', 'image' => 'n8nio/n8n:1.64.0', 'restarts' => 0, 'cpu_percent' => 1.5],
        ['service' => 'postgres', 'container_id' => 'c2', 'state' => 'restarting', 'image' => 'postgres:17.2', 'restarts' => 4],
    ]);

    $status = app(ComposeSites::class)->status($site->id);
    expect($status)->toHaveCount(2)
        ->and($status[0]->healthy())->toBeTrue()
        ->and($status[0]->cpuPercent)->toBe(1.5)
        ->and($status[1]->failing())->toBeTrue()
        ->and($status[1]->restarts)->toBe(4);
});
