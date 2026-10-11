<?php

use Falak\Config\Application\Planning\Planner;
use Falak\Config\Application\Runs\RollbackRestorer;
use Falak\Config\Contracts\ManagedResources;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Deployments\Contracts\Data\DeploymentSummary;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Servers\Domain\Models\Server;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Infrastructure\Config\VolumesReconciler;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../../Databases/tests/Feature/Config/support.php';
require_once __DIR__.'/../../Support/helpers.php';

// falak.yaml's `units.<app>.volumes` (Volumes' Reconcilable): current → desired → ChangeSet tables, apply through the
// module's actions, the never-delete rules, names-only storage, ownership and the dashboard's 422.

beforeEach(function () {
    $this->world = config_world(['runtime' => 'docker', 'framework' => 'docker', 'build_mode' => 'docker', 'php_version' => null, 'dockerfile' => 'Dockerfile']);
    $this->organization = $this->world->deploy->organization;
    $this->server = Server::query()->where('organization_id', $this->organization->id)->where('name', 'web-1')->sole();
    $this->site = $this->world->deploy->site;
    $this->reconciler = app(VolumesReconciler::class);
});

/** @param  array<string, mixed>  $web */
function volumes_apply(array $web, bool $allowDestroy = false): array
{
    return data_apply(test()->world, $web === [] ? [] : ['web' => ['type' => 'app', 'runtime' => 'docker', ...$web]], test()->reconciler, $allowDestroy);
}

/** A volume mounted into the site (the dashboard's way), with a schedule to "offsite" (so the project uses it). */
function volumes_existing(string $name = 'uploads', VolumeKind $kind = VolumeKind::Docker): Volume
{
    $volume = volumes_volume(test()->organization->id, test()->server, $name, $kind);
    $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => test()->site->id, 'mount_path' => '/srv/uploads', 'read_only' => false]);
    $provider = databases_provider(test()->organization, ['name' => 'offsite']);
    BackupSchedule::query()->create(['organization_id' => $volume->organization_id, 'volume_id' => $volume->id, 'storage_provider_id' => $provider->id,
        'cron' => '0 4 * * *', 'consistency' => 'none', 'encryption_mode' => 'cp', 'drill' => 'off', 'enabled' => true]);

    return $volume;
}

it('reads the docker and sized volumes mounted into a unit, not its shared paths', function () {
    volumes_existing();

    expect($this->reconciler->current(data_scope($this->world)))->toBe(['units' => ['web' => ['type' => 'app', 'volumes' => [
        'uploads' => ['mount' => '/srv/uploads', 'server' => 'web-1', 'backups' => ['cron' => '0 4 * * *', 'storage' => 'offsite', 'drill' => 'off']],
    ]]]]);
});

it('creates and mounts volumes with their backups, records ownership and redeploys nothing itself', function () {
    volumes_existing('legacy');
    $deployments = Deployment::query()->count();

    $ops = volumes_apply(['volumes' => [
        'data' => ['kind' => 'sized', 'size' => '2g', 'mount' => '/var/data/', 'protected' => true, 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'keep' => 7, 'consistency' => 'pause']],
        'cache' => ['mount' => '/cache', 'read_only' => true],
        'legacy' => ['mount' => '/srv/uploads'],
    ]]);

    $data = Volume::query()->where('name', 'data')->sole();
    $cache = Volume::query()->where('name', 'cache')->sole();
    $managed = app(ManagedResources::class);

    expect($ops)->toEqualCanonicalizing([['create', '/units/web/volumes/data'], ['create', '/units/web/volumes/cache']])
        ->and($data->only(['kind', 'size_limit_bytes', 'protected', 'server_id']))->toBe(['kind' => VolumeKind::Sized, 'size_limit_bytes' => 2 * 1024 ** 3, 'protected' => true, 'server_id' => $this->server->id])
        ->and($data->attachments()->sole()->only(['attachable_id', 'mount_path', 'read_only']))->toBe(['attachable_id' => $this->site->id, 'mount_path' => '/var/data', 'read_only' => false])
        ->and($cache->kind)->toBe(VolumeKind::Docker)->and($cache->attachments()->sole()->read_only)->toBeTrue()
        ->and($data->schedules()->sole()->only(['cron', 'retention_count']))->toBe(['cron' => '0 3 * * *', 'retention_count' => 7])
        ->and($managed->createdByFile('volume', $data->id))->toBeTrue()
        ->and($managed->managed('volume', $data->id)->paths)->toContain('/volumes/data/size', '/volumes/data/mount')
        ->and($managed->createdByFile('backup_schedule', $data->schedules()->sole()->id))->toBeTrue()
        ->and(Deployment::query()->count())->toBe($deployments)
        ->and($this->world->deploy->agents->last('volume.create')['payload']['volume']['id'] ?? null)->not->toBeNull();

    expect(volumes_apply(['volumes' => [
        'data' => ['kind' => 'sized', 'size' => '2048m', 'mount' => '/var/data', 'protected' => true, 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'keep' => 7, 'consistency' => 'pause']],
        'cache' => ['mount' => '/cache', 'read_only' => true],
        'legacy' => ['mount' => '/srv/uploads'],
    ]]))->toBe([]);
});

