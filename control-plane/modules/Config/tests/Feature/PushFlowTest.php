<?php

use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;

require_once __DIR__.'/../Support/helpers.php';

it('leaves push-to-deploy sites of a project with an enabled source to Config', function () {
    $world = config_world(['push_to_deploy' => true]);
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  staging: { branch: develop }\n");

    $world->push(config_sha('b'));

    expect(Deployment::query()->count())->toBe(0)
        ->and(ConfigRun::query()->count())->toBe(0); // no environment of the file deploys main

    $world->source->forceFill(['enabled' => false])->save();
    $world->push(config_sha('c'));

    expect(Deployment::query()->count())->toBe(1); // a disabled source gives push-to-deploy back
});

it('records a failed run when the file is missing or invalid at the pushed commit', function () {
    $world = config_world();

    $world->push(config_sha('b'));
    $missing = ConfigRun::query()->latest()->first();

    expect($missing->status)->toBe(RunStatus::Failed)
        ->and($missing->trigger)->toBe(RunTrigger::Push)
        ->and($missing->commit)->toBe(config_sha('b'))
        ->and($missing->error)->toContain("falak.yaml doesn't exist at bbbbbbbbbbbb");

    $world->file("version: 1\nunits:\n  web:\n    type: app\n    runtime: frankenphp\n    php: 8.4\nenvironments:\n  production: { branch: main }\n");
    $world->push(config_sha('c'));
    $invalid = ConfigRun::query()->where('commit', config_sha('c'))->first();

    expect($invalid->status)->toBe(RunStatus::Failed)
        ->and($invalid->environment)->toBe('production')
        ->and($invalid->errors[0])->toMatchArray(['code' => 'schema', 'pointer' => '/units/web/php', 'line' => 6, 'column' => 10])
        ->and(Deployment::query()->count())->toBe(0);
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

it('runs once per environment bound to the pushed branch', function () {
    $world = config_world();
    projects_environment($world->deploy->organization, 'staging');
    $world->file("version: 1\nunits:\n  web: { type: app, runtime: frankenphp, php: \"8.4\" }\nenvironments:\n  production: { branch: main }\n  staging: { branch: main }\n  qa: { branch: main }\n  dev: { branch: develop }\n");

    $world->push(config_sha('b'));

    $runs = ConfigRun::query()->orderBy('environment')->get();
    expect($runs->pluck('environment')->all())->toBe(['production', 'qa', 'staging'])
        ->and($runs->firstWhere('environment', 'qa')->status)->toBe(RunStatus::Failed)
        ->and($runs->firstWhere('environment', 'qa')->error)->toContain('no environment "qa"');
});
