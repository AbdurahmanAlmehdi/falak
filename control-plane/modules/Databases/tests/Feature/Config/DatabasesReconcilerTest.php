<?php

use Falak\Config\Contracts\ChangeOp;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\Config\DatabasesReconciler;
use Falak\Identity\Domain\Models\Organization;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Domain\Models\Server;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/support.php';

// falak.yaml's database and redis units (Databases' Reconcilable): current → desired → ChangeSet tables, apply through
// the module's actions with the fake agent gateway, the never-delete rules, names-only storage and ownership.

beforeEach(function () {
    $this->world = config_world();
    $this->organization = $this->world->deploy->organization;
    $this->server = Server::query()->where('organization_id', $this->organization->id)->where('name', 'web-1')->sole();
    $this->reconciler = app(DatabasesReconciler::class);
});

/**
 * A database container placed in the environment as canvas service $unit, with a backup schedule named falak.yaml.
 *
 * @return array{0: Database, 1: DatabaseInstance}
 */
function data_existing(string $unit = 'db', string $engine = 'postgresql', array $attributes = []): array
{
    $volume = app(ServiceVolumes::class)->createSized(test()->organization->id, test()->server->id, "db-{$unit}", 10 * 1024 ** 3, protected: true);
    [$database, , $instance] = databases_service(test()->organization, $engine, $engine === 'postgresql' ? 'app' : $unit, test()->server, ['name' => $unit, 'volume_id' => $volume->id, ...$attributes]);
    Volume::query()->whereKey($volume->id)->update(['status' => 'active']);
    app(LinkService::class)(test()->world->environment, ServiceKind::Database, $database->id, $unit);

    return [$database, $instance];
}

/** A storage provider this project already backs up to (named "offsite"). */
function data_used_provider(DatabaseInstance $instance, string $name = 'offsite'): StorageProvider
{
    $provider = databases_provider(test()->organization, ['name' => $name]);
    BackupSchedule::query()->create(['organization_id' => $instance->organization_id, 'database_instance_id' => $instance->id, 'storage_provider_id' => $provider->id,
        'name' => 'nightly', 'cron' => '0 4 * * *', 'encryption_mode' => 'cp', 'drill' => 'off', 'enabled' => true]);

    return $provider;
}

it('reads an existing container in the file\'s shape, without any password', function () {
    [$database, $instance] = data_existing('db', 'postgresql', ['memory_bytes' => 1024 ** 3, 'cpus' => 1.5, 'settings' => ['max_connections' => 200], 'require_tls' => true]);
    $provider = data_used_provider($instance);
    $instance->forceFill(['pitr_enabled' => true, 'pitr_storage_provider_id' => $provider->id, 'pitr_window_days' => 14, 'pitr_base_interval_days' => 2])->save();
    BackupSchedule::query()->create(['organization_id' => $instance->organization_id, 'database_instance_id' => $instance->id, 'storage_provider_id' => $provider->id,
        'name' => 'falak.yaml', 'cron' => '0 3 * * *', 'retention_count' => 14, 'encryption_mode' => 'cp', 'drill' => 'weekly', 'enabled' => true])
        ->databases()->sync([$database->id]);

    $current = $this->reconciler->current(data_scope($this->world));

    expect($current['units']['db'])->toBe([
        'type' => 'database', 'engine' => 'postgresql', 'version' => '17', 'server' => 'web-1', 'memory' => '1g', 'cpus' => 1.5, 'disk' => '10g',
        'settings' => ['max_connections' => 200], 'require_tls' => true, 'databases' => ['app'],
        'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'keep' => 14, 'drill' => 'weekly'],
        'pitr' => ['storage' => 'offsite', 'window_days' => 14, 'base_interval_days' => 2],
    ])
        ->and(json_encode($current))->not->toContain($instance->root_password)
        ->and(json_encode($current))->not->toContain((string) $instance->users()->first()->password);
});

