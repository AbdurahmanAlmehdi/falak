<?php

use Falak\Identity\Contracts\Role;
use Falak\Templates\Application\Import\HostResolver;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Domain\Models\CustomTemplateRevision;
use Falak\Templates\Tests\Support\FakeHostResolver;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

function custom_yaml(string $slug = 'ours', string $version = '1.0.0'): string
{
    return str_replace(['slug: hello', 'name: Hello Stack', 'version: 1.2.0'], ["slug: {$slug}", 'name: Ours', "version: {$version}"], TEMPLATES_FIXTURE_TEMPLATE);
}

beforeEach(function () {
    templates_fixture_catalog();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('imports, previews, edits (with revisions) and deletes organization templates', function () {
    $this->postJson('/settings/templates/preview', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertOk()
        ->assertJsonPath('data.slug', 'ours')
        ->assertJsonPath('data.source', 'custom');
    expect(CustomTemplate::query()->count())->toBe(0);

    $id = $this->postJson('/settings/templates', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertCreated()
        ->assertJsonPath('data.revision', 1)
        ->json('data.id');

    $this->putJson("/settings/templates/{$id}", ['template_yaml' => custom_yaml(version: '1.1.0'), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertOk()
        ->assertJsonPath('data.version', '1.1.0')
        ->assertJsonPath('data.revision', 2);

    $this->getJson("/settings/templates/{$id}")->assertOk()
        ->assertJsonPath('data.compose_yaml', TEMPLATES_FIXTURE_COMPOSE)
        ->assertJsonPath('data.revisions.0.version', '1.1.0')
        ->assertJsonPath('data.revisions.1.version', '1.0.0');

    $this->get('/settings/templates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Templates/Settings', false)
        ->has('templates', 1)
        ->where('templates.0.slug', 'ours')
        ->where('templates.0.summary.services.1.name', 'admin')
        ->where('can.manage', true));

    $this->deleteJson("/settings/templates/{$id}", ['confirm' => 'wrong'])->assertUnprocessable();
    $this->deleteJson("/settings/templates/{$id}", ['confirm' => 'Ours'])->assertNoContent();

    expect(CustomTemplate::query()->count())->toBe(0)->and(CustomTemplateRevision::query()->count())->toBe(0);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'templates.imported', 'subject_id' => $id]);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'templates.deleted', 'subject_id' => $id]);
});

it('imports bundles (compose under compose:)', function () {
    $bundle = custom_yaml()."\ncompose:\n".preg_replace('/^/m', '  ', TEMPLATES_FIXTURE_COMPOSE);

    $this->postJson('/settings/templates', ['template_yaml' => $bundle])->assertCreated();

    expect(CustomTemplate::query()->sole()->compose_yaml)->toContain('nginx:1.29.1-alpine');
});

it('validates imports like the catalog', function (callable $payload, string $message) {
    $response = $this->postJson('/settings/templates', $payload())->assertUnprocessable()->assertJsonValidationErrors('template');

    expect(implode("\n", $response->json('errors.template')))->toContain($message);
})->with([
    'schema' => [fn () => ['template_yaml' => "name: x\n", 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE], 'slug is required'],
    'compose' => [fn () => ['template_yaml' => custom_yaml(), 'compose_yaml' => str_replace('nginx:1.29.1-alpine', 'nginx:latest', TEMPLATES_FIXTURE_COMPOSE)], 'not latest'],
    'catalog slug' => [fn () => ['template_yaml' => custom_yaml('hello'), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE], 'used by a catalog template'],
    'svg icon' => [fn () => ['template_yaml' => str_replace('icon: docker', 'icon: ./icon.svg', custom_yaml()), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE], 'icon key'],
    'policy' => [fn () => ['template_yaml' => custom_yaml(), 'compose_yaml' => str_replace('    image: nginx:1.29.1-alpine', "    image: nginx:1.29.1-alpine\n    privileged: true", TEMPLATES_FIXTURE_COMPOSE)], 'privileged'],
]);

it('limits the size of imported files', function () {
    $this->postJson('/settings/templates', ['template_yaml' => custom_yaml().'#'.str_repeat('x', 300 * 1024), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])
        ->assertUnprocessable()->assertJsonValidationErrors('template_yaml');
});

it('keeps slugs unique per organization', function () {
    $this->postJson('/settings/templates', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertCreated();
    $this->postJson('/settings/templates', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertUnprocessable();

    actingAsMember(Role::Owner);
    $this->postJson('/settings/templates', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertCreated();
});

it('fetches templates from public https URLs only', function () {
    app()->instance(HostResolver::class, new FakeHostResolver(['raw.example.com' => ['93.184.216.34'], 'intranet.example.com' => ['192.168.0.10']]));
    Http::fake([
        'https://raw.example.com/acme/templates/main/ours/template.yaml' => Http::response(custom_yaml()),
        'https://raw.example.com/acme/templates/main/ours/compose.yaml' => Http::response(TEMPLATES_FIXTURE_COMPOSE),
        'https://raw.example.com/bundle.yaml' => Http::response(custom_yaml()."\ncompose:\n  services:\n    web: {image: 'nginx:1'}\n"),
        'https://raw.example.com/compose.yaml' => Http::response(TEMPLATES_FIXTURE_COMPOSE),
    ]);

    $this->postJson('/settings/templates/fetch', ['url' => 'https://raw.example.com/acme/templates/main/ours/template.yaml'])->assertOk()
        ->assertJsonPath('data.template_yaml', custom_yaml())
        ->assertJsonPath('data.compose_yaml', TEMPLATES_FIXTURE_COMPOSE);

    $this->postJson('/settings/templates/fetch', ['url' => 'https://raw.example.com/bundle.yaml'])->assertOk()->assertJsonPath('data.compose_yaml', null);
    $this->postJson('/settings/templates/fetch', ['url' => 'https://raw.example.com/compose.yaml'])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->postJson('/settings/templates/fetch', ['url' => 'http://raw.example.com/x'])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->postJson('/settings/templates/fetch', ['url' => 'https://intranet.example.com/x'])->assertUnprocessable()
        ->assertJsonPath('errors.url.0', 'intranet.example.com resolves to a private or reserved address.');
});

it('authorizes template management', function () {
    $id = $this->postJson('/settings/templates', ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->json('data.id');

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);
    $this->get('/settings/templates')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->getJson("/settings/templates/{$id}")->assertOk();
    $this->postJson('/settings/templates', ['template_yaml' => custom_yaml('x'), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertForbidden();
    $this->putJson("/settings/templates/{$id}", ['template_yaml' => custom_yaml(), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertForbidden();
    $this->deleteJson("/settings/templates/{$id}", ['confirm' => 'Ours'])->assertForbidden();
    $this->postJson('/settings/templates/fetch', ['url' => 'https://raw.example.com/x'])->assertForbidden();

    actingAsMember(Role::Owner);
    $this->getJson("/settings/templates/{$id}")->assertNotFound();
});

it('shows which catalog the panel serves', function () {
    $this->get('/settings/templates')->assertInertia(fn (Assert $page) => $page
        ->where('catalog', ['version' => null, 'source' => 'built-in', 'synced_at' => null]));
});

it('keeps a custom template editable when the catalog later ships its slug', function () {
    $id = $this->postJson('/settings/templates', ['template_yaml' => custom_yaml('hello2'), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertCreated()->json('data.id');

    // An hourly sync brings a catalog template with the same slug.
    templates_fixture_catalog(['hello2' => [str_replace('slug: hello', 'slug: hello2', TEMPLATES_FIXTURE_TEMPLATE), TEMPLATES_FIXTURE_COMPOSE]]);

    $this->putJson("/settings/templates/{$id}", ['template_yaml' => custom_yaml('hello2', '1.1.0'), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])->assertOk()
        ->assertJsonPath('data.version', '1.1.0');

    // A new custom template still cannot take a catalog slug.
    $this->postJson('/settings/templates', ['template_yaml' => str_replace('name: Ours', 'name: Other', custom_yaml('hello2')), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE])
        ->assertUnprocessable()->assertJsonFragment(['template.yaml: slug hello2 is used by a catalog template; pick another one']);
});
