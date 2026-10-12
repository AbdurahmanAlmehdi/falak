<?php

use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Infrastructure\Config\EdgeReconciler;
use Falak\Identity\Contracts\Role;
use Falak\Limits\Infrastructure\Config\LimitsReconciler;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Infrastructure\Config\SitesReconciler;

require_once __DIR__.'/../../Support/config.php';

/*
| falak.yaml's compose units: the stack's files, profiles and services (public port and health path, a database unit
| replacing a service), per-service limits and routes.
*/

const CONFIG_STACK = <<<'YAML'
services:
  web:
    image: nginx:1.27-alpine
  api:
    image: ghcr.io/acme/api:1
  db:
    image: postgres:17.2
  mailpit:
    image: axllent/mailpit
    profiles: [dev]
YAML;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->git->files = ['stack/compose.yaml' => CONFIG_STACK];
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    sites_fake_agent_memory([$this->server->id => 8 * 1024 ** 3]);
    $this->env = projects_default_env($this->organization);
    // The server is attached to the project through a site it hosts.
    projects_site($this->organization, 'anchor', [], $this->env, [$this->server]);
    $this->source = config_source($this->organization, $this->env, connectionId: $this->git->addConnection($this->organization->id)->id);
    $this->sites = app(SitesReconciler::class);
    $this->resolved = config_doc('production', [], ['branch' => 'main', 'servers' => ['web-1']]);
    $this->scope = fn () => config_scope($this->env, $this->source, $this->resolved);
    $this->apply = function (object $reconciler, array $desired) {
        $scope = ($this->scope)();
        $plan = $reconciler->diff($scope, $desired, $reconciler->current($scope));
        $reconciler->apply($scope, $plan, config_ctx($this->env));

        return $plan;
    };
});

/**
 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
 */
function compose_changes(ChangeSet $set): array
{
    $out = [];

    foreach ($set->changes as $change) {
        $out[$change->path] = [$change->op->value, $change->before, $change->after];
    }

    return $out;
}

/** The compose unit of the tests, pruned to the Sites sections. */
function compose_unit(array $services = ['web' => ['port' => 80, 'health_path' => '/up']], array $extra = []): array
{
    return ['type' => 'compose', 'root' => 'stack', 'files' => ['compose.yaml'], 'profiles' => ['dev'], 'services' => $services, ...$extra];
}

it('creates a compose unit from the repository, then reads it back unchanged', function () {
    $desired = ['units' => ['shop' => compose_unit(extra: ['env' => ['APP_ENV' => 'production']])]];
    $plan = ($this->apply)($this->sites, $desired);

    expect(compose_changes($plan)['/units/shop'][0])->toBe('create');

    $site = Site::query()->where('name', 'shop')->sole();

    expect($site->runtime->value)->toBe('compose')
        ->and($site->root_directory)->toBe('stack')
        ->and($site->compose_files)->toBe(['compose.yaml'])
        ->and($site->compose_profiles)->toBe(['dev'])
        ->and($site->repository)->toBe('acme/shop')
        ->and($site->public_services)->toHaveCount(1)
        ->and($site->public_services[0])->toMatchArray(['service' => 'web', 'port' => 80, 'health_check_path' => '/up'])
        ->and($site->compose_adjustments)->toBeNull();

    $scope = ($this->scope)();
    $current = $this->sites->current($scope);

    expect($current['units']['shop'])->toMatchArray(['type' => 'compose', 'root' => 'stack', 'files' => ['compose.yaml'], 'profiles' => ['dev'],
        'services' => ['web' => ['port' => 80, 'health_path' => '/up']]])
        ->and(collect($this->sites->diff($scope, $desired, $current)->actionable())->all())->toBe([]);
});

it('plans and applies service changes: ports, health paths, new public services last', function () {
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $before = $site->public_services[0]['host_port'];

    $desired = ['units' => ['shop' => compose_unit(['api' => ['port' => 8080, 'health_path' => '/health'], 'web' => ['port' => 8000]])]];
    $scope = ($this->scope)();
    $changes = compose_changes($this->sites->diff($scope, $desired, $this->sites->current($scope)));

    expect($changes)->toMatchArray([
        '/units/shop/services/web/port' => ['update', 80, 8000],
        '/units/shop/services/api/port' => ['create', null, 8080],
        '/units/shop/services/api/health_path' => ['create', null, '/health'],
        '/units/shop/files' => ['noop', ['compose.yaml'], ['compose.yaml']],
    ]);

    foreach ($this->sites->diff($scope, $desired, [])->changes as $change) {
        expect($change->section)->toBeIn($this->sites->sections());
    }

    ($this->apply)($this->sites, $desired);
    $public = $site->refresh()->public_services;

    // web keeps its place (first: the unit's own route), its host port and its health path (absent: kept).
    expect(array_column($public, 'service'))->toBe(['web', 'api'])
        ->and($public[0])->toMatchArray(['port' => 8000, 'host_port' => $before, 'health_check_path' => '/up'])
        ->and($public[1])->toMatchArray(['port' => 8080, 'health_check_path' => '/health'])
        ->and(ConfigOwnership::query()->where('resource_id', $site->id)->where('path', 'like', '/services/%')->orderBy('path')->pluck('path')->all())
        ->toBe(['/services/api/health_path', '/services/api/port', '/services/web/port']);
});