it('plans a new unit as one create, and field changes with a restart note', function () {
    [, $instance] = data_existing('db', 'postgresql', ['memory_bytes' => 512 * 1024 ** 2]);
    $scope = data_scope($this->world);

    $changes = data_diff($scope, [
        'db' => ['type' => 'database', 'engine' => 'postgresql', 'version' => '17', 'memory' => '1024m', 'public' => false, 'settings' => ['max_connections' => 300], 'databases' => ['app', 'analytics']],
        'cache' => ['type' => 'redis', 'memory' => '256m'],
    ]);

    expect(data_table($changes->changes))->toEqualCanonicalizing([
        ['noop', '/units/db/type'],
        ['noop', '/units/db/engine'],
        ['noop', '/units/db/version'],
        ['update', '/units/db/memory'],
        ['noop', '/units/db/public'],
        ['create', '/units/db/settings/max_connections'],
        ['noop', '/units/db/databases/app'],
        ['create', '/units/db/databases/analytics'],
        ['create', '/units/cache'],
    ]);

    $memory = collect($changes->changes)->firstWhere('path', '/units/db/memory');
    $cache = collect($changes->changes)->firstWhere('path', '/units/cache');

    expect($memory->before)->toBe('512m')->and($memory->after)->toBe('1g')
        ->and($memory->note)->toBe(DatabasesReconciler::RESTART_NOTE)
        ->and($memory->toArray()['note'])->toBe(DatabasesReconciler::RESTART_NOTE)
        ->and($cache->after)->toBe(['type' => 'redis', 'memory' => '256m', 'engine' => 'redis'])
        ->and(collect($changes->changes)->firstWhere('path', '/units/db/version')->toArray())->not->toHaveKey('note');
});

it('creates a unit through the actions: container, databases, canvas service, PITR, backups and ownership', function () {
    $existing = data_existing('legacy')[1];
    $provider = data_used_provider($existing);

    $ops = data_apply($this->world, ['db' => [
        'type' => 'database', 'engine' => 'postgresql', 'version' => '17', 'memory' => '1g', 'cpus' => 2, 'disk' => '20g',
        'settings' => ['max_connections' => 150], 'allowed_sources' => ['203.0.113.7'], 'require_tls' => true,
        'databases' => ['shop', 'analytics'],
        'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'keep' => 14, 'databases' => ['shop']],
        'pitr' => ['storage' => 'offsite', 'window_days' => 10],
    ]]);

    $instance = data_instance('db');
    $schedule = BackupSchedule::query()->where('name', 'falak.yaml')->sole();
    $service = Service::query()->where('environment_id', $this->world->environment->id)->where('name', 'db')->sole();
    $managed = app(ManagedResources::class);
    $create = $this->world->deploy->agents->last('db.instance.create');

    expect($ops)->toBe([['create', '/units/db']])
        ->and($instance->only(['engine', 'version', 'memory_bytes', 'cpus', 'require_tls', 'allowed_sources', 'environment_id', 'status']))->toMatchArray([
            'version' => '17', 'memory_bytes' => 1024 ** 3, 'cpus' => 2.0, 'require_tls' => true, 'allowed_sources' => ['203.0.113.7/32'],
            'environment_id' => $this->world->environment->id, 'status' => InstanceStatus::Pending,
        ])
        ->and($instance->settings)->toBe(['max_connections' => 150])
        ->and($instance->databases()->pluck('name')->sort()->values()->all())->toBe(['analytics', 'shop'])
        ->and($instance->databases()->pluck('status')->unique()->all())->toBe([ResourceStatus::Pending])
        ->and($service->kind)->toBe(ServiceKind::Database)
        ->and($service->ref_id)->toBe($instance->databases()->where('name', 'shop')->value('id'))
        ->and($instance->pitr_enabled)->toBeTrue()->and($instance->pitr_storage_provider_id)->toBe($provider->id)->and($instance->pitr_window_days)->toBe(10)
        ->and($schedule->database_instance_id)->toBe($instance->id)
        ->and($schedule->databases()->pluck('name')->all())->toBe(['shop'])
        ->and($schedule->retention_count)->toBe(14)
        ->and($managed->createdByFile('database_instance', $instance->id))->toBeTrue()
        ->and($managed->managed('database_instance', $instance->id)->paths)->toContain('/memory', '/backups/cron', '/databases/analytics', '/pitr/window_days')
        ->and($managed->createdByFile('backup_schedule', $schedule->id))->toBeTrue()
        ->and($create['payload']['instance']['memory_bytes'] ?? $create['payload']['memory_bytes'] ?? null)->not->toBeNull();

    // The same file again: nothing to do (the container is still starting).
    expect(data_apply($this->world, ['db' => [
        'type' => 'database', 'engine' => 'postgresql', 'version' => '17', 'memory' => '1g', 'cpus' => 2, 'disk' => '20g',
        'settings' => ['max_connections' => 150], 'allowed_sources' => ['203.0.113.7/32'], 'require_tls' => true,
        'databases' => ['shop', 'analytics'],
        'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'keep' => 14, 'databases' => ['shop']],
        'pitr' => ['storage' => 'offsite', 'window_days' => 10],
    ]]))->toBe([]);

    // Once running, the extra database is created and the unit's user gets it.
    data_start($this->world);
    $user = $instance->users()->sole();

    expect($instance->refresh()->status)->toBe(InstanceStatus::Active)
        ->and($user->grants()->with('database')->get()->pluck('database.name')->sort()->values()->all())->toBe(['analytics', 'shop']);
});