it('updates protection, size, mount and backups through the actions', function () {
    volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['kind' => 'sized', 'size' => '1g', 'mount' => '/data', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']]]]);
    $volume = Volume::query()->where('name', 'data')->sole();
    $volume->forceFill(['status' => VolumeStatus::Active])->save();

    $ops = volumes_apply(['volumes' => ['data' => ['kind' => 'sized', 'size' => '4g', 'mount' => '/srv/data', 'read_only' => true, 'protected' => true, 'backups' => ['cron' => '0 5 * * *', 'storage' => 'offsite', 'keep_days' => 30]]]]);

    expect($ops)->toEqualCanonicalizing([
        ['update', '/units/web/volumes/data/size'], ['update', '/units/web/volumes/data/mount'], ['update', '/units/web/volumes/data/read_only'],
        ['update', '/units/web/volumes/data/protected'], ['update', '/units/web/volumes/data/backups/cron'], ['create', '/units/web/volumes/data/backups/keep_days'],
    ])
        ->and($this->world->deploy->agents->last('volume.resize')['payload']['size_bytes'])->toBe(4 * 1024 ** 3)
        ->and($volume->refresh()->protected)->toBeTrue()
        ->and($volume->attachments()->sole()->only(['mount_path', 'read_only']))->toBe(['mount_path' => '/srv/data', 'read_only' => true])
        ->and($volume->schedules()->sole()->only(['cron', 'retention_days']))->toBe(['cron' => '0 5 * * *', 'retention_days' => 30]);
});

it('never deletes a volume the file drops: unmounted, kept with its data, flagged', function () {
    $adopted = volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['mount' => '/data'], 'legacy' => ['mount' => '/srv/uploads']]]);
    $created = Volume::query()->where('name', 'data')->sole();

    expect(volumes_apply(['volumes' => []]))->toEqualCanonicalizing([['detach', '/units/web/volumes/data'], ['detach', '/units/web/volumes/legacy']])
        ->and(Volume::query()->find($created->id)?->status)->not->toBe(VolumeStatus::Deleting)
        ->and($created->refresh()->status_message)->toContain('Detached from falak.yaml')
        ->and($created->attachments()->count())->toBe(0)
        ->and($adopted->refresh()->attachments()->count())->toBe(0)
        ->and(app(ManagedResources::class)->managed('volume', $created->id)->any())->toBeFalse()
        ->and($this->world->deploy->agents->dispatched('volume.delete'))->toBe([])
        ->and(volumes_apply(['volumes' => []]))->toBe([]);
});

/** The site's last deployment, as Deployments reports it (status, created at). */
function volumes_last_deployment(string $siteId, string $status, string $at): void
{
    app()->instance(DeploymentDirectory::class, new class($siteId, $status, $at) implements DeploymentDirectory
    {
        public function __construct(private string $siteId, private string $status, private string $at) {}

        public function currentForSites(array $siteIds): array
        {
            return [$this->siteId => new DeploymentSummary('d1', $this->siteId, 9, $this->status, null, null, null, null, null, new DateTimeImmutable($this->at), null, null)];
        }

        public function recentForSites(array $siteIds, int $limit = 20): array
        {
            return [];
        }

        public function liveCommit(string $siteId): ?string
        {
            return null;
        }
    });
    app()->forgetInstance(VolumesReconciler::class);
    test()->reconciler = app(VolumesReconciler::class);
}

