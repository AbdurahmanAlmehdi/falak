<?php

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Runs\RollbackRestorer;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigOwnership;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Databases\Application\Actions\TakeOverInstance;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\Config\DatabasesReconciler;
use Falak\Deployments\Domain\Models\Release;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Domain\Models\Server;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Domain\Models\Volume;

require_once __DIR__.'/support.php';

// Phase 3 review fixes of the data lane: the canvas service's database is never deleted, a unit whose service is gone
// is a conflict (not a new empty container), ownership follows upgrades and stays per environment, rollbacks don't
// create data, retention and drill rules, preview servers, restart notes and lone upgrades.

beforeEach(function () {
    $this->world = config_world();
    $this->organization = $this->world->deploy->organization;
    $this->server = Server::query()->where('organization_id', $this->organization->id)->where('name', 'web-1')->sole();
    $this->reconciler = app(DatabasesReconciler::class);
});

function review_unit(array $extra = []): array
{
    return ['db' => ['type' => 'database', 'engine' => 'postgresql', 'databases' => ['shop', 'extra'], ...$extra]];
}

it('never deletes the database the unit\'s canvas service points to, even with allow_destroy', function () {
    data_apply($this->world, review_unit());
    data_start($this->world);

    $plan = data_diff(data_scope($this->world, allowDestroy: true), review_unit(['databases' => ['extra']]));

    expect(data_table($plan->actionable()))->toBe([['detach', '/units/db/databases/shop']]);

    data_apply($this->world, review_unit(['databases' => ['extra']]), allowDestroy: true);
    expect(Service::query()->where('environment_id', $this->world->environment->id)->where('name', 'db')->exists())->toBeTrue()
        ->and($this->world->deploy->agents->dispatched('db.drop'))->toBe([]);
});

it('reports a unit whose canvas service is gone as a conflict instead of creating an empty container', function () {
    data_apply($this->world, review_unit());
    // Every canvas service of the container is gone (unlinked from the canvas, its databases still there).
    Service::query()->where('environment_id', $this->world->environment->id)->where('kind', ServiceKind::Database)->delete();
    $scope = data_scope($this->world);

    $issues = collect($this->reconciler->validate($scope, ['units' => review_unit()]))->map(fn ($i) => [$i->code, $i->pointer])->all();

    expect($issues)->toContain(['value.conflict', '/units/db'])
        ->and(data_diff($scope, review_unit())->changes)->toBe([])
        ->and(DatabaseInstance::query()->count())->toBe(1);
});

it('moves ownership to the container that takes over (a major upgrade), so a renamed service still maps', function () {
    data_apply($this->world, review_unit());
    data_start($this->world);
    $old = data_instance('db');
    $volume = app(ServiceVolumes::class)->createSized($this->organization->id, $this->server->id, 'db-upgraded', 10 * 1024 ** 3);
    $new = databases_instance($this->organization, 'postgresql', $this->server, ['name' => 'db', 'version' => '18', 'environment_id' => $this->world->environment->id, 'host_port' => 29999, 'volume_id' => $volume->id]);

    app(TakeOverInstance::class)($old, $new, 'Retired', null);
    Service::query()->where('environment_id', $this->world->environment->id)->where('name', 'db')->update(['name' => 'renamed']);
    $managed = app(ManagedResources::class);

    expect($managed->managedIn($this->world->environment->id, 'database_instance', $new->id))->toBeTrue()
        ->and($managed->managedIn($this->world->environment->id, 'database_instance', $old->id))->toBeFalse()
        ->and(data_scope($this->world)->services['db']['kind'] ?? null)->toBe('database')
        ->and(collect(data_diff(data_scope($this->world), review_unit())->actionable())->pluck('op')->all())->not->toContain('create');
});

it('decides removals from its own environment\'s ownership only', function () {
    data_apply($this->world, review_unit());
    $instance = data_instance('db');
    ConfigOwnership::query()->where('resource_id', $instance->id)->update(['environment_id' => strtolower((string) Str::ulid())]);

    expect(data_diff(data_scope($this->world, allowDestroy: true), [])->changes)->toBe([]);
});

