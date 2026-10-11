<?php

use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Edge\Domain\Models\Mount;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Functions\Application\Actions\DeployCode;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

const CONFIG_FUNCTION_FILE = <<<'YAML'
version: 1
units:
  web:
    type: app
    root: app
    runtime: frankenphp
    php: "8.4"
    mounts:
      - { path: /api, function: api }
  api:
    type: function
    root: fn
    runtime: bun
    scaling: { min_instances: 1, max_instances: 3 }
    memory: 512m
    timeout: 1m
    access: { allow: [10.0.0.0/8] }
    env: { GREETING: hello }
    schedules:
      nightly: { cron: "0 3 * * *", timezone: Europe/Berlin }
environments:
  production: { branch: main, servers: [web-1] }

YAML;

const CONFIG_FN_V1 = "import { Hono } from 'hono'\nimport { db } from './lib/db'\nconst app = new Hono()\napp.get('/', (c) => c.text('v1'))\nexport default app\n";

function config_function_world(): ConfigWorld
{
    $world = config_world();
    Agent::factory()->create(['server_id' => $world->deploy->servers[0]->id, 'organization_id' => $world->deploy->organization->id,
        'facts' => ['features' => ['fn.v1', 'fn.v2', 'fn.v3'], 'memory_bytes' => 4 * 1024 ** 3]]);
    $world->file(CONFIG_FUNCTION_FILE);
    $world->deploy->sourceControl->files += [
        'fn/index.ts' => CONFIG_FN_V1,
        'fn/lib/db.ts' => "export const db = 'postgres'\n",
        'fn/.env' => "SECRET=1\n",
        'fn/node_modules/hono/index.js' => "module.exports = {}\n",
        'README.md' => "# shop\n",
    ];

    return $world;
}

it('deploys a function unit\'s code from the repository at the pushed commit', function () {
    $world = config_function_world();

    $world->push(config_sha('b'));
    $world->settle();

    $run = ConfigRun::query()->sole();
    $site = Site::query()->where('name', 'api')->sole();
    $function = CloudFunction::query()->where('site_id', $site->id)->sole();
    $version = $function->head();
    $apply = $world->deploy->agents->last('fn.release.apply');

    expect($run->status)->toBe(RunStatus::Applied)
        ->and($site->runtime->value)->toBe('function')
        ->and($site->repository)->toBeNull()
        ->and($function->only(['runtime', 'root', 'min_instances', 'max_instances', 'memory_mb', 'request_timeout_s']))
        ->toBe(['runtime' => 'bun', 'root' => 'fn', 'min_instances' => 1, 'max_instances' => 3, 'memory_mb' => 512, 'request_timeout_s' => 60])
        ->and($function->allow_cidrs)->toBe(['10.0.0.0/8'])
        // Only the code under root: no dot-files, no installer folders, nothing outside it.
        ->and(array_keys($version->files))->toBe(['index.ts', 'lib/db.ts'])
        ->and($version->number)->toBe(1)
        ->and($version->message)->toBe('falak.yaml @ bbbbbbb')
        ->and($apply['payload']['files'])->toBe([['path' => 'index.ts', 'content' => CONFIG_FN_V1], ['path' => 'lib/db.ts', 'content' => "export const db = 'postgres'\n"]])
        ->and($apply['payload']['scaling']['min_instances'])->toBe(1)
        ->and(Deployment::query()->where('site_id', $site->id)->sole()->commit)->toBe($version->hash)
        // Phase B with the release: the schedule, and the mount on the app's domains.
        ->and(FunctionSchedule::query()->where('function_id', $function->id)->sole()->only(['name', 'expression', 'timezone']))
        ->toBe(['name' => 'nightly', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin'])
        ->and(Mount::query()->where('site_id', $world->deploy->site->id)->sole()->only(['function_site_id', 'path_prefix', 'strip_prefix']))
        ->toBe(['function_site_id' => $site->id, 'path_prefix' => '/api', 'strip_prefix' => true]);

    // The editor no longer replaces code the file manages.
    expect(fn () => app(DeployCode::class)(app(SiteDirectory::class)->find($site->id), $function, ['index.ts' => 'x'], null, $version->id, $world->deploy->user->id, 'Ada'))
        ->toThrow(ValidationException::class, 'Managed by falak.yaml');

    // A push changing the code: a new version at that commit, deployed alone.
    $world->deploy->sourceControl->files['fn/index.ts'] = str_replace('v1', 'v2', CONFIG_FN_V1);
    $world->push(config_sha('c'), paths: ['fn/index.ts']);
    $world->settle();
    $v2 = $function->head();

    expect($v2->number)->toBe(2)
        ->and($v2->message)->toBe('falak.yaml @ ccccccc')
        ->and(Deployment::query()->where('site_id', $site->id)->latest('id')->first()->commit)->toBe($v2->hash)
        ->and(Deployment::query()->where('site_id', $world->deploy->site->id)->where('commit', config_sha('c'))->exists())->toBeFalse();

    // Files outside its root: no version, no deployment of the function.
    $world->push(config_sha('d'), paths: ['README.md']);
    $world->settle();

    expect(FunctionVersion::query()->where('function_id', $function->id)->count())->toBe(2)
        ->and(Deployment::query()->where('site_id', $site->id)->count())->toBe(2);

    // The same code at a later commit deploys the same version (the commit maps to it).
    $world->push(config_sha('e'));
    $world->settle();

    expect(FunctionVersion::query()->where('function_id', $function->id)->count())->toBe(2)
        ->and(Deployment::query()->where('site_id', $site->id)->latest('id')->first()->commit)->toBe($v2->hash);

    // Exporting then planning the export changes nothing.
    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $exporter = app(Exporter::class);
    $yaml = $exporter->render($exporter->document($environment, $world->source), 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml', config_sha('e'));

    expect($validation->errors)->toBe([])
        ->and($plan?->changes->actionable())->toBe([])
        ->and($yaml)->toContain('nightly')->toContain('root: fn')->not->toContain('api_key');
});

it('refuses function code the repository can\'t give', function () {
    $world = config_function_world();
    unset($world->deploy->sourceControl->files['fn/index.ts']);

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(collect($run->errors)->firstWhere('pointer', '/units/api/root'))->toMatchArray(['code' => 'value.range'])
        ->and(collect($run->errors)->firstWhere('pointer', '/units/api/root')['message'])->toContain('entrypoint index.ts is missing');
});