it('deletes with allow_destroy only what the file created, once the unit runs without it; never a protected volume', function () {
    volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['mount' => '/data'], 'keep' => ['mount' => '/keep', 'protected' => true], 'legacy' => ['mount' => '/srv/uploads']]]);
    Volume::query()->update(['status' => VolumeStatus::Active]);
    $data = Volume::query()->where('name', 'data')->sole();

    // First: unmounted (the unit redeploys without it) and marked, never deleted while a release may mount it.
    expect(volumes_apply(['volumes' => ['keep' => ['mount' => '/keep', 'protected' => true]]], allowDestroy: true))
        ->toEqualCanonicalizing([['delete', '/units/web/volumes/data'], ['detach', '/units/web/volumes/legacy']])
        ->and($data->refresh()->status)->toBe(VolumeStatus::Active)
        ->and($data->attachments()->count())->toBe(0)
        ->and($data->options['config_delete']['unit'] ?? null)->toBe('web')
        ->and($this->world->deploy->agents->dispatched('volume.delete'))->toBe([]);

    // The unit hasn't run without it yet: nothing to delete.
    volumes_last_deployment($this->site->id, 'failed', now()->addMinute()->toIso8601String());
    expect(volumes_apply(['volumes' => ['keep' => ['mount' => '/keep', 'protected' => true]]], allowDestroy: true))->toBe([]);

    // Its redeploy succeeded: the next run with allow_destroy deletes it.
    volumes_last_deployment($this->site->id, 'succeeded', now()->addMinute()->toIso8601String());
    expect(volumes_apply(['volumes' => ['keep' => ['mount' => '/keep', 'protected' => true]]]))->toBe([])
        ->and(volumes_apply(['volumes' => ['keep' => ['mount' => '/keep', 'protected' => true]]], allowDestroy: true))->toBe([['delete', '/units/web/volumes/data']])
        ->and($data->refresh()->status)->toBe(VolumeStatus::Deleting)
        ->and(app(ManagedResources::class)->managed('volume', $data->id)->any())->toBeFalse();

    volumes_apply([], allowDestroy: true);
})->throws(ValidationException::class, 'protected');

it('mounts a volume a run unmounted to delete again when the file lists it again, never an empty new one', function () {
    volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['mount' => '/data'], 'legacy' => ['mount' => '/srv/uploads']]]);
    $data = Volume::query()->where('name', 'data')->sole();
    volumes_apply(['volumes' => ['legacy' => ['mount' => '/srv/uploads']]], allowDestroy: true);

    expect(volumes_apply(['volumes' => ['data' => ['mount' => '/data'], 'legacy' => ['mount' => '/srv/uploads']]]))->toBe([['create', '/units/web/volumes/data']])
        ->and(Volume::query()->where('name', 'like', '%data%')->count())->toBe(1)
        ->and($data->refresh()->attachments()->sole()->mount_path)->toBe('/data')
        ->and($data->options['config_delete'] ?? null)->toBeNull();
});

it('deploys the unit when a volume is unmounted from it', function () {
    volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['mount' => '/data'], 'legacy' => ['mount' => '/srv/uploads']]]);

    $plan = app(Planner::class)->plan(data_scope($this->world),
        ['units' => ['web' => ['type' => 'app', 'runtime' => 'docker', 'volumes' => ['legacy' => ['mount' => '/srv/uploads']]]], 'environments' => [$this->world->environment->slug => ['branch' => 'main', 'servers' => ['web-1']]]]);

    expect($plan->deploy)->toContain('web');
});

it('refuses to apply a kind change or a move', function (array $change) {
    volumes_apply(['volumes' => ['data' => ['mount' => '/data']]]);
    sites_server($this->organization->id, ['name' => 'web-2'], docker: true);

    volumes_apply(['volumes' => ['data' => ['mount' => '/data', ...$change]]]);
})->with([
    'kind' => [['kind' => 'sized', 'size' => '1g']],
    'server' => [['server' => 'web-2']],
])->throws(ValidationException::class);

it('flags the risky volume changes', function (array $before, ?array $after, string $path, string $rule) {
    volumes_existing('legacy');
    sites_server($this->organization->id, ['name' => 'web-2'], docker: true);
    volumes_apply(['volumes' => ['data' => ['kind' => 'sized', 'size' => '2g', 'mount' => '/data', ...$before]]]);
    $volumes = $after === null ? [] : ['data' => ['kind' => 'sized', 'size' => '2g', 'mount' => '/data', ...$after]];

    $plan = data_plan($this->world, ['web' => ['type' => 'app', 'runtime' => 'docker', 'volumes' => $volumes]]);

    expect(data_rule($plan, $path))->toBe($rule);
})->with([
    'removed' => [[], null, '/units/web/volumes/data', 'volume.delete'],
    'shrink' => [[], ['size' => '1g'], '/units/web/volumes/data/size', 'volume.shrink'],
    'move' => [['server' => 'web-1'], ['server' => 'web-2'], '/units/web/volumes/data/server', 'volume.move'],
    'backups removed' => [['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']], [], '/units/web/volumes/data/backups', 'volume.backups_removed'],
    'backups disabled' => [['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']], ['backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite', 'enabled' => false]], '/units/web/volumes/data/backups/enabled', 'volume.backups_disabled'],
]);

