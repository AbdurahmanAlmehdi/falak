<?php

use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Domain\Models\EnvironmentVersion;

require_once __DIR__.'/../../Support/config.php';

// Fields a project's falak.yaml manages: the dashboard gets them (read-only badge) and edits changing them get a 422.

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1'], ['8.3', '8.4']);
    $this->env = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', ['APP_ENV' => 'production', 'FREE' => 'x'], $this->env, [$this->server], [
        'runtime' => 'php-fpm',
        'deploy_script' => "\$FALAK_FETCH\nphp artisan migrate --force\n\$FALAK_ACTIVATE\necho live\n",
    ]);
    $this->source = config_source($this->organization, $this->env);
    app(ManagedResources::class)->record(config_scope($this->env, $this->source), config_ctx($this->env), 'web', 'site', $this->site->id, [
        '/php', '/env/APP_ENV', '/deploy/hooks/before_activate', '/deploy/strategy', '/deploy/watch/minutes', '/limits/memory', '/laravel/horizon',
    ]);
    $this->json = ['Accept' => 'application/json'];
});

it('tells the panels what the file manages, with the commit and a link', function () {
    $config = $this->getJson("/sites/{$this->site->id}/settings")->assertOk()->json('data.config');

    expect($config['badge'])->toBe('Managed by falak.yaml @ aaaaaaa')
        ->and($config['short'])->toBe('aaaaaaa')
        ->and($config['paths'])->toContain('/php', '/env/APP_ENV');

    foreach (["/sites/{$this->site->id}/deploy-script", "/sites/{$this->site->id}/environment", "/sites/{$this->site->id}/limits", "/sites/{$this->site->id}/deploy-settings"] as $url) {
        expect($this->getJson($url)->assertOk()->json('data.config.badge'))->toBe('Managed by falak.yaml @ aaaaaaa', $url);
    }
});

it('refuses edits that change managed fields and accepts the rest', function () {
    $id = $this->site->id;

    $this->patchJson("/sites/{$id}", ['php_version' => '8.3'])->assertStatus(422)->assertJsonValidationErrors('php_version');
    $this->patchJson("/sites/{$id}", ['php_version' => '8.4', 'web_directory' => 'web'])->assertOk(); // same managed value, other field free
    expect($this->site->refresh()->web_directory)->toBe('web');

    $this->putJson("/sites/{$id}/laravel", ['scheduler' => false, 'horizon' => true, 'octane' => false, 'maintenance' => false])->assertStatus(422)->assertJsonValidationErrors('horizon');

    $this->putJson("/sites/{$id}/deploy-script", ['script' => "\$FALAK_FETCH\nphp artisan migrate\n\$FALAK_ACTIVATE\necho live\n"])->assertStatus(422)->assertJsonValidationErrors('script');
    $this->putJson("/sites/{$id}/deploy-script", ['script' => "\$FALAK_FETCH\nphp artisan migrate --force\n\$FALAK_ACTIVATE\necho changed\n"])->assertRedirect();

    $this->patchJson("/sites/{$id}/environment", ['set' => ['APP_ENV' => 'staging'], 'unset' => [], 'exposed' => []])->assertStatus(422)->assertJsonValidationErrors('set');
    $this->patchJson("/sites/{$id}/environment", ['set' => [], 'unset' => ['APP_ENV'], 'exposed' => []])->assertStatus(422);
    $this->patchJson("/sites/{$id}/environment", ['set' => ['FREE' => 'y'], 'unset' => [], 'exposed' => []])->assertOk();
    $this->putJson("/sites/{$id}/environment", ['content' => "APP_ENV=dev\nFREE=y\n", 'exposed' => []])->assertStatus(422)->assertJsonValidationErrors('content');
    $this->putJson("/api/v1/sites/{$id}/env", ['content' => "FREE=z\n"])->assertStatus(422);

    $this->putJson("/sites/{$id}/limits", ['limits' => ['memory_limit' => 256]])->assertStatus(422)->assertJsonValidationErrors('memory_limit');

    $this->putJson("/sites/{$id}/deploy-settings", [
        'strategy' => 'in-place', 'batch_size' => 1, 'keep_releases' => 5, 'health_enabled' => true, 'health_status' => 200,
        'health_timeout_s' => 10, 'health_retries' => 3, 'health_retry_delay_s' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors('strategy');
    $this->putJson("/sites/{$id}/deploy-settings", [
        'strategy' => 'zero-downtime', 'batch_size' => 2, 'keep_releases' => 5, 'health_enabled' => true, 'health_status' => 200,
        'health_timeout_s' => 10, 'health_retries' => 3, 'health_retry_delay_s' => 5,
    ])->assertRedirect();
    $this->putJson("/sites/{$id}/deploy-settings/watch", ['minutes' => 30])->assertStatus(422)->assertJsonValidationErrors('minutes');
    $this->putJson("/api/v1/sites/{$id}/release-watch", ['minutes' => 30])->assertStatus(422);
    $this->putJson("/sites/{$id}/deploy-settings/watch", ['issues' => true])->assertRedirect();

    expect(EnvironmentVersion::query()->where('site_id', $id)->orderByDesc('version')->first()->variables)->toMatchArray(['APP_ENV' => 'production', 'FREE' => 'y']);
});

it('makes every field editable again once the config source is disabled', function () {
    ConfigSource::query()->whereKey($this->source->id)->update(['enabled' => false]);

    expect($this->getJson("/sites/{$this->site->id}/settings")->json('data.config'))->toBeNull();
    $this->patchJson("/sites/{$this->site->id}", ['php_version' => '8.3'])->assertOk();
    expect($this->site->refresh()->php_version)->toBe('8.3');
});
