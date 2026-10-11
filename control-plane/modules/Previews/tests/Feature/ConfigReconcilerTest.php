<?php

use Falak\Config\Application\Planning\RiskRules;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Identity\Contracts\Role;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Previews\Infrastructure\Config\PreviewsReconciler;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Sites/tests/Support/config.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    sites_fake_agents();
    $this->sc = sites_fake_source_control();
    $this->connection = $this->sc->addConnection($this->organization->id);
    previews_fakes();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
    $this->production = projects_default_env($this->organization);
    $this->staging = projects_environment($this->organization, 'staging');
    projects_site($this->organization, 'web', [], $this->staging, [$this->server], ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'develop']);
    projects_database($this->organization, 'db', $this->staging);
    $this->source = config_source($this->organization, $this->staging, connectionId: $this->connection->id);
    $this->reconciler = app(PreviewsReconciler::class);
    $this->scope = fn ($environment) => config_scope($environment, $this->source, config_doc($environment->slug, [], ['branch' => 'develop']));
});

/**
 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
 */
function preview_changes(ChangeSet $set): array
{
    $out = [];

    foreach ($set->changes as $change) {
        $out[$change->path] = [$change->op->value, $change->before, $change->after];
    }

    return $out;
}

it('stores the file\'s previews block as the project\'s settings, in the run of its base environment only', function () {
    $desired = ['previews' => ['base' => 'staging', 'units' => ['db' => ['strategy' => 'empty'], 'docs' => 'omit'], 'server' => 'web-1',
        'domain_pattern' => 'pr-{number}-{service}-{project}', 'idle_ttl' => '48h', 'max_concurrent' => 3, 'access' => 'public', 'variables' => ['APP_NAME']]];
    $staging = ($this->scope)($this->staging);

    expect($this->reconciler->diff(($this->scope)($this->production), $desired, $this->reconciler->current(($this->scope)($this->production)))->changes)->toBe([]);

    $changes = preview_changes($this->reconciler->diff($staging, $desired, $this->reconciler->current($staging)));
    expect($changes)->toMatchArray([
        '/previews/enabled' => ['create', null, true],
        '/previews/base' => ['create', null, 'staging'],
        '/previews/units/db/strategy' => ['create', null, 'empty'],
        '/previews/idle_ttl' => ['create', null, '2d'],
    ]);

    $this->reconciler->apply($staging, $this->reconciler->diff($staging, $desired, $this->reconciler->current($staging)), config_ctx($this->staging));
    $settings = PreviewSettings::query()->sole();

    expect($settings->only(['enabled', 'base_environment_id', 'server_id', 'domain_pattern', 'idle_ttl_hours', 'max_concurrent', 'access', 'variables']))->toBe([
        'enabled' => true, 'base_environment_id' => $this->staging->id, 'server_id' => $this->server->id, 'domain_pattern' => 'pr-{number}-{service}-{project}',
        'idle_ttl_hours' => 48, 'max_concurrent' => 3, 'access' => 'public', 'variables' => ['APP_NAME'],
    ])->and($settings->services)->toBe(['db' => 'include', 'docs' => 'omit'])
        ->and($settings->databases['db']['strategy'])->toBe('empty')
        ->and(ConfigOwnership::query()->where('resource_type', 'preview_settings')->pluck('path')->all())->toContain('/previews/base', '/previews/units/db/strategy');

    // Exporting then planning changes nothing; a block gone from the file is released, previews stay on.
    $current = $this->reconciler->current($staging);
    expect(collect($this->reconciler->diff($staging, $current, $this->reconciler->current($staging))->actionable())->all())->toBe([]);

    $removed = $this->reconciler->diff($staging, [], $current);
    expect(preview_changes($removed)['/previews'][0])->toBe('detach');
    $this->reconciler->apply($staging, $removed, config_ctx($this->staging));

    expect(PreviewSettings::query()->sole()->enabled)->toBeTrue()
        ->and(ConfigOwnership::query()->where('resource_type', 'preview_settings')->exists())->toBeFalse();
});

it('needs an approval to put production data in previews', function () {
    $staging = ($this->scope)($this->staging);
    $desired = ['previews' => ['base' => 'staging', 'units' => ['db' => ['strategy' => 'clone_sanitize', 'sanitize' => ['sql' => 'UPDATE users SET email = id']]]]];
    $plan = app(RiskRules::class)->flag($this->reconciler->diff($staging, $desired, $this->reconciler->current($staging)), false, []);
    $risky = collect($plan->risky())->pluck('rule', 'path')->all();

    expect($risky)->toMatchArray(['/previews/units/db/strategy' => PreviewsReconciler::RULE, '/previews/units/db/sanitize/sql' => PreviewsReconciler::RULE]);

    // The staging copy of the database is not production's.
    $own = ['previews' => ['base' => 'staging', 'units' => ['db' => ['strategy' => 'clone_backup']]]];
    expect($this->reconciler->diff($staging, $own, $this->reconciler->current($staging))->isRisky())->toBeFalse();
});

it('validates names in the block', function () {
    $issues = $this->reconciler->validate(($this->scope)($this->staging), ['units' => ['web' => ['type' => 'app']], 'previews' => [
        'base' => 'staging',
        'units' => ['nope' => 'omit', 'web' => ['strategy' => 'empty'], 'db' => 'share'],
        'server' => 'elsewhere',
        'fork_server' => 'elsewhere',
        'idle_ttl' => '90m',
    ]]);

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toEqualCanonicalizing([
        'name.unit /previews/units/nope',
        'value.conflict /previews/units/web',
        'value.conflict /previews/units/db',
        'name.server /previews/server',
        'name.server /previews/fork_server',
        'value.range /previews/idle_ttl',
    ]);
});

it('refuses dashboard edits of preview settings the file manages', function () {
    $staging = ($this->scope)($this->staging);
    $desired = ['previews' => ['base' => 'staging', 'max_concurrent' => 3, 'units' => ['db' => ['strategy' => 'empty']]]];
    $this->reconciler->apply($staging, $this->reconciler->diff($staging, $desired, $this->reconciler->current($staging)), config_ctx($this->staging));
    $settings = PreviewSettings::query()->sole();
    $body = ['enabled' => true, 'base_environment_id' => $this->staging->id, 'max_concurrent' => 3, 'services' => ['db' => 'include'], 'databases' => ['db' => ['strategy' => 'empty']]];
    $url = "/projects/{$this->staging->project_id}/previews/settings";

    $this->put($url, [...$body, 'max_concurrent' => 9])->assertSessionHasErrors('max_concurrent');
    $this->put($url, [...$body, 'databases' => ['db' => ['strategy' => 'clone_backup']]])->assertSessionHasErrors('databases');
    $this->put($url, [...$body, 'idle_ttl_hours' => 24])->assertSessionHasNoErrors();

    expect($settings->refresh()->idle_ttl_hours)->toBe(24)->and($settings->max_concurrent)->toBe(3);
});
