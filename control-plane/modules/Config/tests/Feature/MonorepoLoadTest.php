<?php

use Falak\Config\Application\Drift\DriftChecker;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Domain\Models\Environment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

/*
| A monorepo's falak.yaml: 40 units (apps with workers, schedules and domains, docker apps with volumes, compose
| stacks, functions, databases, Redis) in 3 environments with overrides and previews: production and QA through
| validate → plan → apply → drift, staging planned, with the fake gateways. The query ceilings are the measured
| counts plus headroom: a plan or a drift check that starts querying per unit and field (an N+1) fails here.
*/

const MONOREPO_APPS = 16;
const MONOREPO_DOCKER_APPS = 4;
const MONOREPO_COMPOSE = 4;
const MONOREPO_FUNCTIONS = 6;
const MONOREPO_DATABASES = 6;
const MONOREPO_REDIS = 4;

/** @return array<string, mixed> */
function monorepo_document(): array
{
    $units = [];

    for ($i = 1; $i <= MONOREPO_DATABASES; $i++) {
        $units["db{$i}"] = ['type' => 'database', 'engine' => $i % 2 ? 'postgresql' : 'mysql', 'memory' => '512m',
            'databases' => ["app{$i}", "reports{$i}"]];
    }

    for ($i = 1; $i <= MONOREPO_REDIS; $i++) {
        $units["cache{$i}"] = ['type' => 'redis', 'memory' => '64m'];
    }

    for ($i = 1; $i <= MONOREPO_APPS; $i++) {
        $db = 'db'.(($i - 1) % MONOREPO_DATABASES + 1);
        $units["app{$i}"] = [
            'type' => 'app', 'root' => "apps/app{$i}", 'runtime' => 'php-fpm', 'framework' => 'laravel', 'php' => '8.4',
            'env' => ['APP_ENV' => 'production', 'DATABASE_URL' => "\${{ {$db}.DATABASE_URL }}",
                'REDIS_URL' => '${{ cache'.(($i - 1) % MONOREPO_REDIS + 1).'.REDIS_URL }}', 'FEATURE' => "f{$i}"],
            'deploy' => ['health' => ['path' => '/up'], 'hooks' => ['before_activate' => 'php artisan migrate --force']],
            'workers' => ['default' => ['command' => 'php artisan queue:work', 'processes' => 2], 'mail' => ['queue' => ['mail'], 'processes' => 1]],
            'daemons' => ['pulse' => ['command' => 'php artisan pulse:check']],
            'schedules' => ['prune' => ['command' => 'php artisan model:prune', 'cron' => '0 4 * * *']],
            'domains' => ["app{$i}.example.com"],
            'redirects' => [['from' => '/old', 'to' => '/new']],
            'headers' => ['X-Frame-Options' => 'DENY'],
            'limits' => ['memory' => '512m', 'cpus' => 1],
        ];
    }

    for ($i = 1; $i <= MONOREPO_DOCKER_APPS; $i++) {
        $units["box{$i}"] = ['type' => 'app', 'root' => "boxes/box{$i}", 'runtime' => 'docker', 'port' => 8080,
            'volumes' => ['uploads' => ['mount' => '/srv/uploads'], 'cache' => ['kind' => 'sized', 'size' => '256m', 'mount' => '/cache']]];
    }

    for ($i = 1; $i <= MONOREPO_COMPOSE; $i++) {
        $units["stack{$i}"] = ['type' => 'compose', 'root' => "stacks/stack{$i}", 'env' => ['MODE' => 'demo'],
            'services' => ['front' => ['port' => 80, 'health_path' => '/up', 'limits' => ['memory' => '256m']]],
            'domains' => ["stack{$i}.example.com"]];
    }

    for ($i = 1; $i <= MONOREPO_FUNCTIONS; $i++) {
        $units["fn{$i}"] = ['type' => 'function', 'root' => "fns/fn{$i}", 'runtime' => 'bun', 'memory' => '128m',
            'scaling' => ['max_instances' => 2], 'schedules' => ['nightly' => ['cron' => '0 3 * * *']]];
    }

    $units['app1']['mounts'] = [['path' => '/api', 'function' => 'fn1']];

    // Staging and QA: own domains, smaller workers, a staging-only unit, QA without the functions' schedules.
    $overrides = fn (string $env) => collect($units)
        ->filter(fn ($unit) => isset($unit['domains']))
        ->map(fn ($unit, $key) => ['domains' => ["{$key}.{$env}.example.com"]])
        ->all();
    $staging = $overrides('staging');
    $staging['app2']['workers'] = ['default' => ['processes' => 1]];
    $staging['scratch'] = ['type' => 'app', 'root' => 'apps/scratch', 'runtime' => 'static'];
    $qa = $overrides('qa');
    $qa['app3']['env'] = ['APP_ENV' => 'qa'];

    return [
        'version' => 1,
        'units' => $units,
        'environments' => [
            'production' => ['branch' => 'main', 'servers' => ['web-1']],
            'staging' => ['branch' => 'develop', 'servers' => ['web-1'], 'units' => $staging],
            'qa' => ['branch' => 'main', 'servers' => ['web-2'], 'units' => $qa],
        ],
        'previews' => ['enabled' => true, 'base' => 'production', 'units' => ['db1' => ['strategy' => 'empty'], 'app16' => 'omit']],
    ];
}