it('runs a service as a database unit of the environment', function () {
    [$database, , $instance] = projects_database($this->organization, 'db', $this->env, server: $this->server);
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);
    $site = Site::query()->where('name', 'shop')->sole();

    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit(['web' => ['port' => 80], 'db' => ['replace_with' => 'db']])]]);

    expect($site->refresh()->compose_services['db'])->toMatchArray(['mode' => 'database', 'database_id' => $database->id]);

    $scope = ($this->scope)();
    expect($this->sites->current($scope)['units']['shop']['services']['db'])->toBe(['replace_with' => 'db']);
});

it('never takes host binds from the file: keep_binds fails the schema', function () {
    $check = fn (string $yaml) => app(FileCheck::class)->check($yaml, 'yaml');
    $base = "version: 1\nunits:\n  shop:\n    type: compose\n";

    $unit = $check($base."    keep_binds: [\"web:./data\"]\n");
    $service = $check($base."    services:\n      web: { port: 80, keep_binds: [\"./data\"] }\n");
    $adjustments = $check($base."    compose_adjustments: { keep_binds: [\"web:./data\"] }\n");

    foreach ([$unit, $service, $adjustments] as $validation) {
        expect($validation->valid())->toBeFalse()
            ->and($validation->errors[0]->code)->toBe('schema');
    }

    // A stack with binds an admin kept keeps them through file changes.
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $site->forceFill(['compose_adjustments' => ['keep_binds' => ['web:./data']]])->save();
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit(['web' => ['port' => 81]])]]);

    expect($site->refresh()->compose_adjustments)->toBe(['keep_binds' => ['web:./data']]);
});

it('validates compose services', function () {
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);

    $issues = $this->sites->validate(($this->scope)(), config_doc('production', [
        'shop' => compose_unit([
            'web' => ['port' => 80],
            'api' => ['health_path' => '/up', 'domains' => ['api.example.com']],
            'db' => ['replace_with' => 'web'],
        ], ['deploy' => ['hooks' => ['before_activate' => 'echo']]]),
        'web' => ['type' => 'app', 'runtime' => 'static'],
        'other' => compose_unit(['cache' => ['port' => 6379, 'replace_with' => 'redis']]),
        'redis' => ['type' => 'redis'],
    ]));

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'value.unsupported /units/shop/deploy/hooks',
        'value.conflict /units/shop/services/api/health_path',
        'value.conflict /units/shop/services/api/domains',
        'name.unit /units/shop/services/db/replace_with',
        'value.conflict /units/other/services/cache/replace_with',
    ]);

    // A unit's type never changes under it.
    $changed = $this->sites->validate(($this->scope)(), config_doc('production', ['shop' => ['type' => 'app', 'runtime' => 'static']]));
    expect($changed[0]->code.' '.$changed[0]->pointer)->toBe('value.conflict /units/shop/type');
});

it('sets the limits of compose services, keeping what the file doesn\'t set', function () {
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $site->forceFill(['compose_limits' => ['*' => ['memory_limit' => 256], 'web' => ['memory_limit' => 512, 'oom' => 'protect']]])->save();
    $limits = app(LimitsReconciler::class);

    $scope = ($this->scope)();
    expect($limits->current($scope)['units']['shop']['services'])->toBe(['web' => ['limits' => ['memory' => '512m', 'oom' => 'protect']]]);

    $desired = ['units' => ['shop' => ['type' => 'compose', 'services' => ['web' => ['limits' => ['memory' => '1g']], 'api' => ['limits' => ['cpus' => 0.5]]]]]];
    expect(compose_changes($limits->diff($scope, $desired, $limits->current($scope))))->toBe([
        '/units/shop/services/web/limits/memory' => ['update', '512m', '1g'],
        '/units/shop/services/api/limits/cpus' => ['create', null, 0.5],
    ]);

    $this->git->files['stack/compose.yaml'] = CONFIG_STACK;
    $site->forceFill(['compose_snapshot' => CONFIG_STACK])->save();
    ($this->apply)($limits, $desired);

    expect($site->refresh()->compose_limits)->toMatchArray([
        '*' => ['memory_limit' => 256],
        'web' => ['memory_limit' => 1024, 'oom' => 'protect'],
        'api' => ['cpus' => 0.5],
    ])->and(ConfigOwnership::query()->where('resource_id', $site->id)->where('path', 'like', '/services/%/limits%')->orderBy('path')->pluck('path')->all())
        ->toBe(['/services/api/limits/cpus', '/services/web/limits/memory']);

    $issues = $limits->validate($scope, config_doc('production', ['shop' => ['type' => 'compose', 'services' => ['web' => ['limits' => ['memory' => '8m']]]]]));
    expect($issues[0]->code.' '.$issues[0]->pointer)->toBe('value.range /units/shop/services/web/limits/memory');
});

