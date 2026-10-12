<?php

use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

/** Detach the world's site as a run whose file no longer has the unit would. */
function config_detach_site(ConfigWorld $world): ConfigDetached
{
    $scope = new ConfigScope($world->deploy->organization->id, $world->environment->project_id, $world->environment->id, $world->environment->slug);
    $site = $world->deploy->site;
    app(ManagedResources::class)->detach($scope, new ApplyContext('01jrun0000000000000000000a', ApplyPhase::BeforeBuild), 'web', 'site', $site->id,
        "Site {$site->name}", route('sites.destroy', ['site' => $site->id], false), $site->id, ['name' => $site->name]);

    return ConfigDetached::query()->sole();
}

it('lists unmanaged resources: the API without, the dashboard with the module delete', function () {
    $world = config_world(actingAs: false);
    $row = config_detach_site($world);
    $project = $world->environment->project_id;
    $token = app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['config.view'])->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/projects/{$project}/config/unmanaged")->assertStatus(422)->assertJsonValidationErrors('environment');
    $this->withToken($token)->getJson("/api/v1/projects/{$project}/config/unmanaged?environment=production")->assertOk()
        ->assertJsonPath('data.0', ['id' => $row->id, 'environment' => 'production', 'unit' => 'web', 'resource_type' => 'site', 'resource_id' => $world->deploy->site->id,
            'label' => 'Site Shop', 'reason' => 'removed_from_file', 'run_id' => '01jrun0000000000000000000a', 'detached_at' => $row->detached_at->toIso8601String()]);

    app('auth')->forgetGuards();
    $this->actingAs($world->deploy->user)->getJson("/projects/{$project}/settings/config/unmanaged")->assertOk()
        ->assertJsonPath('data.0.delete_url', "/sites/{$world->deploy->site->id}")
        ->assertJsonPath('data.0.delete_body', ['name' => 'Shop']);
});

it('deleting through the module route takes the resource off the list; the file managing it again too', function () {
    $world = config_world();
    $row = config_detach_site($world);

    $this->deleteJson((string) $row->delete_url, (array) $row->delete_body)->assertNoContent();

    expect(Site::query()->find($world->deploy->site->id))->toBeNull()
        ->and(ConfigDetached::query()->count())->toBe(0);

    $other = config_world();
    config_detach_site($other);
    $scope = new ConfigScope($other->deploy->organization->id, $other->environment->project_id, $other->environment->id, $other->environment->slug);
    app(ManagedResources::class)->record($scope, new ApplyContext('01jrun0000000000000000000b', ApplyPhase::BeforeBuild), 'web', 'site', $other->deploy->site->id, ['/runtime'], merge: true);

    expect(ConfigDetached::query()->count())->toBe(0);
});

it('the module delete keeps its own permissions; dismissing needs config.apply', function () {
    $world = config_world(role: Role::Developer);
    $row = config_detach_site($world);

    // Deleting a site is Admin-only (sites.delete): a developer can't, through this list either.
    $this->deleteJson((string) $row->delete_url, (array) $row->delete_body)->assertForbidden();
    expect(Site::query()->find($world->deploy->site->id))->not->toBeNull();

    [$viewer] = memberOf($world->deploy->organization, Role::Viewer);
    $this->actingAs($viewer)->deleteJson("/config/unmanaged/{$row->id}")->assertForbidden();
    $this->actingAs($world->deploy->user)->deleteJson("/config/unmanaged/{$row->id}")->assertNoContent();

    expect(ConfigDetached::query()->count())->toBe(0);
});

it('rows of other organizations stay hidden', function () {
    $world = config_world();
    $row = config_detach_site($world);
    config_world();

    $this->deleteJson("/config/unmanaged/{$row->id}")->assertNotFound();
});