it('neither attaches servers nor lends storage of another project\'s container placed here', function () {
    $otherProject = Project::query()->create(['organization_id' => $this->organization->id, 'name' => 'Other']);
    $otherEnv = projects_environment($this->organization, 'production', $otherProject);
    $far = sites_server($this->organization->id, ['name' => 'far'], docker: true);
    [$database, , $instance] = databases_service($this->organization, 'postgresql', 'theirs', $far, ['environment_id' => $otherEnv->id]);
    $provider = databases_provider($this->organization, ['name' => 'their-storage']);
    BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'database_instance_id' => $instance->id, 'storage_provider_id' => $provider->id,
        'name' => 'n', 'cron' => '0 4 * * *', 'encryption_mode' => 'cp', 'drill' => 'off', 'enabled' => true]);
    app(LinkService::class)($this->world->environment, ServiceKind::Database, $database->id, 'theirs');

    expect(app(ScopeFactory::class)->attachedServers($this->world->environment->project_id))->not->toHaveKey('far')
        ->and(app(BackupStorage::class)->namesForProject($this->organization->id, $this->world->environment->project_id))->not->toContain('their-storage');
});

it('never attaches a preview environment\'s servers to the production scope; drill servers on production are risky', function () {
    $preview = Environment::query()->create(['organization_id' => $this->organization->id, 'project_id' => $this->world->environment->project_id,
        'name' => 'pr-9', 'slug' => 'pr-9', 'is_production' => false, 'is_preview' => true]);
    $forkServer = sites_server($this->organization->id, ['name' => 'fork-box'], docker: true);
    $site = projects_site($this->organization, 'fork-web', [], $preview, [$forkServer]);

    expect(app(ScopeFactory::class)->attachedServers($this->world->environment->project_id))->not->toHaveKey('fork-box')
        ->and(app(ScopeFactory::class)->attachedServers($this->world->environment->project_id, true))->toHaveKey('fork-box');

    [, , $instance] = databases_service($this->organization, 'postgresql', 'app', $this->server, ['environment_id' => $this->world->environment->id, 'name' => 'db']);
    data_existing_link($instance);
    $provider = databases_provider($this->organization, ['name' => 'offsite']);
    $this->world->source->forceFill(['allowed_credentials' => ['offsite']])->save();
    $plan = data_plan($this->world, ['db' => ['type' => 'database', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']]]);
    data_apply($this->world, ['db' => ['type' => 'database', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']]]);
    sites_server($this->organization->id, ['name' => 'web-2'], docker: true);

    $plan = data_plan($this->world, ['db' => ['type' => 'database', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'drill_server' => 'web-1']]]);
    expect(data_rule($plan, '/units/db/backups/drill_server'))->toBe('production.drill_server');
});

function data_existing_link(DatabaseInstance $instance): void
{
    app(LinkService::class)(test()->world->environment, ServiceKind::Database, $instance->databases()->first()->id, 'db');
}

it('flags shorter retention and databases dropped from the schedule', function (array $before, array $after, string $path, string $rule) {
    $this->world->source->forceFill(['allowed_credentials' => ['offsite']])->save();
    databases_provider($this->organization, ['name' => 'offsite']);
    $backups = ['cron' => '0 3 * * *', 'storage' => 'offsite'];
    data_apply($this->world, review_unit(['backups' => [...$backups, ...$before]]));

    $plan = data_plan($this->world, review_unit(['backups' => [...$backups, ...$after]]));

    expect(data_rule($plan, $path))->toBe($rule);
})->with([
    'keep set' => [[], ['keep' => 7], '/units/db/backups/keep', 'database.backups_retention'],
    'keep lowered' => [['keep' => 30], ['keep' => 7], '/units/db/backups/keep', 'database.backups_retention'],
    'keep_days lowered' => [['keep_days' => 30], ['keep_days' => 7], '/units/db/backups/keep_days', 'database.backups_retention'],
    'database dropped' => [[], ['databases' => ['shop']], '/units/db/backups/databases', 'database.backups_databases_removed'],
]);

it('notes restarts on new settings too, and wants a version upgrade alone in its push', function () {
    config(['databases.digests.postgresql.18' => 'sha256:'.str_repeat('c', 64)]);
    data_apply($this->world, review_unit());
    data_start($this->world);
    $scope = data_scope($this->world);

    $note = collect(data_diff($scope, review_unit(['settings' => ['max_connections' => 300]]))->changes)->firstWhere('path', '/units/db/settings/max_connections');
    $issues = collect($this->reconciler->validate($scope, ['units' => review_unit(['version' => '18', 'memory' => '1g'])]))->map(fn ($i) => [$i->code, $i->pointer])->all();
    $alone = collect($this->reconciler->validate($scope, ['units' => review_unit(['version' => '18'])]))->map(fn ($i) => $i->pointer)->all();

    expect($note->op->value)->toBe('create')->and($note->note)->toBe(DatabasesReconciler::RESTART_NOTE)
        ->and($issues)->toContain(['value.conflict', '/units/db/version'])
        ->and($alone)->not->toContain('/units/db/version');
});

it('refuses a disk size for a container whose data volume is not sized', function () {
    [$database, , $instance] = databases_service($this->organization, 'postgresql', 'app', $this->server, ['name' => 'db', 'environment_id' => $this->world->environment->id]);
    app(LinkService::class)($this->world->environment, ServiceKind::Database, $database->id, 'db');

    $issues = collect($this->reconciler->validate(data_scope($this->world), ['units' => ['db' => ['type' => 'database', 'engine' => 'postgresql', 'disk' => '20g']]]))
        ->map(fn ($i) => [$i->code, $i->pointer])->all();

    expect($issues)->toContain(['value.unsupported', '/units/db/disk']);
});

it('a rollback never creates a data resource', function () {
    $this->world->deploy->site->forceFill(['runtime' => 'docker', 'framework' => 'docker', 'build_mode' => 'docker', 'php_version' => null, 'dockerfile' => 'Dockerfile', 'app_port' => 30001, 'container_port' => 8080])->save();
    $file = fn (string $volumes) => "version: 1\nunits:\n  web:\n    type: app\n    runtime: docker\n    port: 8080\n{$volumes}environments:\n  production: { branch: main, servers: [web-1] }\n";
    $this->world->file($file("    volumes:\n      data: { mount: /data }\n"));
    $this->world->push(config_sha('b'));
    $this->world->settle();
    $with = Release::query()->where('site_id', $this->world->deploy->site->id)->latest('id')->firstOrFail();

    // The file drops the volume (detached, kept); rolling back to the release that had it must not create an empty one.
    $source = $this->world->source;
    $source->forceFill(['experimental' => false])->save();
    $this->world->file($file(''));
    $this->world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();
    app(ApproveRun::class)($run, $this->world->deploy->user->id);
    $this->world->settle();
    $volumes = Volume::query()->count();

    $rollback = app(RollbackRestorer::class)->restore($this->world->environment->project_id, $this->world->environment->id, 'web', $with->id, config_sha('b'));

    expect($rollback->status)->toBe(RunStatus::Applied)
        ->and(collect($rollback->errors)->where('code', 'rollback.risky_skipped')->pluck('pointer')->all())->toContain('/units/web/volumes/data')
        ->and(Volume::query()->count())->toBe($volumes);
});

it('refuses renaming or deleting a managed database service and deleting its first database from the dashboard', function () {
    data_apply($this->world, review_unit());
    data_start($this->world);
    $env = $this->world->environment;
    $service = Service::query()->where('environment_id', $env->id)->where('name', 'db')->sole();
    $shop = data_instance('db')->databases()->where('name', 'shop')->sole();
    app(ManagedResources::class)->forget('database', $shop->id);

    $this->patchJson("/projects/{$env->project_id}/{$env->slug}/services/{$service->id}", ['name' => 'renamed'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->deleteJson("/projects/{$env->project_id}/{$env->slug}/services/{$service->id}", ['confirm' => 'db'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->deleteJson("/databases/databases/{$shop->id}", ['confirm' => 'shop'])->assertStatus(422)->assertJsonValidationErrors('confirm');

    expect(DatabaseInstance::query()->where('name', 'db')->sole()->status->value)->toBe('active');
});
