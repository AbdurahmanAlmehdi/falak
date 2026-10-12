<?php

use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ApplyContext;
use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Domain\Models\Organization;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Domain\Models\Environment;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

/*
| Config-as-code helpers for the reconcilers' tests (Sites, Deployments, Limits).
*/

/** An enabled config source for the environment's project. */
function config_source(Organization $organization, Environment $environment, bool $enabled = true, ?string $connectionId = null): ConfigSource
{
    return ConfigSource::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $environment->project_id,
        'source_connection_id' => $connectionId ?? strtolower((string) Str::ulid()),
        'repository' => 'acme/shop',
        'path' => 'falak.yaml',
        'enabled' => $enabled,
    ]);
}

/**
 * The scope Config would plan with (services, attached servers, environment defaults from $resolved).
 *
 * @param  array<string, mixed>  $resolved
 */
function config_scope(Environment $environment, ?ConfigSource $source = null, array $resolved = [], bool $allowDestroy = false): ConfigScope
{
    $data = app(ProjectDirectory::class)->environment($environment->id);
    $s = app(ScopeFactory::class)->make($data, $source, $resolved, str_repeat('a', 40));

    // The run's allow_destroy (config.destroy): whether removed units may be deleted.
    return new ConfigScope($s->organizationId, $s->projectId, $s->environmentId, $s->environment, $s->production, $s->commit, $s->services,
        $s->servers, $s->defaultServers, $s->connectionId, $s->repository, $s->branch, $s->actorId, $allowDestroy, $s->credentials);
}

/** A run to record ownership against, and its apply context. */
function config_ctx(Environment $environment, ApplyPhase $phase = ApplyPhase::BeforeBuild, bool $allowDestroy = false): ApplyContext
{
    $run = ConfigRun::query()->create([
        'organization_id' => $environment->organization_id,
        'project_id' => $environment->project_id,
        'environment_id' => $environment->id,
        'environment' => $environment->slug,
        'commit' => str_repeat('a', 40),
        'trigger' => 'push',
        'status' => 'applying',
        'file' => '',
    ]);

    return new ApplyContext($run->id, $phase, $run->commit, allowDestroy: $allowDestroy);
}

/**
 * @param  array<string, mixed>  $units
 * @return array<string, mixed>
 */
function config_doc(string $environment, array $units, array $env = []): array
{
    return ['version' => 1, 'units' => $units, 'environments' => [$environment => $env]];
}
