<?php

use Falak\Config\Application\Actions\RequestRun;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Infrastructure\Config\EdgeReconciler;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/units.php';

/*
| Phase 3 units under the phase 1 safety rules: deletes need allow_destroy and are risky, env values are masked,
| credentials are names the project may use.
*/

it('never shows a function or compose unit\'s env values in a stored plan', function () {
    $world = config_function_world();
    $world->push(config_sha('b'));
    $plan = json_encode(ConfigRun::query()->sole()->plan, JSON_UNESCAPED_UNICODE);

    expect($plan)->not->toContain('hello')->toContain('••••');
});

it('detaches a function unit gone from the file; deleting it needs allow_destroy and an approval', function () {
    $world = config_function_world();
    $world->push(config_sha('b'));
    $world->settle();
    $site = Site::query()->where('name', 'api')->sole();
    $world->source->forceFill(['experimental' => false])->save();
    $without = preg_replace('/  api:\n(    .*\n|      .*\n)+/', '', CONFIG_FUNCTION_FILE);
    $without = str_replace("    mounts:\n      - { path: /api, function: api }\n", '', (string) $without);

    // allow_destroy (config.destroy): a delete, which is risky (unit.delete): it waits for an approval.
    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $run = app(RequestRun::class)($world->source, $environment, $without, 'yaml', null, RunTrigger::Cli, $world->deploy->user->id, allowDestroy: true, branch: 'main');
    $change = collect($run->plan['changes'])->firstWhere('path', '/units/api');

    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and($change)->toMatchArray(['op' => 'delete', 'rule' => 'unit.delete', 'risky' => true]);
    $run->forceFill(['status' => RunStatus::Cancelled])->save();

    // A push (no allow_destroy) only detaches: the function stays, no longer managed.
    $world->file($without);
    $world->push(config_sha('c'));
    $world->settle();
    $pushed = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect(collect($pushed->plan['changes'])->firstWhere('path', '/units/api')['op'])->toBe('detach')
        ->and($pushed->status)->toBe(RunStatus::Applied)
        ->and(Site::query()->find($site->id))->not->toBeNull()
        ->and(app(ManagedResources::class)->managed('site', $site->id)->any())->toBeFalse();
});

it('a compose service\'s domains name only certificates the project may use', function () {
    $world = config_world();
    Certificate::query()->create(['organization_id' => $world->deploy->organization->id, 'name' => 'wildcard', 'domains' => ['*.example.com'], 'cert_pem' => 'x', 'key_pem' => 'y',
        'not_before' => now()->subDay(), 'not_after' => now()->addYear(), 'fingerprint' => str_repeat('f', 64)]);
    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $scope = app(ScopeFactory::class)->make($environment, $world->source);
    $issues = app(EdgeReconciler::class)->validate($scope, ['units' => ['shop' => ['type' => 'compose', 'services' => [
        'api' => ['port' => 8080, 'domains' => [['name' => 'api.example.com', 'tls' => 'custom', 'certificate' => 'wildcard']]],
    ]]]]);

    expect(collect($issues)->map(fn ($i) => $i->code.' '.$i->pointer)->all())->toBe(['name.certificate /units/shop/services/api/domains/0/certificate']);

    $scope = app(ScopeFactory::class)->make($environment, $world->source->forceFill(['allowed_credentials' => ['wildcard']]));
    expect(app(EdgeReconciler::class)->validate($scope, ['units' => ['shop' => ['type' => 'compose', 'services' => [
        'api' => ['port' => 8080, 'domains' => [['name' => 'api.example.com', 'tls' => 'custom', 'certificate' => 'wildcard']]],
    ]]]]))->toBe([]);
});
