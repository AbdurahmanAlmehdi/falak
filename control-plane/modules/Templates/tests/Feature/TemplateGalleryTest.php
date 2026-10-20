<?php

use Falak\Identity\Contracts\Role;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Infrastructure\FilesystemCatalog;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    templates_fixture_catalog([
        'hello' => [TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE],
        'iconic' => [str_replace(['slug: hello', 'name: Hello Stack', 'icon: docker'], ['slug: iconic', 'name: Iconic', 'icon: ./icon.svg'], TEMPLATES_FIXTURE_TEMPLATE), TEMPLATES_FIXTURE_COMPOSE, '<svg xmlns="http://www.w3.org/2000/svg"/>'],
        'broken' => ['name: [', 'services: {}'],
    ]);
});

it('renders the gallery page with catalog and custom templates', function () {
    [, $organization] = actingAsMember(Role::Developer);
    CustomTemplate::query()->create([
        'organization_id' => $organization->id, 'slug' => 'ours', 'name' => 'Ours', 'version' => '1.0.0', 'category' => 'cms', 'description' => 'Ours',
        'template_yaml' => str_replace(['slug: hello', 'name: Hello Stack'], ['slug: ours', 'name: Ours'], TEMPLATES_FIXTURE_TEMPLATE), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE,
    ]);

    $this->get('/templates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Templates/Index', false)
        ->has('templates', 3)
        ->where('templates.0.slug', 'hello')
        ->where('templates.0.source', 'catalog')
        ->where('templates.0.icon', ['type' => 'brand', 'key' => 'docker'])
        ->where('templates.0.services.0', ['name' => 'web', 'image' => 'nginx:1.29.1-alpine'])
        ->where('templates.1.icon', ['type' => 'url', 'url' => '/templates/catalog/iconic/icon.svg'])
        ->where('templates.2.slug', 'ours')
        ->where('templates.2.source', 'custom')
        ->where('can', ['deploy' => true, 'manage' => true])
        ->has('categories', 10));
});

it('lists templates as JSON for the Create picker', function () {
    actingAsMember(Role::Viewer);

    $this->getJson('/templates')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.stateful', true)
        ->assertJsonPath('data.0.min_memory_mb', 256)
        ->assertJsonPath('data.0.public.1', ['service' => 'admin', 'port' => 9000]);
});

it('shows template details with freshly generated secrets', function () {
    actingAsMember(Role::Developer);
    config(['sites.test_domain' => 'falak.test']);

    $first = $this->getJson('/templates/catalog/hello')->assertOk()
        ->assertJsonPath('data.test_domain', 'falak.test')
        ->assertJsonPath('data.inputs.0', ['key' => 'APP_SECRET', 'type' => 'secret', 'label' => 'App secret', 'generate' => 'secret(32)', 'required' => false, 'secret' => true, 'generated' => true])
        ->assertJsonPath('data.inputs.3.options', ['UTC', 'Europe/Berlin'])
        ->json('data.generated');
    $second = $this->getJson('/templates/catalog/hello')->json('data.generated');

    expect(array_keys($first))->toBe(['APP_SECRET', 'DB_PASSWORD'])
        ->and($first['APP_SECRET'])->not->toBe($second['APP_SECRET']);

    $this->getJson('/templates/catalog/hello/generate/APP_SECRET')->assertOk()->assertJsonPath('data.key', 'APP_SECRET');
    $this->getJson('/templates/catalog/hello/generate/ADMIN_EMAIL')->assertNotFound();
    $this->getJson('/templates/catalog/nope')->assertNotFound();
    $this->getJson('/templates/custom/hello')->assertNotFound();
});

it('serves catalog icons as locked-down SVG', function () {
    actingAsMember(Role::Viewer);

    $this->get('/templates/catalog/iconic/icon.svg')->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
    $this->get('/templates/catalog/hello/icon.svg')->assertNotFound();
});

it('skips templates that do not parse and caches the files', function () {
    actingAsMember(Role::Viewer);

    expect(array_map(fn ($t) => $t->slug, app(Catalog::class)->all()))->toBe(['hello', 'iconic']);
});

it('requires a session', function () {
    $this->get('/templates')->assertRedirect('/login');
});

it('skips templates that need a newer Falak, quietly, even when they use keys this panel does not know', function () {
    $future = str_replace('slug: hello', "slug: future\nmin_falak: 9.0.0\nbrand_new: true", TEMPLATES_FIXTURE_TEMPLATE);
    templates_fixture_catalog(['hello' => [TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE], 'future' => [$future, TEMPLATES_FIXTURE_COMPOSE]]);
    config(['controlplane.version' => 'v1.2.0']);
    Log::spy();

    expect(array_map(fn ($t) => $t->slug, app(Catalog::class)->all()))->toBe(['hello']);
    Log::shouldNotHaveReceived('warning');

    // A panel without a release version (development) offers everything that parses.
    expect(FilesystemCatalog::supported("min_falak: 9.0.0\n", null))->toBeTrue()
        ->and(FilesystemCatalog::supported("min_falak: 9.0.0\n", 'v1.2.0'))->toBeFalse()
        ->and(FilesystemCatalog::supported("min_falak: 1.2.0\n", 'v1.2.0'))->toBeTrue()
        ->and(FilesystemCatalog::supported("name: x\n", 'v1.2.0'))->toBeTrue();
});