it('creates a redis unit with its keyspace and no databases', function () {
    $ops = data_apply($this->world, ['cache' => ['type' => 'redis', 'engine' => 'valkey', 'memory' => '128m', 'settings' => ['eviction' => 'allkeys-lru']]]);
    $instance = data_instance('cache');

    expect($ops)->toBe([['create', '/units/cache']])
        ->and($instance->engine->value)->toBe('valkey')
        ->and($instance->databases()->pluck('name')->all())->toBe(['cache'])
        ->and($this->reconciler->current(data_scope($this->world))['units']['cache'])->not->toHaveKey('databases')
        ->and(data_apply($this->world, ['cache' => ['type' => 'redis', 'engine' => 'valkey', 'memory' => '128m', 'settings' => ['eviction' => 'allkeys-lru']]]))->toBe([]);
});

it('applies limits, settings and access in one update, and grows the data volume', function () {
    data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'disk' => '10g']]);
    data_start($this->world);
    $agents = $this->world->deploy->agents;
    $updates = count($agents->dispatched('db.instance.update'));

    $ops = data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'disk' => '20g', 'memory' => '768m', 'settings' => ['slow_query_ms' => 500], 'allowed_sources' => ['198.51.100.0/24']]]);
    $instance = data_instance('db');

    expect($ops)->toEqualCanonicalizing([
        ['update', '/units/db/disk'], ['update', '/units/db/memory'], ['create', '/units/db/settings/slow_query_ms'], ['create', '/units/db/allowed_sources'],
    ])
        ->and($instance->memory_bytes)->toBe(768 * 1024 ** 2)
        ->and($instance->settings)->toBe(['slow_query_ms' => 500])
        ->and($instance->allowed_sources)->toBe(['198.51.100.0/24'])
        ->and(count($agents->dispatched('db.instance.update')))->toBe($updates + 1)
        ->and($agents->last('volume.resize')['payload']['size_bytes'])->toBe(20 * 1024 ** 3);
});

