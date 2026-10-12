<?php

use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\Config\DatabasesReconciler;
use Falak\Projects\Contracts\ProjectDirectory;

require_once __DIR__.'/../../../../Config/tests/Support/helpers.php';
require_once __DIR__.'/../../Support/helpers.php';

// Shared by the database and volume config tests: a config world (site `web` on web-1, production environment,
// enabled source on acme/shop), scopes built the way runs build them, plans and applies through one reconciler.

/**
 * @param  array<string, mixed>  $environment  the resolved file's environment (servers, branch)
 */
function data_scope(ConfigWorld $world, array $environment = ['branch' => 'main', 'servers' => ['web-1']], bool $allowDestroy = false, array $units = []): ConfigScope
{
    $data = app(ProjectDirectory::class)->environment($world->environment->id);

    return app(ScopeFactory::class)->make($data, $world->source->refresh(), ['units' => $units, 'environments' => [$data->slug => $environment]], config_sha('a'), null, $allowDestroy);
}

function data_ctx(ConfigWorld $world, bool $allowDestroy = false): ApplyContext
{
    $run = ConfigRun::query()->create([
        'organization_id' => $world->deploy->organization->id, 'project_id' => $world->environment->project_id, 'environment_id' => $world->environment->id,
        'environment' => $world->environment->slug, 'commit' => config_sha('a'), 'trigger' => 'push', 'status' => 'applying', 'file' => '',
    ]);

    return new ApplyContext($run->id, ApplyPhase::BeforeBuild, config_sha('a'), allowDestroy: $allowDestroy);
}

/**
 * The reconciler's diff of $units against today (its sections only, as the planner prunes them).
 *
 * @param  array<string, mixed>  $units
 */
function data_diff(ConfigScope $scope, array $units, ?object $reconciler = null): ChangeSet
{
    $reconciler ??= app(DatabasesReconciler::class);

    return $reconciler->diff($scope, $units === [] ? [] : ['units' => $units], $reconciler->current($scope));
}

/**
 * Plan then apply one reconciler, as phase A does; returns the actionable changes as [op, path].
 *
 * @param  array<string, mixed>  $units
 * @return list<array{0: string, 1: string}>
 */
function data_apply(ConfigWorld $world, array $units, ?object $reconciler = null, bool $allowDestroy = false, array $environment = ['branch' => 'main', 'servers' => ['web-1']]): array
{
    $reconciler ??= app(DatabasesReconciler::class);
    $scope = data_scope($world, $environment, $allowDestroy);
    $changes = data_diff($scope, $units, $reconciler);
    $reconciler->apply($scope, $changes, data_ctx($world, $allowDestroy));

    return data_table($changes->actionable());
}

/**
 * The whole plan (every reconciler, risky rules applied) of a resolved file's units.
 *
 * @param  array<string, mixed>  $units
 */
function data_plan(ConfigWorld $world, array $units, bool $allowDestroy = false): ChangeSet
{
    $environment = ['branch' => 'main', 'servers' => ['web-1']];
    $scope = data_scope($world, $environment, $allowDestroy, $units);

    return app(Planner::class)->plan($scope, ['units' => $units, 'environments' => [$world->environment->slug => $environment]])->changes;
}

/**
 * @param  list<Change>  $changes
 * @return list<array{0: string, 1: string}>
 */
function data_table(array $changes): array
{
    return array_values(array_map(fn (Change $c) => [$c->op->value, $c->path], $changes));
}

/** The risky rule of the change at $path (null: not risky, or no such change). */
function data_rule(ChangeSet $changes, string $path): ?string
{
    foreach ($changes->changes as $change) {
        if ($change->path === $path) {
            return $change->rule;
        }
    }

    return null;
}

/** Start every pending database container (and its databases and users), as the agent would. */
function data_start(ConfigWorld $world): void
{
    deploy_run_all($world->deploy->agents);
}

function data_instance(string $name = 'db'): DatabaseInstance
{
    return DatabaseInstance::query()->where('name', $name)->sole();
}