function monorepo_world(): ConfigWorld
{
    $world = config_world(servers: 2);

    foreach ($world->deploy->servers as $server) {
        Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $world->deploy->organization->id,
            'facts' => ['features' => ['fn.v1', 'fn.v2', 'fn.v3'], 'memory_bytes' => 64 * 1024 ** 3]]);
    }

    $compose = "services:\n  front: { image: nginx:1.27-alpine }\n";
    $world->deploy->builds->composeContent = $compose;

    for ($i = 1; $i <= MONOREPO_COMPOSE; $i++) {
        $world->deploy->sourceControl->files["stacks/stack{$i}/compose.yaml"] = $compose;
    }

    for ($i = 1; $i <= MONOREPO_FUNCTIONS; $i++) {
        $world->deploy->sourceControl->files["fns/fn{$i}/index.ts"] = "export default { fetch: () => new Response('fn{$i}') }\n";
    }

    $world->file(Yaml::dump(monorepo_document(), 8, 2));

    return $world;
}

/**
 * Runs $callback and counts its queries.
 *
 * @return array{0: mixed, 1: int, 2: float}
 */
function monorepo_count(callable $callback): array
{
    $queries = 0;
    $listening = true;
    DB::listen(function () use (&$queries, &$listening) {
        if ($listening) {
            $queries++;
        }
    });
    $started = microtime(true);
    $result = $callback();
    $listening = false;

    return [$result, $queries, round((microtime(true) - $started) * 1000)];
}

it('validates, plans, applies and drift-checks a 40-unit monorepo in 3 environments with bounded queries', function () {
    $world = monorepo_world();

    // The first push to main creates staging and qa, and applies production and qa.
    [, $applyQueries] = monorepo_count(fn () => $world->push(config_sha('b')));
    [, $settleQueries] = monorepo_count(fn () => $world->settle());

    $runs = ConfigRun::query()->get()->keyBy('environment');
    foreach (['production', 'qa'] as $env) {
        expect($runs[$env]->status)->toBe(RunStatus::Applied, "{$env}: ".json_encode([$runs[$env]->error, $runs[$env]->errors]))
            ->and(count($runs[$env]->plan['deploy']))->toBe(count(monorepo_document()['units']));
    }

    expect(PreviewSettings::query()->where('project_id', $world->environment->project_id)->sole()->only(['enabled', 'base_environment_id']))
        ->toBe(['enabled' => true, 'base_environment_id' => $world->environment->id]);

    // Staging (created empty by that push, fed by develop): its plan creates all 41 of its units. Planned, not applied,
    // to keep the test's time down; production and QA cover applying.
    $directory = app(ProjectDirectory::class);
    $stagingEnv = $directory->environment(Environment::query()->where('slug', 'staging')->value('id'));
    [[$stagingValidation, $stagingPlan], $stagingPlanQueries] = monorepo_count(fn () => app(PlanFile::class)($world->source, $stagingEnv, Yaml::dump(monorepo_document(), 8, 2), 'yaml'));
    expect($stagingValidation->errors)->toBe([])
        ->and(collect($stagingPlan->changes->actionable())->filter(fn ($c) => preg_match('#^/units/[^/]+$#', $c->path) === 1)->count())->toBe(count(monorepo_document()['units']) + 1);

    // Plan the same file again: nothing to do, in a bounded number of queries.
    $production = $directory->environment($world->environment->id);
    [[$validation, $plan], $planQueries, $planMs] = monorepo_count(fn () => app(PlanFile::class)($world->source, $production, Yaml::dump(monorepo_document(), 8, 2), 'yaml'));

    expect($validation->errors)->toBe([])
        ->and(array_map(fn ($c) => [$c->op->value, $c->path], $plan->changes->actionable()))->toBe([]);

    [$drift, $driftQueries, $driftMs] = monorepo_count(fn () => app(DriftChecker::class)->check($production));
    $qa = $directory->environment(Environment::query()->where('slug', 'qa')->value('id'));
    [$qaDrift, $qaDriftQueries] = monorepo_count(fn () => app(DriftChecker::class)->check($qa));

    expect($drift->status)->toBe('in_sync', json_encode($drift->changes))
        ->and($qaDrift->status)->toBe('in_sync', json_encode($qaDrift->changes));

    // ponytail: ceilings are the counts measured on 2026-10-12 plus ~30%: plan 1539 queries (~190 ms), staging's
    // all-create plan 164, drift 1061 (~100 ms), QA drift 1041, the push 30022 and phase B 85305 (mostly the fake
    // deployments). Plans and drift grow linearly (~38 queries per unit); a jump past a ceiling is a new per-field or
    // per-unit-pair query.
    expect($planQueries)->toBeLessThan(2000)
        ->and($stagingPlanQueries)->toBeLessThan(250)
        ->and($driftQueries)->toBeLessThan(1400)
        ->and($qaDriftQueries)->toBeLessThan(1400)
        ->and($applyQueries)->toBeLessThan(40000)
        ->and($settleQueries)->toBeLessThan(110000)
        ->and($planMs + $driftMs)->toBeLessThan(10000);
});
