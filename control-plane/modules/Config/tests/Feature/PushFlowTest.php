<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Edge\Domain\Models\Domain;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_PUSH_FILE = "version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n";

it('Config deploys only the units of the file applied to the pushed branch; other sites keep push to deploy', function () {
    $world = config_world();
    $other = projects_site($world->deploy->organization, 'Other', [], $world->environment, $world->deploy->servers, [
        'source_connection_id' => $world->source->source_connection_id, 'repository' => 'acme/shop', 'branch' => 'main', 'push_to_deploy' => true,
    ]);
    $world->file(CONFIG_PUSH_FILE);

    // Before the file was ever applied, push to deploy deploys; Config's run reuses that deployment.
    $world->push(config_sha('b'));
    $world->settle();
    expect(Deployment::query()->where('site_id', $world->deploy->site->id)->count())->toBe(1)
        ->and(ConfigRun::query()->sole()->status)->toBe(RunStatus::Applied);

    // Now `web` is a unit of the applied file: only Config deploys it; `other` isn't in the file and keeps push to deploy.
    $world->push(config_sha('c'));
    $world->settle();
    expect(Deployment::query()->where('commit', config_sha('c'))->where('site_id', $world->deploy->site->id)->count())->toBe(1)
        ->and(Deployment::query()->where('commit', config_sha('c'))->where('site_id', $other->id)->count())->toBe(1);

    // An invalid file on the bound branch: a failed run says why, and the site still deploys the old way.
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: nope }\nenvironments:\n  production: { branch: main }\n");
    $world->push(config_sha('d'));
    expect(ConfigRun::query()->where('commit', config_sha('d'))->sole()->status)->toBe(RunStatus::Failed)
        ->and(Deployment::query()->where('commit', config_sha('d'))->where('site_id', $world->deploy->site->id)->count())->toBe(1);

    // A disabled source gives push to deploy back.
    $world->source->forceFill(['enabled' => false])->save();
    $world->push(config_sha('e'));
    expect(Deployment::query()->where('commit', config_sha('e'))->count())->toBe(2)
        ->and(ConfigRun::query()->where('commit', config_sha('e'))->exists())->toBeFalse();
});

it('fails the run when the deployment of a unit fails while it is being queued (a build refused at once)', function () {
    $world = config_world();
    $world->file(CONFIG_PUSH_FILE);
    $world->deploy->builds->refuse = 'VITE_KEY would put a sensitive secret into the public front-end build';

    $world->push(config_sha('b'));
    $world->settle();
    $run = ConfigRun::query()->sole();

    // Its DeploymentFailed fired before the run had recorded the deployment: the run must not wait for it forever.
    expect(Deployment::query()->pluck('status')->map->value->unique()->all())->toBe(['failed'])
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->deploy[0])->toMatchArray(['unit' => 'web', 'status' => 'failed'])
        ->and($run->deploy[0]['error'])->toContain('would put a sensitive secret');
});

it('gives a never-released unit its domains before its first deployment; a released one gets new ones with the release', function () {
    $world = config_world();
    $site = $world->deploy->site;
    $file = fn (array $domains) => "version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: \"8.4\"\n    domains: [".implode(', ', $domains)."]\nenvironments:\n  production: { branch: main }\n";
    $names = fn () => Domain::query()->where('site_id', $site->id)->pluck('name')->sort()->values()->all();

    // First deployment: the build hasn't even finished, yet the edge already routes the domain its health check uses.
    $world->file($file(['shop.example.com']));
    $world->push(config_sha('b'));
    expect(Deployment::query()->sole()->status->value)->not->toBe('succeeded')
        ->and($names())->toBe(['shop.example.com']);
    $world->settle();

    // Released since: a new domain waits for the release that brings it.
    $world->file($file(['shop.example.com', 'new.example.com']));
    $world->push(config_sha('c'));
    expect($names())->toBe(['shop.example.com']);
    $world->settle();
    expect($names())->toBe(['new.example.com', 'shop.example.com']);
});

it('a push never deploys a unit whose site has push to deploy off', function () {
    $world = config_world(['push_to_deploy' => false]);
    $world->file(str_replace('php: \"8.4\" }', 'php: \"8.4\", env: { FEATURE: \"on\" } }', CONFIG_PUSH_FILE));

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applied)
        ->and($run->deploy[0])->toMatchArray(['unit' => 'web', 'status' => 'skipped'])
        ->and(Deployment::query()->count())->toBe(0);
});

it('records a failed run only for branches the applied file binds, when the file is missing or invalid', function () {
    $world = config_world();

    $world->push(config_sha('a'));
    expect(ConfigRun::query()->count())->toBe(0); // never applied: nothing is bound yet

    $world->file(CONFIG_PUSH_FILE);
    $world->push(config_sha('b'));
    $world->settle();
    unset($world->deploy->sourceControl->files['falak.yaml']);

    $world->push(config_sha('c'), 'feature');
    expect(ConfigRun::query()->where('commit', config_sha('c'))->exists())->toBeFalse();

    $world->push(config_sha('d'));
    $missing = ConfigRun::query()->where('commit', config_sha('d'))->sole();
    expect($missing->status)->toBe(RunStatus::Failed)
        ->and($missing->trigger)->toBe(RunTrigger::Push)
        ->and($missing->environment)->toBe('production')
        ->and($missing->error)->toContain("falak.yaml doesn't exist at dddddddddddd")
        ->and(Deployment::query()->where('commit', config_sha('d'))->count())->toBe(1); // push to deploy fallback

    $world->file("version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: 8.4\nenvironments:\n  production: { branch: main }\n");
    $world->push(config_sha('e'));
    $invalid = ConfigRun::query()->where('commit', config_sha('e'))->sole();

    expect($invalid->status)->toBe(RunStatus::Failed)
        ->and($invalid->errors[0])->toMatchArray(['code' => 'schema', 'pointer' => '/units/web/php', 'line' => 6, 'column' => 10]);
});

it('binds the production environment to the default branch when the file has no environments', function () {
    $world = config_world();
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\n");

    $world->push(config_sha('b'), 'feature');
    expect(ConfigRun::query()->count())->toBe(0);

    $world->push(config_sha('c'));
    $run = ConfigRun::query()->sole();

    expect($run->environment)->toBe('production')
        ->and($run->resolved['environments'])->toBe(['production' => ['branch' => 'main']])
        ->and($run->status)->not->toBe(RunStatus::Failed);
});
