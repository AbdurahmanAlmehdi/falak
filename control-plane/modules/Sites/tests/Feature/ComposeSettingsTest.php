<?php

use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\ComposeState;
use Falak\Sites\Domain\Models\OrganizationSettings;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteUpdated;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

const STACK = "services:\n  web:\n    image: nginx:1.27\n    ports: ['80:80']\n  api:\n    image: ghcr.io/acme/api:2\n    expose: ['9000']\n";

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'falak.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
    $this->site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'stack', 'runtime' => 'compose', 'server_ids' => [$this->server->id], 'compose_source' => 'inline', 'compose_content' => STACK,
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->site;
    SiteTarget::query()->where('site_id', $this->site->id)->update(['status' => 'ready']);
    $this->json = ['Accept' => 'application/json'];
});

it('shows compose settings with the parsed summary, history and policy', function () {
    $this->getJson("/sites/{$this->site->id}/compose")->assertOk()
        ->assertJsonPath('data.source', 'inline')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.summary.services.1.name', 'api')
        ->assertJsonPath('data.summary.services.1.ports', [9000])
        ->assertJsonPath('data.public_services.0.url', 'https://stack.falak.test')
        ->assertJsonPath('data.policy.allow_privileged', false)
        ->assertJsonPath('data.can.update', true);
});

it('validates compose content without saving', function () {
    $this->postJson("/sites/{$this->site->id}/compose/validate", ['content' => "services:\n  x:\n    image: a\n    privileged: true\n"])->assertOk()
        ->assertJsonPath('data.violations', ['Service x runs privileged.'])
        ->assertJsonPath('data.allow_privileged', false);
    $this->postJson("/sites/{$this->site->id}/compose/validate", ['content' => "services:\n  x:\n    build: .\n"])->assertOk()
        ->assertJsonPath('data.errors.0', 'Inline compose files cannot use `build:` (x).');
});