it('adds backups and PITR to an adopted container and updates them field by field', function () {
    [, $instance] = data_existing();
    data_used_provider($instance);

    expect(data_apply($this->world, ['db' => ['type' => 'database', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite'], 'pitr' => ['storage' => 'offsite']]]))
        ->toEqualCanonicalizing([['create', '/units/db/backups'], ['create', '/units/db/pitr/enabled'], ['create', '/units/db/pitr/storage']]);

    $schedule = BackupSchedule::query()->where('name', 'falak.yaml')->sole();
    expect($schedule->enabled)->toBeTrue()->and($instance->refresh()->pitr_enabled)->toBeTrue()
        ->and(app(ManagedResources::class)->createdByFile('database_instance', $instance->id))->toBeFalse();

    $ops = data_apply($this->world, ['db' => ['type' => 'database', 'backups' => ['cron' => '30 2 * * *', 'storage' => 'offsite', 'keep_days' => 30], 'pitr' => ['storage' => 'offsite', 'window_days' => 21]]]);

    expect($ops)->toEqualCanonicalizing([['update', '/units/db/backups/cron'], ['create', '/units/db/backups/keep_days'], ['update', '/units/db/pitr/window_days']])
        ->and($schedule->refresh()->cron)->toBe('30 2 * * *')->and($schedule->retention_days)->toBe(30)
        ->and($instance->refresh()->pitr_window_days)->toBe(21);
});

it('refuses a storage provider the project doesn\'t use, at apply too (names only)', function () {
    databases_provider($this->organization, ['name' => 'offsite']);

    data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']]]);
})->throws(ValidationException::class, "can't be used by this project's file");

it('keeps what the file drops: databases, schedules and whole units are detached, never deleted', function () {
    [, $legacy] = data_existing('legacy');
    data_used_provider($legacy);
    data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'databases' => ['shop', 'extra'], 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite'], 'pitr' => ['storage' => 'offsite']]]);
    data_start($this->world);
    $instance = data_instance('db');
    $managed = app(ManagedResources::class);

    // A database dropped from the list stays; so do the schedule and PITR when the blocks go.
    expect(data_apply($this->world, ['db' => ['type' => 'database', 'databases' => ['shop']]]))
        ->toEqualCanonicalizing([['detach', '/units/db/databases/extra'], ['detach', '/units/db/backups'], ['detach', '/units/db/pitr']])
        ->and($instance->databases()->where('name', 'extra')->exists())->toBeTrue()
        ->and(BackupSchedule::query()->where('name', 'falak.yaml')->exists())->toBeTrue()
        ->and($instance->refresh()->pitr_enabled)->toBeTrue()
        ->and($managed->managed('database_instance', $instance->id)->under('/pitr'))->toBe([]);

    // The whole unit goes from the file: the container keeps running, unmanaged and flagged.
    expect(data_apply($this->world, []))->toBe([['detach', '/units/db']])
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Active)
        ->and($instance->status_message)->toContain('Detached from falak.yaml')
        ->and($managed->managed('database_instance', $instance->id)->any())->toBeFalse()
        ->and($this->world->deploy->agents->dispatched('db.instance.delete'))->toBe([])
        ->and($this->world->deploy->agents->dispatched('db.drop'))->toBe([]);

    // Not managed any more: a later plan leaves it alone.
    expect(data_apply($this->world, []))->toBe([]);
});

it('deletes only with allow_destroy, only what the file created, and keeps the data volume', function () {
    [, $adopted] = data_existing('legacy');
    data_apply($this->world, ['legacy' => ['type' => 'database', 'memory' => '512m'], 'db' => ['type' => 'database', 'engine' => 'postgresql', 'databases' => ['shop', 'extra']]]);
    data_start($this->world);
    $created = data_instance('db');

    // Without allow_destroy a dropped database is only detached.
    expect(data_table(data_diff(data_scope($this->world), ['legacy' => ['type' => 'database'], 'db' => ['type' => 'database', 'databases' => ['shop']]])->actionable()))
        ->toBe([['detach', '/units/db/databases/extra']]);

    $ops = data_apply($this->world, ['db' => ['type' => 'database', 'databases' => ['shop']]], allowDestroy: true);

    expect($ops)->toEqualCanonicalizing([['delete', '/units/db/databases/extra'], ['detach', '/units/legacy']])
        ->and($this->world->deploy->agents->last('db.drop')['payload']['name'] ?? null)->toBe('extra')
        ->and($adopted->refresh()->status)->toBe(InstanceStatus::Active);

    expect(data_apply($this->world, [], allowDestroy: true))->toBe([['delete', '/units/db']])
        ->and($created->refresh()->status)->toBe(InstanceStatus::Deleting)
        ->and($created->delete_volume)->toBeFalse();
});

it('refuses to apply an engine change or a move, even when approved', function (array $change) {
    data_existing();
    sites_server($this->organization->id, ['name' => 'web-2'], docker: true);

    data_apply($this->world, ['db' => ['type' => 'database', ...$change]]);
})->with([
    'engine' => [['engine' => 'mysql']],
    'server' => [['server' => 'web-2']],
])->throws(ValidationException::class);

it('runs a version change through UpgradeInstance', function () {
    config(['databases.digests.postgresql.18' => 'sha256:'.str_repeat('c', 64)]);
    [, $instance] = data_existing();

    data_apply($this->world, ['db' => ['type' => 'database', 'version' => '18']]);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Upgrading)
        ->and(DatabaseInstance::query()->where('upgrade_of', $instance->id)->value('version'))->toBe('18');
});

it('accepts a preview environment\'s scope without special cases', function () {
    $preview = Environment::query()->create(['organization_id' => $this->organization->id, 'project_id' => $this->world->environment->project_id,
        'name' => 'pr-7', 'slug' => 'pr-7', 'is_production' => false, 'is_preview' => true]);
    $this->world->environment = $preview;

    $ops = data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'mysql']]);
    $instance = data_instance('db');

    expect($ops)->toBe([['create', '/units/db']])
        ->and($instance->environment_id)->toBe($preview->id)
        ->and($instance->pitr_enabled)->toBeFalse()
        ->and(Service::query()->where('environment_id', $preview->id)->where('name', 'db')->exists())->toBeTrue();
});

