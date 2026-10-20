<?php

use Falak\Identity\Contracts\Role;
use Falak\Projects\Application\Actions\UnlinkService;
use Falak\Projects\Application\Canvas\ComposeGroup;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Domain\Models\ComposeVersion;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->environment = projects_default_env($this->organization);
    $this->base = "/projects/{$this->environment->project_id}/production";
});

function projects_place(Service $service, int $x, int $y): Service
{
    $service->forceFill(['x' => $x, 'y' => $y])->save();

    return $service;
}

it('groups services around their top-left without moving them on screen, and ungroups them back', function () {
    $web = projects_place(projects_service('site', projects_site($this->organization, 'Web', [], $this->environment)->id), 400, 200);
    [$database] = projects_database($this->organization, 'db', $this->environment);
    $db = projects_place(projects_service('database', $database->id), 100, 500);

    $response = $this->postJson("{$this->base}/groups", ['name' => 'Commerce', 'service_ids' => [$web->id, $db->id]])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Commerce')
        ->assertJsonPath('data.position', ['x' => 100, 'y' => 200])
        ->assertJsonPath('data.collapsed', false);
    $groupId = $response->json('data.id');

    expect($web->refresh()->only(['group_id', 'x', 'y']))->toBe(['group_id' => $groupId, 'x' => 300, 'y' => 0])
        ->and($db->refresh()->only(['group_id', 'x', 'y']))->toBe(['group_id' => $groupId, 'x' => 0, 'y' => 300]);

    $canvas = $this->getJson("{$this->base}/canvas")->assertOk();
    expect($canvas->json('groups'))->toBe([['id' => $groupId, 'name' => 'Commerce', 'icon' => null, 'position' => ['x' => 100, 'y' => 200], 'collapsed' => false]])
        ->and(collect($canvas->json('services'))->pluck('group_id')->unique()->all())->toBe([$groupId]);

    // Moving / renaming / collapsing the group is one row; members keep their relative positions.
    $this->patchJson("{$this->base}/groups/{$groupId}", ['x' => 140, 'y' => 260, 'name' => ' Shop ', 'collapsed' => true])
        ->assertOk()->assertJsonPath('data', ['id' => $groupId, 'name' => 'Shop', 'icon' => null, 'position' => ['x' => 140, 'y' => 260], 'collapsed' => true]);
    expect($web->refresh()->only(['x', 'y']))->toBe(['x' => 300, 'y' => 0]);

    $this->deleteJson("{$this->base}/groups/{$groupId}")->assertNoContent();
    expect(Group::query()->count())->toBe(0)
        ->and($web->refresh()->only(['group_id', 'x', 'y']))->toBe(['group_id' => null, 'x' => 440, 'y' => 260])
        ->and($db->refresh()->only(['group_id', 'x', 'y']))->toBe(['group_id' => null, 'x' => 140, 'y' => 560]);
});

