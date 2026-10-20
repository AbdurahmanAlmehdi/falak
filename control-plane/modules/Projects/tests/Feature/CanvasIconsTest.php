<?php

use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Group;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->environment = projects_default_env($this->organization);
    $this->base = "/projects/{$this->environment->project_id}/production";
});

it('sets a service icon picked on the canvas, and clearing it brings back the derived one', function () {
    $service = projects_service('site', projects_site($this->organization, 'Shop', [], $this->environment)->id);
    $derived = $this->getJson("{$this->base}/canvas")->json('services.0.icon');

    $this->patchJson("{$this->base}/services/{$service->id}", ['icon' => 'docker/svgl-dark'])
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $service->id, 'name' => 'Shop', 'icon' => 'docker/svgl-dark']]);
    $this->getJson("{$this->base}/canvas")
        ->assertJsonPath('services.0.icon', 'docker/svgl-dark')
        ->assertJsonPath('services.0.custom_icon', 'docker/svgl-dark')
        ->assertJsonPath('services.0.name', 'Shop');

    $this->patchJson("{$this->base}/services/{$service->id}", ['icon' => null])->assertOk()->assertJsonPath('data.icon', null);
    $this->getJson("{$this->base}/canvas")->assertJsonPath('services.0.icon', $derived)->assertJsonPath('services.0.custom_icon', null);
});

it('overrides a database engine icon and shows picks on the Projects dashboard', function () {
    [$database] = projects_database($this->organization, 'db', $this->environment);
    $service = projects_service('database', $database->id);

    $this->patchJson("{$this->base}/services/{$service->id}", ['icon' => 'supabase'])->assertOk();

    $this->getJson("{$this->base}/canvas")->assertJsonPath('services.0.icon', 'supabase');
    $this->get('/projects')->assertInertia(fn ($page) => $page->where('projects.0.services.0.icon', 'supabase')->etc());
});

it('rejects icons that are not Cool Icons keys and still needs a name or an icon', function () {
    $service = projects_service('site', projects_site($this->organization, 'Shop', [], $this->environment)->id);

    foreach (['Docker', '../etc/passwd', 'docker/', 'a/b/c', str_repeat('a', 65), 'https://evil.test/x.svg'] as $icon) {
        $this->patchJson("{$this->base}/services/{$service->id}", ['icon' => $icon])->assertUnprocessable()->assertJsonValidationErrors('icon');
    }

    $this->patchJson("{$this->base}/services/{$service->id}", [])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($service->refresh()->icon)->toBeNull();
});

it('sets and clears a group icon', function () {
    $service = projects_service('site', projects_site($this->organization, 'Web', [], $this->environment)->id);
    $groupId = $this->postJson("{$this->base}/groups", ['name' => 'Commerce', 'service_ids' => [$service->id]])->assertCreated()->assertJsonPath('data.icon', null)->json('data.id');

    $this->patchJson("{$this->base}/groups/{$groupId}", ['icon' => 'stripe'])->assertOk()->assertJsonPath('data.icon', 'stripe');
    $this->getJson("{$this->base}/canvas")->assertJsonPath('groups.0.icon', 'stripe');

    $this->patchJson("{$this->base}/groups/{$groupId}", ['icon' => 'Not An Icon'])->assertUnprocessable()->assertJsonValidationErrors('icon');
    $this->patchJson("{$this->base}/groups/{$groupId}", ['icon' => ''])->assertOk();
    expect(Group::query()->find($groupId)?->icon)->toBeNull();
});

it('takes a Cool Icons key with a variant as the project icon', function () {
    $this->patchJson("/projects/{$this->environment->project_id}", ['icon' => 'kubernetes/cncf'])->assertOk()->assertJsonPath('data.icon', 'kubernetes/cncf');
    $this->patchJson("/projects/{$this->environment->project_id}", ['icon' => 'kubernetes/CNCF'])->assertUnprocessable()->assertJsonValidationErrors('icon');
});

it('sets a service icon through the API with a token', function () {
    $service = projects_service('site', projects_site($this->organization, 'Shop', [], $this->environment)->id);
    $token = $this->user->createToken('cli', ['*']);
    $token->accessToken->forceFill(['organization_id' => $this->organization->id])->save();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->patchJson("/api/v1/projects/{$this->environment->project_id}/environments/production/services/{$service->id}", ['icon' => 'nextjs'])
        ->assertOk()->assertJsonPath('data.icon', 'nextjs');

    [, $stranger] = memberOf();
    $other = projects_default_env($stranger);
    $this->withToken($token->plainTextToken)
        ->patchJson("/api/v1/projects/{$other->project_id}/environments/production/services/{$service->id}", ['icon' => 'nextjs'])
        ->assertNotFound();
});

it('shares the icon library URL, and none when it is turned off', function () {
    $this->get('/projects')->assertInertia(fn ($page) => $page->where('icons_url', 'https://coolicon.falak.sh')->etc());

    config(['kernel.icons_url' => '']);
    $this->get('/projects')->assertInertia(fn ($page) => $page->where('icons_url', null)->etc());
});