it('validates runtimes, sizes, mounts, servers and storage (names only)', function () {
    volumes_existing('legacy');
    databases_provider($this->organization, ['name' => 'unused']);
    sites_server($this->organization->id, ['name' => 'lonely'], docker: true);

    $issues = fn (array $units) => collect($this->reconciler->validate(data_scope($this->world), ['units' => $units]))->map(fn ($i) => [$i->code, $i->pointer])->all();

    expect($issues(['web' => ['type' => 'app', 'runtime' => 'frankenphp', 'volumes' => ['data' => ['mount' => '/data']]]]))
        ->toBe([['value.unsupported', '/units/web/volumes']])
        ->and($issues(['web' => ['type' => 'app', 'runtime' => 'docker', 'volumes' => [
            'a' => ['mount' => '/data', 'size' => '1g'],
            'b' => ['kind' => 'sized', 'size' => '1.5k', 'mount' => '/data'],
            'c' => ['mount' => '/etc'],
            'd' => ['mount' => '/d', 'server' => 'lonely', 'service' => 'x'],
            'legacy' => ['kind' => 'sized', 'size' => '1g', 'mount' => '/srv/uploads', 'backups' => ['cron' => '0 3 * * *', 'storage' => 'unused', 'drill_server' => 'nope']],
        ]]]))->toEqualCanonicalizing([
            ['value.unsupported', '/units/web/volumes/a/size'],
            ['value.range', '/units/web/volumes/b/size'],
            ['value.conflict', '/units/web/volumes/b/mount'],
            ['value.unsupported', '/units/web/volumes/c/mount'],
            ['value.unsupported', '/units/web/volumes/d/service'],
            ['name.server', '/units/web/volumes/d/server'],
            ['value.conflict', '/units/web/volumes/legacy/kind'],
            ['name.storage', '/units/web/volumes/legacy/backups/storage'],
            ['name.server', '/units/web/volumes/legacy/backups/drill_server'],
        ]);
});

it('answers 422 to dashboard edits of what the file manages, and shows the badge', function () {
    volumes_existing('legacy');
    volumes_apply(['volumes' => ['data' => ['kind' => 'sized', 'size' => '1g', 'mount' => '/data', 'protected' => true, 'backups' => ['cron' => '0 3 * * *', 'storage' => 'offsite']]]]);
    $volume = Volume::query()->where('name', 'data')->sole();
    $volume->forceFill(['status' => VolumeStatus::Active])->save();
    $schedule = $volume->schedules()->sole();
    $attachment = $volume->attachments()->sole();

    $this->patchJson("/volumes/{$volume->id}", ['protected' => false])->assertStatus(422)->assertJsonValidationErrors('protected');
    $this->postJson("/volumes/{$volume->id}/resize", ['size_bytes' => 2 * 1024 ** 3])->assertStatus(422)->assertJsonValidationErrors('size_bytes');
    $this->deleteJson("/volumes/{$volume->id}", ['confirm' => 'data'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->deleteJson("/volumes/attachments/{$attachment->id}")->assertStatus(422)->assertJsonValidationErrors('attachment');
    $this->putJson("/volumes/schedules/{$schedule->id}", ['storage_provider_id' => $schedule->storage_provider_id, 'cron' => '0 9 * * *'])->assertStatus(422)->assertJsonValidationErrors('cron');
    $this->deleteJson("/volumes/schedules/{$schedule->id}")->assertStatus(422);

    // Unmanaged fields stay editable (labels; the same managed values resubmitted).
    $this->patchJson("/volumes/{$volume->id}", ['protected' => true, 'labels' => ['team' => 'web']])->assertOk();

    $this->get("/volumes/{$volume->id}")->assertInertia(fn ($page) => $page->where('volume.managed.badge', 'Managed by falak.yaml @ aaaaaaa')
        ->where('schedules.0.managed.paths', ['/volumes/data/backups/cron', '/volumes/data/backups/storage']));
});

it('a rollback to a release made before a volume never unmounts it (risky changes are skipped)', function () {
    $file = fn (string $volumes) => "version: 1\nunits:\n  web:\n    type: app\n    runtime: docker\n    port: 8080\n{$volumes}environments:\n  production: { branch: main, servers: [web-1] }\n";
    $this->site->forceFill(['app_port' => 30001, 'container_port' => 8080])->save();
    $this->world->file($file(''));
    $this->world->push(config_sha('b'));
    $this->world->settle();
    $before = Release::query()->where('site_id', $this->site->id)->latest('id')->firstOrFail();

    $this->world->file($file("    volumes:\n      data: { mount: /data }\n"));
    $this->world->push(config_sha('c'));
    $this->world->settle();
    $volume = Volume::query()->where('name', 'data')->sole();

    $run = app(RollbackRestorer::class)->restore($this->world->environment->project_id, $this->world->environment->id, 'web', $before->id, config_sha('b'));

    expect($run->status)->toBe(RunStatus::Applied)
        ->and(collect($run->errors)->pluck('code')->all())->toContain('rollback.risky_skipped')
        ->and($volume->attachments()->count())->toBe(1)
        ->and(app(ManagedResources::class)->managed('volume', $volume->id)->any())->toBeTrue();
});