it('validates versions, sizes, settings, servers, names and storage (names only)', function () {
    [, $instance] = data_existing('legacy');
    data_used_provider($instance, 'offsite');
    databases_provider($this->organization, ['name' => 'unused']);
    $other = Organization::factory()->create();
    databases_provider($other, ['name' => 'theirs']);
    sites_server($this->organization->id, ['name' => 'lonely'], docker: true);

    $resolved = ['units' => [
        'legacy' => ['type' => 'database', 'version' => '16'],
        'db' => ['type' => 'database', 'engine' => 'postgresql', 'version' => '9', 'memory' => '100m', 'disk' => '1536m', 'server' => 'lonely',
            'settings' => ['eviction' => 'allkeys-lru'], 'allowed_sources' => ['0.0.0.0/0'], 'databases' => ['postgres'],
            'backups' => ['cron' => '0 3 * * *', 'storage' => 'unused', 'drill_server' => 'lonely', 'databases' => ['other']],
            'pitr' => ['storage' => 'theirs', 'window_days' => 3, 'base_interval_days' => 5]],
        'cache' => ['type' => 'redis', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'drill_query' => 'SELECT 1']],
        'web' => ['type' => 'database'],
    ]];
    $issues = collect($this->reconciler->validate(data_scope($this->world), $resolved))->map(fn ($i) => [$i->code, $i->pointer])->all();

    expect($issues)->toEqualCanonicalizing([
        ['value.unsupported', '/units/legacy/version'],
        ['value.unsupported', '/units/db/version'],
        ['value.range', '/units/db/memory'],
        ['value.range', '/units/db/disk'],
        ['name.server', '/units/db/server'],
        ['value.unsupported', '/units/db/settings/eviction'],
        ['value.range', '/units/db/allowed_sources/0'],
        ['value.unsupported', '/units/db/databases/0'],
        ['name.storage', '/units/db/backups/storage'],
        ['name.server', '/units/db/backups/drill_server'],
        ['value.conflict', '/units/db/backups/databases/0'],
        ['name.storage', '/units/db/pitr/storage'],
        ['value.range', '/units/db/pitr/base_interval_days'],
        ['value.unsupported', '/units/cache/backups/drill_query'],
        ['value.conflict', '/units/web'],
    ]);

    // An admin allows "unused" on the config source: then it is fine.
    ConfigSource::query()->update(['allowed_credentials' => ['unused']]);
    $issues = collect($this->reconciler->validate(data_scope($this->world), $resolved))->map(fn ($i) => $i->pointer)->all();
    expect($issues)->not->toContain('/units/db/backups/storage')->toContain('/units/db/pitr/storage');
});

it('flags the risky changes of the contract', function (array $before, array $after, string $path, string $rule, bool $allowDestroy = false) {
    [, $instance] = data_existing();
    $provider = data_used_provider($instance);
    data_apply($this->world, ['db' => ['type' => 'database', ...$before]]);

    $plan = data_plan($this->world, $after === [] ? [] : ['db' => ['type' => 'database', ...$after]], $allowDestroy);

    expect(data_rule($plan, $path))->toBe($rule)->and($plan->isRisky())->toBeTrue();
})->with([
    'engine' => [[], ['engine' => 'mysql'], '/units/db/engine', 'database.engine'],
    'major version' => [[], ['version' => '18'], '/units/db/version', 'database.version'],
    'public on' => [[], ['public' => true], '/units/db/public', 'database.public'],
    'move' => [['server' => 'web-1'], ['server' => 'web-2'], '/units/db/server', 'database.move'],
    'disk shrink' => [['disk' => '10g'], ['disk' => '5g'], '/units/db/disk', 'volume.shrink'],
    'backups removed' => [['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']], ['memory' => '512m'], '/units/db/backups', 'database.backups_removed'],
    'backups disabled' => [['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']], ['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'enabled' => false]], '/units/db/backups/enabled', 'database.backups_disabled'],
    'pitr off' => [['pitr' => ['storage' => 'offsite']], ['pitr' => ['storage' => 'offsite', 'enabled' => false]], '/units/db/pitr/enabled', 'database.pitr_off'],
    'pitr removed' => [['pitr' => ['storage' => 'offsite']], ['memory' => '512m'], '/units/db/pitr', 'database.pitr_removed'],
    'pitr window shrinks' => [['pitr' => ['storage' => 'offsite', 'window_days' => 14]], ['pitr' => ['storage' => 'offsite', 'window_days' => 7]], '/units/db/pitr/window_days', 'database.pitr_window'],
    'unit removed' => [['memory' => '512m'], [], '/units/db', 'unit.remove_with_data'],
]);

it('flags a new public unit and a database dropped with allow_destroy', function () {
    $plan = data_plan($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'public' => true, 'allowed_sources' => ['203.0.113.7']]]);
    expect(data_rule($plan, '/units/db'))->toBe('database.public');

    data_apply($this->world, ['db' => ['type' => 'database', 'engine' => 'postgresql', 'databases' => ['shop', 'extra']]]);
    data_start($this->world);
    $plan = data_plan($this->world, ['db' => ['type' => 'database', 'databases' => ['shop']]], allowDestroy: true);

    expect(data_rule($plan, '/units/db/databases/extra'))->toBe('database.database_removed')
        ->and(collect($plan->changes)->firstWhere('path', '/units/db/databases/extra')->op)->toBe(ChangeOp::Delete);
});