it('saves inline versions, public services with allocated ports, and restores history', function () {
    Event::fake([SiteUpdated::class]);

    $this->putJson("/sites/{$this->site->id}/compose", [
        'compose_source' => 'inline',
        'compose_content' => STACK."  worker:\n    image: ghcr.io/acme/api:2\n",
        'public_services' => [['service' => 'web', 'port' => 80], ['service' => 'api', 'port' => 9000, 'domain' => 'API.example.com']],
        'base_version' => 1,
    ])->assertOk()->assertJsonPath('data.version', 2);

    $site = Site::query()->findOrFail($this->site->id);
    expect($site->public_services)->toBe([
        ['service' => 'web', 'port' => 80, 'domain' => null, 'host_port' => 3000],
        ['service' => 'api', 'port' => 9000, 'domain' => 'api.example.com', 'host_port' => 3001],
    ])->and($site->app_port)->toBe(3000)
        ->and($site->publicService('api')->testDomain)->toBe('api-stack.falak.test');
    Event::assertDispatched(SiteUpdated::class, fn ($e) => in_array('public_services', $e->changed, true) && in_array('compose_content', $e->changed, true));

    // Stale editor.
    $this->putJson("/sites/{$this->site->id}/compose", ['compose_source' => 'inline', 'compose_content' => STACK, 'base_version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('compose_content');

    // Policy + unknown service.
    $this->putJson("/sites/{$this->site->id}/compose", ['compose_source' => 'inline', 'compose_content' => "services:\n  web:\n    image: x\n    network_mode: host\n"])
        ->assertUnprocessable()->assertJsonValidationErrors('compose_content');
    $this->putJson("/sites/{$this->site->id}/compose", ['compose_source' => 'inline', 'compose_content' => "services:\n  other:\n    image: x\n"])
        ->assertUnprocessable()->assertJsonValidationErrors('public_services.0.service');

    $this->getJson("/sites/{$this->site->id}/compose/versions/1")->assertOk()->assertJsonPath('data.content', STACK);
    $this->postJson("/sites/{$this->site->id}/compose/versions/1/restore")->assertOk()->assertJsonPath('data.version', 3);
    $this->getJson("/sites/{$this->site->id}/compose")->assertJsonPath('data.content', STACK)->assertJsonCount(3, 'data.versions');
});

it('switches to a repository source', function () {
    $this->putJson("/sites/{$this->site->id}/compose", ['compose_source' => 'repo', 'compose_file' => 'deploy/compose.yml', 'public_services' => [['service' => 'app', 'port' => 8080]]])->assertOk();

    $site = Site::query()->findOrFail($this->site->id);
    expect($site->compose_source->value)->toBe('repo')->and($site->compose_file)->toBe('deploy/compose.yml')
        ->and($site->public_services[0]['host_port'])->toBe(3000);
});

it('only lets site managers change compose settings', function () {
    actingAsMember(Role::Viewer, $this->organization);
    $this->getJson("/sites/{$this->site->id}/compose")->assertOk()->assertJsonPath('data.can.update', false);
    $this->putJson("/sites/{$this->site->id}/compose", ['compose_source' => 'inline', 'compose_content' => STACK])->assertForbidden();
    $this->postJson("/sites/{$this->site->id}/compose/restart")->assertForbidden();
});

it('refreshes service state with docker.compose.ps and restarts services', function () {
    $this->getJson("/sites/{$this->site->id}/compose/services")->assertOk()->assertJsonPath('data.services', [])->assertJsonPath('data.servers.0.refreshing', true);
    $ps = $this->agents->last('docker.compose.ps');
    expect($ps['payload'])->toBe(['project' => 'stack', 'stats' => true]);

    // A second poll inside the refresh window does not dispatch again.
    $this->getJson("/sites/{$this->site->id}/compose/services")->assertOk();
    expect($this->agents->ofType('docker.compose.ps'))->toHaveCount(1);

    CommandFinished::dispatch($ps['id'], $this->organization->id, $this->server->id, 'docker.compose.ps', 'k', 0, ['services' => [
        ['service' => 'web', 'container_id' => 'c1', 'container_name' => 'stack-web-1', 'state' => 'running', 'health' => 'healthy', 'image' => 'nginx:1.27', 'image_digest' => 'sha256:'.str_repeat('a', 64), 'restarts' => 1, 'cpu_percent' => 2.5, 'memory_bytes' => 1048576,
            'ports' => [['host_ip' => '127.0.0.1', 'host_port' => 3000, 'container_port' => 80, 'protocol' => 'tcp']]],
    ]]);

    $this->getJson("/sites/{$this->site->id}/compose/services")->assertOk()
        ->assertJsonPath('data.services.0.service', 'web')
        ->assertJsonPath('data.services.0.server_name', 'app-1')
        ->assertJsonPath('data.services.0.cpu_percent', 2.5)
        ->assertJsonPath('data.services.0.public.url', 'https://stack.falak.test')
        ->assertJsonPath('data.servers.0.refreshing', false);

    $this->postJson("/sites/{$this->site->id}/compose/restart", ['service' => 'web'])->assertStatus(202);
    expect($this->agents->last('docker.compose.restart')['payload'])->toBe(['project' => 'stack', 'services' => ['web']])
        ->and(ComposeState::query()->first()->requested_at)->toBeNull();
});

it('manages the organization compose policy', function () {
    $this->get('/settings/compose')->assertOk()->assertInertia(fn ($page) => $page->component('Sites/ComposePolicy', false)->where('policy.allow_privileged', false)->where('can.manage', true)->where('compose_sites', 1));
    $this->put('/settings/compose', ['allow_privileged' => true])->assertRedirect();
    expect(OrganizationSettings::for($this->organization->id)->allow_privileged_compose)->toBeTrue();

    actingAsMember(Role::Developer, $this->organization);
    $this->put('/settings/compose', ['allow_privileged' => false])->assertForbidden();
});

it('locks the compose policy on a managed install', function () {
    OrganizationSettings::query()->create(['organization_id' => $this->organization->id, 'allow_privileged_compose' => true]);
    config(['controlplane.managed' => true]);

    $this->get('/settings/compose')->assertInertia(fn ($page) => $page->where('policy.allow_privileged', false)->where('policy.locked', true));
    $this->put('/settings/compose', ['allow_privileged' => true])->assertSessionHasErrors('allow_privileged');
    $this->put('/settings/compose', ['allow_privileged' => false])->assertSessionHasNoErrors();
    expect(OrganizationSettings::for($this->organization->id)->allow_privileged_compose)->toBeFalse();
});