it('routes the unit\'s domains to its first public service and a service\'s domains to that service', function () {
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit(['web' => ['port' => 80], 'api' => ['port' => 8080]])]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $edge = app(EdgeReconciler::class);

    $desired = ['units' => ['shop' => ['type' => 'compose', 'domains' => ['shop.example.com'], 'services' => [
        'api' => ['domains' => ['api.example.com'], 'headers' => ['X-Api' => '1']],
    ]]]];
    $plan = ($this->apply)($edge, $desired);

    expect(collect($plan->changes)->pluck('path')->all())->toContain('/units/shop/domains/shop.example.com', '/units/shop/services/api/domains/api.example.com', '/units/shop/services/api/headers/X-Api')
        ->and(Domain::query()->where('site_id', $site->id)->pluck('compose_service', 'name')->all())->toBe(['shop.example.com' => null, 'api.example.com' => 'api']);

    $scope = ($this->scope)();
    $current = $edge->current($scope)['units']['shop'];

    expect($current['domains'])->toBe(['shop.example.com'])
        ->and($current['services'])->toBe(['api' => ['domains' => ['api.example.com'], 'headers' => ['X-Api' => '1']]])
        ->and($edge->diff($scope, $desired, $edge->current($scope))->actionable())->toBe([])
        // `services.<first>` is the unit's own route.
        ->and($edge->diff($scope, ['units' => ['shop' => ['type' => 'compose', 'services' => ['web' => ['domains' => ['shop.example.com']], 'api' => ['domains' => ['api.example.com'], 'headers' => ['X-Api' => '1']]]]]], $edge->current($scope))->actionable())->toBe([]);
});

it('refuses dashboard edits of compose fields the file manages', function () {
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit()]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $public = [['service' => 'web', 'port' => 80, 'health_check_path' => '/up']];
    $body = ['compose_source' => 'repo', 'compose_files' => ['compose.yaml'], 'compose_profiles' => ['dev'], 'public_services' => $public];

    // The panel shows them read-only.
    expect($this->getJson("/sites/{$site->id}/compose")->assertOk()->json('data.managed.paths'))->toContain('/files', '/services/web/port');

    $this->putJson("/sites/{$site->id}/compose", [...$body, 'compose_files' => ['other.yaml']])->assertStatus(422)->assertJsonValidationErrors('compose_files');
    $this->putJson("/sites/{$site->id}/compose", [...$body, 'public_services' => [['service' => 'web', 'port' => 81, 'health_check_path' => '/up']]])
        ->assertStatus(422)->assertJsonValidationErrors('public_services.web.port');
    $this->putJson("/sites/{$site->id}/compose", [...$body, 'compose_source' => 'inline', 'compose_content' => "services: {}\n"])->assertStatus(422);

    // The managed values unchanged, another service made public: accepted.
    $this->putJson("/sites/{$site->id}/compose", [...$body, 'public_services' => [...$public, ['service' => 'api', 'port' => 8080]]])->assertOk();
});

it('lets the form save with a managed replaced service unchanged, and guards restore, not viewing versions', function () {
    [$database] = projects_database($this->organization, 'db', $this->env, server: $this->server);
    ($this->apply)($this->sites, ['units' => ['shop' => compose_unit(['web' => ['port' => 80, 'health_path' => '/up'], 'db' => ['replace_with' => 'db']])]]);
    $site = Site::query()->where('name', 'shop')->sole();
    $body = ['compose_source' => 'repo', 'compose_files' => ['compose.yaml'], 'compose_profiles' => ['dev'],
        'public_services' => [['service' => 'web', 'port' => 80, 'health_check_path' => '/up']]];

    $this->putJson("/sites/{$site->id}/compose", [...$body, 'compose_services' => ['db' => ['mode' => 'database', 'database_id' => $database->id], 'mailpit' => ['mode' => 'keep']]])->assertOk();
    $this->putJson("/sites/{$site->id}/compose", [...$body, 'compose_services' => ['db' => ['mode' => 'keep']]])->assertStatus(422)->assertJsonValidationErrors('compose_services.db');

    ComposeVersion::query()->create(['site_id' => $site->id, 'version' => 1, 'content' => "services: {}\n", 'created_at' => now()]);
    $this->getJson("/sites/{$site->id}/compose/versions/1")->assertOk();
    $this->postJson("/sites/{$site->id}/compose/versions/1/restore")->assertStatus(422);
});
