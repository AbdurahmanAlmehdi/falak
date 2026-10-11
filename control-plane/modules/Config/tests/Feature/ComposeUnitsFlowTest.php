<?php

use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_COMPOSE_FILE = <<<'YAML'
version: 1
units:
  web: { type: app, runtime: frankenphp, php: "8.4" }
  shop:
    type: compose
    root: stack
    files: [compose.yaml]
    env: { SHOP_MODE: demo }
    deploy: { keep_releases: 3 }
    domains: [shop.example.com]
    services:
      front: { port: 80, health_path: /up, limits: { memory: 256m } }
      api: { port: 8080, domains: [api.example.com] }
environments:
  production: { branch: main, servers: [web-1] }

YAML;

it('creates a compose unit on push, deploys it, and exporting then planning gives an empty plan', function () {
    $world = config_world();
    $compose = "services:\n  front: { image: nginx:1.27-alpine }\n  api: { build: . }\n";
    $world->deploy->sourceControl->files['stack/compose.yaml'] = $compose;
    // What the build finds in the repository.
    $world->deploy->builds->composeContent = $compose;
    $world->deploy->builds->composeImages = ['api' => 'registry.falak.local/falak/shop/api@sha256:'.str_repeat('a', 64)];
    $world->file(CONFIG_COMPOSE_FILE);

    $world->push(config_sha('b'));
    $world->settle();

    $run = ConfigRun::query()->sole();
    $shop = Site::query()->where('name', 'shop')->sole();

    expect($run->status)->not->toBe(RunStatus::Failed, (string) $run->error)
        ->and($shop->runtime->value)->toBe('compose')
        ->and(array_column($shop->public_services, 'service'))->toBe(['front', 'api'])
        ->and($shop->compose_limits['front'] ?? null)->toMatchArray(['memory_limit' => 256])
        ->and(Deployment::query()->where('site_id', $shop->id)->where('commit', config_sha('b'))->exists())->toBeTrue();

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $exporter = app(Exporter::class);
    $yaml = $exporter->render($exporter->document($environment, $world->source), 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml');

    expect($validation->errors)->toBe([])
        ->and($plan?->changes->actionable())->toBe([])
        ->and($yaml)->toContain('compose.yaml')->not->toContain('keep_binds');
});