it('moves cards into and out of groups and drops a group with its last card', function () {
    $a = projects_place(projects_service('site', projects_site($this->organization, 'A', [], $this->environment)->id), 0, 0);
    $b = projects_place(projects_service('site', projects_site($this->organization, 'B', [], $this->environment)->id), 600, 0);
    $group = Group::query()->findOrFail($this->postJson("{$this->base}/groups", ['service_ids' => [$a->id]])->assertCreated()->json('data.id'));
    expect($group->name)->toBe('Group');

    $this->patchJson("{$this->base}/services/{$b->id}/position", ['x' => 20, 'y' => 180, 'group_id' => $group->id])
        ->assertOk()->assertJsonPath('data', ['id' => $b->id, 'position' => ['x' => 20, 'y' => 180], 'group_id' => $group->id]);

    // Plain moves keep membership.
    $this->patchJson("{$this->base}/services/{$b->id}/position", ['x' => 40, 'y' => 200])->assertOk()->assertJsonPath('data.group_id', $group->id);

    $this->patchJson("{$this->base}/services/{$a->id}/position", ['x' => -300, 'y' => 0, 'group_id' => null])->assertOk()->assertJsonPath('data.group_id', null);
    expect(Group::query()->whereKey($group->id)->exists())->toBeTrue();

    $this->patchJson("{$this->base}/services/{$b->id}/position", ['x' => 900, 'y' => 0, 'group_id' => null])->assertOk();
    expect(Group::query()->whereKey($group->id)->exists())->toBeFalse();

    $this->patchJson("{$this->base}/services/{$b->id}/position", ['x' => 0, 'y' => 0, 'group_id' => $group->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['group_id' => 'That group does not exist.']);
});

it('rejects services of other environments and compose sites', function () {
    $staging = projects_environment($this->organization, 'staging');
    $other = projects_service('site', projects_site($this->organization, 'Elsewhere', [], $staging)->id);
    $compose = projects_service('site', projects_site($this->organization, 'Stack', [], $this->environment, [], [
        'framework' => 'docker', 'runtime' => 'compose', 'build_mode' => 'docker', 'php_version' => null, 'compose_source' => 'inline',
    ])->id);

    $this->postJson("{$this->base}/groups", ['service_ids' => [$other->id]])->assertUnprocessable()->assertJsonValidationErrors('service_ids');
    $this->postJson("{$this->base}/groups", ['service_ids' => [$compose->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['service_ids' => 'Compose services are already grouped by their site.']);
    $this->postJson("{$this->base}/groups", ['service_ids' => []])->assertUnprocessable();
});

it('removes a group whose last service is deleted', function () {
    $site = projects_site($this->organization, 'Solo', [], $this->environment);
    $groupId = $this->postJson("{$this->base}/groups", ['service_ids' => [projects_service('site', $site->id)->id]])->json('data.id');

    app(UnlinkService::class)(ServiceKind::Site, $site->id);

    expect(Group::query()->whereKey($groupId)->exists())->toBeFalse();
});

it('lets viewers read groups but not change them', function () {
    $site = projects_site($this->organization, 'Web', [], $this->environment);
    $groupId = $this->postJson("{$this->base}/groups", ['service_ids' => [projects_service('site', $site->id)->id]])->json('data.id');

    actingAsMember(Role::Viewer, $this->organization);

    $this->getJson("{$this->base}/canvas")->assertOk()->assertJsonCount(1, 'groups');
    $this->postJson("{$this->base}/groups", ['service_ids' => [projects_service('site', $site->id)->id]])->assertForbidden();
    $this->patchJson("{$this->base}/groups/{$groupId}", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("{$this->base}/groups/{$groupId}")->assertForbidden();
});

it('draws compose sites as a group of their compose services with volumes, status and depends_on edges', function () {
    $web = sites_server($this->organization->id, ['name' => 'web-1'], docker: true);
    $site = projects_site($this->organization, 'Automations', [], $this->environment, [$web], [
        'framework' => 'docker', 'runtime' => 'compose', 'build_mode' => 'docker', 'php_version' => null, 'compose_source' => 'inline',
        'public_services' => [['service' => 'app', 'port' => 5678, 'domain' => 'flows.example.com', 'host_port' => 3200]],
    ]);
    ComposeVersion::query()->create(['site_id' => $site->id, 'version' => 1, 'created_at' => now(), 'content' => <<<'YAML'
        services:
          app:
            image: n8nio/n8n:1.64.0
            depends_on: [db, cache]
            volumes: [app-data:/data]
          db:
            image: postgres:17-alpine
            volumes: [pg-data:/var/lib/postgresql/data]
          cache:
            image: redis:7
        volumes:
          app-data:
          pg-data:
        YAML]);
    projects_deployment($site, 'succeeded', ['finished_at' => now()->subMinute()]);
    app(ComposeSites::class)->recordStatus($site->id, $web->id, [
        ['service' => 'app', 'state' => 'running', 'health' => 'healthy', 'image' => 'n8nio/n8n:1.64.0', 'restarts' => 0],
        ['service' => 'db', 'state' => 'running', 'image' => 'postgres:17-alpine', 'restarts' => 0],
        ['service' => 'cache', 'state' => 'restarting', 'image' => 'redis:7', 'restarts' => 4],
    ]);
    $service = projects_service('site', $site->id);

    $canvas = $this->getJson("{$this->base}/canvas")->assertOk();

    expect($canvas->json('services.0.volumes'))->toBe([])
        ->and($canvas->json('services.0.compose'))->toBe([
            'template' => null,
            'collapsed' => false,
            'services' => [
                ['name' => 'app', 'icon' => 'n8n', 'image' => 'n8nio/n8n:1.64.0', 'status' => 'active', 'status_label' => 'Online', 'url' => 'https://flows.example.com', 'volumes' => [['id' => null, 'name' => 'app-data', 'detail' => null, 'used_bytes' => null, 'limit_bytes' => null, 'url' => null]], 'position' => ['x' => 0, 'y' => 0]],
                ['name' => 'db', 'icon' => 'postgresql', 'image' => 'postgres:17-alpine', 'status' => 'active', 'status_label' => 'Online', 'url' => null, 'volumes' => [['id' => null, 'name' => 'pg-data', 'detail' => null, 'used_bytes' => null, 'limit_bytes' => null, 'url' => null]], 'position' => ['x' => 300, 'y' => 0]],
                ['name' => 'cache', 'icon' => 'redis', 'image' => 'redis:7', 'status' => 'crashed', 'status_label' => 'Restarting · 4 restarts', 'url' => null, 'volumes' => [], 'position' => ['x' => 0, 'y' => 180]],
            ],
        ])
        ->and($canvas->json('edges'))->toBe([
            ['from' => "{$service->id}:app", 'to' => "{$service->id}:db", 'kind' => 'depends_on'],
            ['from' => "{$service->id}:app", 'to' => "{$service->id}:cache", 'kind' => 'depends_on'],
        ]);

    // Arranging the group: compose service positions (relative to the card) and collapsed state.
    $this->patchJson("{$this->base}/services/{$service->id}/layout", ['children' => ['cache' => ['x' => 600, 'y' => 20]], 'collapsed' => true])->assertOk();

    $canvas = $this->getJson("{$this->base}/canvas");
    expect($canvas->json('services.0.compose.collapsed'))->toBeTrue()
        ->and($canvas->json('services.0.compose.services.2.position'))->toBe(['x' => 600, 'y' => 20])
        ->and($canvas->json('services.0.compose.services.0.position'))->toBe(['x' => 0, 'y' => 0]);

    $this->patchJson("{$this->base}/services/{$service->id}/layout", ['children' => ['cache' => ['x' => 'left']]])->assertUnprocessable();
});

it('draws a repository stack from the project last read from git', function () {
    $web = sites_server($this->organization->id, ['name' => 'web-1'], docker: true);
    $site = projects_site($this->organization, 'Shop', [], $this->environment, [$web], [
        'framework' => 'docker', 'runtime' => 'compose', 'build_mode' => 'docker', 'php_version' => null, 'compose_source' => 'repo',
        'compose_files' => ['compose.yaml'], 'public_services' => [['service' => 'web', 'port' => 80, 'host_port' => 3201]],
    ]);
    $service = projects_service('site', $site->id);

    // Nothing read from the repository yet: no compose services to draw.
    expect($this->getJson("{$this->base}/canvas")->json('services.0.compose.services'))->toBe([]);

    // A deploy renders the files it read from git: the canvas shows them.
    app(ComposeSites::class)->render($site->id, "services:\n  web:\n    image: nginx:1.29-alpine\n    depends_on: [api]\n  api:\n    image: ghcr.io/acme/api:1\n", [], '01j9zq4n8v2m6r0t3w5y7b9d1f');
    app(ComposeSites::class)->recordStatus($site->id, $web->id, [
        ['service' => 'web', 'state' => 'running', 'health' => 'healthy', 'image' => 'nginx:1.29-alpine', 'restarts' => 0],
        ['service' => 'api', 'state' => 'running', 'health' => 'healthy', 'image' => 'ghcr.io/acme/api:1', 'restarts' => 0],
    ]);

    $canvas = $this->getJson("{$this->base}/canvas")->assertOk();
    expect(array_column($canvas->json('services.0.compose.services'), 'name'))->toBe(['web', 'api'])
        ->and(array_column($canvas->json('services.0.compose.services'), 'status'))->toBe(['active', 'active'])
        ->and($canvas->json('edges'))->toBe([['from' => "{$service->id}:web", 'to' => "{$service->id}:api", 'kind' => 'depends_on']]);
});

it('maps compose images to service icons', function (string $image, string $icon) {
    expect(ComposeGroup::icon($image))->toBe($icon);
})->with([
    ['postgres:17.2-alpine', 'postgresql'],
    ['ghcr.io/acme/status-api:1.2.0', 'docker'],
    ['grafana/grafana-oss:11.3.0', 'grafana'],
    ['docker.io/valkey/valkey:8@sha256:abc', 'redis'],
    ['mariadb:11', 'mariadb'],
    ['', 'docker'],
]);
