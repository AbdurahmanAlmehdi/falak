<?php

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Application\Actions\RunVolumeBackup;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Application\Jobs\RefreshVolumeUsage;
use Falak\Volumes\Application\Jobs\RunDueVolumeBackups;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Contracts\VolumeMounts;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Events\VolumeAlmostFull;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    $this->deployments = VolumesFakeDeployments::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = volumes_server($this->organization->id);
});

/**
 * The agent's volume.archive result for $command (encrypted with its key).
 *
 * @param  array<string, mixed>  $command
 */
function volumes_archive_result(array $command, string $content = 'tar'): array
{
    return ['size_bytes' => 4096, 'sha256' => hash('sha256', $content), 'location' => 'https://falak-backups.s3.eu-central-1.amazonaws.com/x', 'uncompressed_bytes' => 10 * 1024 ** 2, 'files' => 12, 'duration_ms' => 900,
        'plaintext_sha256' => hash('sha256', "plain {$content}"), 'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'],
        'cipher' => 'aes-256-gcm', 'compression' => 'zstd'];
}

it('creates a sized volume on its server and activates it when volume.create succeeds', function () {
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'uploads', 'kind' => 'sized', 'size_bytes' => 5 * 1024 ** 3, 'labels' => ['team' => 'web']])
        ->assertSessionHasNoErrors();

    $volume = Volume::query()->sole();
    $command = $this->agents->last('volume.create');

    expect(volumes_schema_errors($command))->toBe([])
        ->and($command['payload'])->toBe(['volume' => ['id' => $volume->id, 'kind' => 'sized'], 'size_bytes' => 5 * 1024 ** 3,
            'labels' => ['falak.volume.id' => $volume->id, 'falak.volume.name' => 'uploads', 'team' => 'web']])
        ->and($volume->status)->toBe(VolumeStatus::Pending);

    $this->agents->succeed($command['handle'], ['path' => "/var/lib/falak/volumes/{$volume->id}", 'size_bytes' => 5 * 1024 ** 3, 'created' => true]);

    expect($volume->refresh()->status)->toBe(VolumeStatus::Active)
        ->and($volume->host_path)->toBe("/var/lib/falak/volumes/{$volume->id}");
});

it('validates new volumes: names, sizes, duplicates, bind paths for admins inside the allowlist', function () {
    config(['volumes.bind_allow' => ['/srv/data']]);
    volumes_volume($this->organization->id, $this->server, 'taken');

    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'Bad Name', 'kind' => 'docker'])->assertSessionHasErrors('name');
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'taken', 'kind' => 'docker'])->assertSessionHasErrors('name');
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'tiny', 'kind' => 'sized', 'size_bytes' => 1024])->assertSessionHasErrors('size_bytes');
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'etc', 'kind' => 'bind', 'host_path' => '/etc'])->assertSessionHasErrors('host_path');
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'esc', 'kind' => 'bind', 'host_path' => '/srv/data/../../etc'])->assertSessionHasErrors('host_path');
    $this->post('/volumes', ['server_id' => $this->server->id, 'name' => 'media', 'kind' => 'bind', 'host_path' => '/srv/data/media'])->assertSessionHasNoErrors();

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->post('/volumes', ['server_id' => $this->server->id, 'name' => 'media2', 'kind' => 'bind', 'host_path' => '/srv/data/m2'])->assertSessionHasErrors('kind');

    expect(Volume::query()->where('kind', VolumeKind::Bind)->pluck('host_path')->all())->toBe(['/srv/data/media']);
});

it('attaches a volume to a docker site and redeploys it; detaching redeploys too', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $volume = volumes_volume($this->organization->id, $this->server);

    $this->post("/volumes/{$volume->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/proc'])->assertSessionHasErrors('mount_path');
    $this->post("/volumes/{$volume->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/app/../etc'])->assertSessionHasErrors('mount_path');
    $this->post("/volumes/{$volume->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/app/storage/', 'read_only' => true])->assertSessionHasNoErrors();

    $attachment = Attachment::query()->sole();
    expect($attachment->mount_path)->toBe('/app/storage')
        ->and($this->deployments->deployed)->toBe([['site' => $site->id, 'message' => 'Volume data attached at /app/storage']])
        ->and(app(VolumeMounts::class)->forSite($site->id, $this->server->id)[0]->toPayload())
        ->toBe(['source' => "/var/lib/falak/volumes/{$volume->id}", 'target' => '/app/storage', 'read_only' => true]);

    $this->delete("/volumes/attachments/{$attachment->id}")->assertSessionHasNoErrors();

    expect(Attachment::query()->count())->toBe(0)->and($this->deployments->deployed)->toHaveCount(2);
});

it('refuses attachments across servers and to sites that cannot mount volumes', function () {
    $other = volumes_server($this->organization->id, 'app-2');
    $site = volumes_docker_site($this->organization->id, [$other]);
    $volume = volumes_volume($this->organization->id, $this->server);

    $this->post("/volumes/{$volume->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/data'])->assertSessionHasErrors('site_id');
    $site->forceFill(['runtime' => 'compose'])->save();
    $this->post("/volumes/{$volume->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/data'])->assertSessionHasErrors('site_id');

    expect(Attachment::query()->count())->toBe(0)->and($this->deployments->deployed)->toBe([]);
});

it('grows sized volumes only', function () {
    $volume = volumes_volume($this->organization->id, $this->server);
    $docker = volumes_volume($this->organization->id, $this->server, 'cache', VolumeKind::Docker);

    $this->post("/volumes/{$volume->id}/resize", ['size_bytes' => 5 * 1024 ** 3])->assertSessionHasErrors('size_bytes');
    $this->post("/volumes/{$docker->id}/resize", ['size_bytes' => 50 * 1024 ** 3])->assertSessionHasErrors('size_bytes');
    $this->post("/volumes/{$volume->id}/resize", ['size_bytes' => 20 * 1024 ** 3])->assertSessionHasNoErrors();
    $this->post("/volumes/{$volume->id}/resize", ['size_bytes' => 30 * 1024 ** 3])->assertSessionHasErrors('size_bytes'); // one at a time

    $command = $this->agents->last('volume.resize');
    expect(volumes_schema_errors($command))->toBe([])
        ->and($volume->refresh()->size_limit_bytes)->toBe(10 * 1024 ** 3);

    $this->agents->succeed($command['handle'], ['size_bytes' => 20 * 1024 ** 3, 'previous_bytes' => 10 * 1024 ** 3, 'grown' => true]);

    expect($volume->refresh()->size_limit_bytes)->toBe(20 * 1024 ** 3)
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded);
});

it('deletes a volume only with its name typed, never a protected or attached one', function () {
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Docker, ['protected' => true]);

    $this->delete("/volumes/{$volume->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasErrors('volume');

    $volume->forceFill(['protected' => false])->save();
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $attachment = $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);
    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasErrors('volume');

    $attachment->delete();
    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasNoErrors();

    $command = $this->agents->last('volume.delete');
    expect(volumes_schema_errors($command))->toBe([])
        ->and($command['payload'])->toBe(['volume' => ['id' => $volume->id, 'kind' => 'docker', 'name' => 'falak-data'], 'wait_s' => 0])
        ->and($volume->refresh()->status)->toBe(VolumeStatus::Deleting);

    $this->agents->succeed($command['handle'], ['deleted' => true, 'existed' => true]);
    expect(Volume::query()->find($volume->id))->toBeNull();
});

it('records a compose stack’s named volumes and attaches them to the services mounting them', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server], 'Stack');
    $yaml = <<<'YAML'
        services:
          app:
            image: n8nio/n8n
            volumes: ["app-data:/home/node/.n8n", "shared:/shared:ro"]
          db:
            image: postgres:17
            volumes:
              - type: volume
                source: pg-data
                target: /var/lib/postgresql/data
        volumes:
          app-data:
          pg-data:
            name: custom-pg
          shared:
            external: true
        YAML;

    app(ServiceVolumes::class)->composeDeployed($this->organization->id, $site->id, $this->server->id, 'stack', $yaml);
    app(ServiceVolumes::class)->composeDeployed($this->organization->id, $site->id, $this->server->id, 'stack', $yaml); // idempotent

    $volumes = Volume::query()->with('attachments')->orderBy('name')->get();
    expect($volumes->pluck('docker_name')->all())->toBe(['stack_app-data', 'custom-pg', 'shared'])
        ->and($volumes->every(fn (Volume $v) => $v->kind === VolumeKind::Docker && $v->composeSiteId() === $site->id))->toBeTrue()
        ->and($volumes[2]->external())->toBeTrue()
        ->and($volumes->flatMap->attachments->map(fn (Attachment $a) => [$a->service, $a->mount_path, $a->read_only])->sortBy(0)->values()->all())->toBe([
            ['app', '/home/node/.n8n', false],
            ['app', '/shared', true],
            ['db', '/var/lib/postgresql/data', false],
        ]);

    // The file dropped pg-data: its data stays, unattached.
    $dropped = "services:\n  app:\n    image: n8nio/n8n\n    volumes: [\"app-data:/home/node/.n8n\", \"shared:/shared:ro\"]\n  db:\n    image: postgres:17\nvolumes:\n  app-data:\n  shared:\n    external: true\n";
    app(ServiceVolumes::class)->composeDeployed($this->organization->id, $site->id, $this->server->id, 'stack', $dropped);
    expect(Volume::query()->count())->toBe(3)
        ->and(Volume::query()->where('docker_name', 'custom-pg')->sole()->attachments()->count())->toBe(0);
});

it('keeps a deleted service’s volumes unless picked, and never deletes protected ones', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server], 'Stack');
    $yaml = "services:\n  app:\n    image: nginx\n    volumes: [data:/data, logs:/logs]\nvolumes:\n  data:\n  logs:\n";
    app(ServiceVolumes::class)->composeDeployed($this->organization->id, $site->id, $this->server->id, 'stack', $yaml);
    [$data, $logs] = [Volume::query()->where('docker_name', 'stack_data')->sole(), Volume::query()->where('docker_name', 'stack_logs')->sole()];
    $data->forceFill(['protected' => true])->save();

    expect(fn () => app(SiteFactory::class)->delete($site->id, [$data->id]))->toThrow(ValidationException::class);
    expect($site->fresh())->not->toBeNull();

    app(SiteFactory::class)->delete($site->id, [$logs->id]);

    $delete = $this->agents->last('volume.delete');
    expect($site->fresh())->toBeNull()
        ->and(Attachment::query()->count())->toBe(0)
        ->and($data->refresh()->status)->toBe(VolumeStatus::Active)
        ->and($logs->refresh()->status)->toBe(VolumeStatus::Deleting)
        ->and($delete['payload'])->toBe(['volume' => ['id' => $logs->id, 'kind' => 'docker', 'name' => 'stack_logs'], 'wait_s' => 120])
        ->and($this->agents->dispatched('volume.delete'))->toHaveCount(1);
});

it('turns a classic site’s shared paths into shared_path volumes, kept in order', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server], 'Shop');
    $volumes = app(ServiceVolumes::class);

    $volumes->syncSharedPaths($this->organization->id, $site->id, [new SharedPath('storage'), new SharedPath('.env', 'file')]);
    $volumes->syncSharedPaths($this->organization->id, $site->id, [new SharedPath('public/uploads'), new SharedPath('storage'), new SharedPath('.env', 'file')]);

    expect(array_map(fn ($p) => $p->toArray(), app(VolumeMounts::class)->sharedPaths($site->id)))->toBe([
        ['path' => 'public/uploads', 'type' => 'directory'],
        ['path' => 'storage', 'type' => 'directory'],
        ['path' => '.env', 'type' => 'file'],
    ])
        ->and(Volume::query()->where('kind', VolumeKind::SharedPath)->pluck('host_path')->sort()->values()->all())
        ->toBe(["/srv/falak/sites/{$site->slug}/shared/.env", "/srv/falak/sites/{$site->slug}/shared/public/uploads", "/srv/falak/sites/{$site->slug}/shared/storage"]);

    $volumes->syncSharedPaths($this->organization->id, $site->id, [new SharedPath('storage')]);
    expect(Volume::query()->count())->toBe(1);
});

it('backs up on schedule through a presigned URL, prunes by retention and restores into a new volume', function () {
    Carbon::setTestNow('2026-10-07 02:59:30');
    Http::fake();
    $provider = volumes_provider($this->organization->id);
    $volume = volumes_volume($this->organization->id, $this->server);

    $this->post("/volumes/{$volume->id}/schedules", ['storage_provider_id' => $provider->id, 'cron' => 'nope'])->assertSessionHasErrors('cron');
    $this->post("/volumes/{$volume->id}/schedules", ['storage_provider_id' => $provider->id, 'cron' => '0 3 * * *', 'retention_count' => 2, 'consistency' => 'pause'])->assertSessionHasNoErrors();
    $schedule = BackupSchedule::query()->sole();

    foreach (['03:00:30', '03:00:31', '03:00:32'] as $i => $time) {
        Carbon::setTestNow('2026-10-0'.(7 + $i)." {$time}");
        $schedule->forceFill(['next_run_at' => now()->subSecond()])->save();
        (new RunDueVolumeBackups)->handle(app(RunVolumeBackup::class), app(CurrentOrganization::class));
        $command = $this->agents->last('volume.archive');
        expect(volumes_schema_errors($command))->toBe([])
            ->and($command['payload']['consistency'])->toBe('pause')
            ->and(json_encode($command['payload']))->not->toContain('super-secret-access-key-value');
        $this->agents->succeed($command['handle'], volumes_archive_result($command, "tar{$i}"));
    }

    $backups = VolumeBackup::query()->orderBy('created_at')->get();
    expect($backups->pluck('status')->all())->toBe([BackupStatus::Pruned, BackupStatus::Succeeded, BackupStatus::Succeeded])
        ->and($backups[0]->object_key)->toMatch('#^acme/volumes/app-1-[a-z0-9]{6}/data/2026/10/20261007T030030Z-'.$backups[0]->id.'\.tar\.zst\.fkb$#');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $backups[0]->object_key));

    // Restore the newest into a new volume and swap the services over to it.
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);

    $this->post("/volumes/backups/{$backups[2]->id}/restore", ['server_id' => $this->server->id, 'name' => 'data', 'swap' => true])->assertSessionHasErrors('name');
    $this->post("/volumes/backups/{$backups[2]->id}/restore", ['server_id' => $this->server->id, 'name' => 'data-restored', 'swap' => true])->assertSessionHasNoErrors();

    $restored = Volume::query()->where('name', 'data-restored')->sole();
    $command = $this->agents->last('volume.restore');
    expect(volumes_schema_errors($command))->toBe([])
        ->and($command['payload']['sha256'])->toBe(hash('sha256', 'tar2'))
        ->and($command['payload']['size_bytes'])->toBe(10 * 1024 ** 3)
        ->and($command['payload']['source']['url'])->toContain($backups[2]->object_key)
        ->and($restored->status)->toBe(VolumeStatus::Pending)
        ->and($this->deployments->deployed)->toBe([]);

    $this->agents->succeed($command['handle'], ['bytes' => 10 * 1024 ** 2, 'files' => 12, 'duration_ms' => 800]);

    expect($restored->refresh()->status)->toBe(VolumeStatus::Active)
        ->and($restored->attachments()->pluck('mount_path')->all())->toBe(['/data'])
        ->and($volume->attachments()->count())->toBe(0)
        ->and($this->deployments->deployed)->toHaveCount(1);

    Carbon::setTestNow();
});

it('moves a volume to another server: archive, restore there, hand the services over, delete the source', function () {
    Http::fake();
    $provider = volumes_provider($this->organization->id);
    $target = volumes_server($this->organization->id, 'app-2');
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Sized, ['protected' => true]);
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);

    $input = ['server_id' => $target->id, 'storage_provider_id' => $provider->id, 'confirm' => 'data'];
    $this->post("/volumes/{$volume->id}/move", [...$input, 'confirm' => 'x'])->assertSessionHasErrors('confirm');
    $this->post("/volumes/{$volume->id}/move", $input)->assertSessionHasErrors('server_id'); // the site does not run there

    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $target->id, 'role' => 'member', 'status' => 'ready']);
    $this->post("/volumes/{$volume->id}/move", $input)->assertSessionHasNoErrors();

    $archive = $this->agents->last('volume.archive');
    expect($archive['handle']->serverId)->toBe($this->server->id)->and($archive['payload']['consistency'])->toBe('stop');
    $this->agents->succeed($archive['handle'], volumes_archive_result($archive));

    $restore = $this->agents->last('volume.restore');
    $moved = Volume::query()->where('server_id', $target->id)->sole();
    expect($restore['handle']->serverId)->toBe($target->id)
        ->and(volumes_schema_errors($restore))->toBe([])
        ->and($moved->only(['name', 'protected', 'size_limit_bytes']))->toBe(['name' => 'data', 'protected' => true, 'size_limit_bytes' => 10 * 1024 ** 3]);

    $this->agents->succeed($restore['handle'], ['bytes' => 100, 'files' => 1]);

    $delete = $this->agents->last('volume.delete');
    expect($delete['handle']->serverId)->toBe($this->server->id)
        ->and($delete['payload']['volume']['id'])->toBe($volume->id)
        ->and($moved->refresh()->attachments()->count())->toBe(1)
        ->and($this->deployments->deployed)->toHaveCount(1)
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded)
        // The transfer copy is removed from storage once restored.
        ->and(VolumeBackup::query()->sole()->status)->toBe(BackupStatus::Pruned);
});

it('clones a volume on the same server with volume.clone', function () {
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Docker);

    $this->post("/volumes/{$volume->id}/clone", ['server_id' => $this->server->id, 'name' => 'data-copy', 'consistency' => 'pause'])->assertSessionHasNoErrors();

    $command = $this->agents->last('volume.clone');
    $copy = Volume::query()->where('name', 'data-copy')->sole();
    expect(volumes_schema_errors($command))->toBe([])
        ->and($command['payload']['target'])->toBe(['id' => $copy->id, 'kind' => 'docker', 'name' => 'falak-data-copy']);

    $this->agents->succeed($command['handle'], ['bytes' => 5, 'files' => 1]);
    expect($copy->refresh()->status)->toBe(VolumeStatus::Active);
});

it('browses volumes for admins only, audited, and downloads through storage', function () {
    Http::fake();
    $provider = volumes_provider($this->organization->id);
    $volume = volumes_volume($this->organization->id, $this->server);
    $this->agents->answer('volume.browse', fn (array $payload) => ['path' => $payload['path'], 'entries' => [
        ['name' => 'a.txt', 'path' => trim($payload['path'].'/a.txt', '/'), 'type' => 'file', 'size' => 3, 'mtime' => '2026-10-07T00:00:00Z'],
    ], 'total' => 1, 'truncated' => false]);

    $this->getJson("/volumes/{$volume->id}/browse?path=../etc")->assertUnprocessable();
    $this->getJson("/volumes/{$volume->id}/browse?path=uploads")->assertOk()->assertJsonPath('data.entries.0.path', 'uploads/a.txt');
    expect(AuditEntry::query()->where('action', 'volumes.browsed')->count())->toBe(1);

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->getJson("/volumes/{$volume->id}/browse")->assertForbidden();
    $this->actingAs($developer)->postJson("/volumes/{$volume->id}/downloads", ['path' => 'a.txt', 'storage_provider_id' => $provider->id])->assertForbidden();

    $this->actingAs($this->user)->postJson("/volumes/{$volume->id}/downloads", ['path' => 'uploads', 'storage_provider_id' => $provider->id])->assertStatus(202);
    $command = $this->agents->last('volume.download');
    $operation = Operation::query()->sole();
    expect(volumes_schema_errors($command))->toBe([])->and($command['payload']['max_bytes'])->toBe(1024 ** 3);

    $this->getJson("/volumes/operations/{$operation->id}/file")->assertUnprocessable();
    $this->agents->succeed($command['handle'], ['size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'format' => 'tar.zst', 'name' => 'uploads.tar.zst']);
    expect($this->getJson("/volumes/operations/{$operation->id}/file")->assertOk()->json('url'))->toContain($operation->meta('object_key'))
        ->and(AuditEntry::query()->whereIn('action', ['volumes.download_requested', 'volumes.downloaded'])->count())->toBe(2);
});

it('hides other organizations’ volumes', function () {
    $volume = volumes_volume($this->organization->id, $this->server);
    [$stranger] = memberOf(null, Role::Owner);

    $this->actingAs($stranger)->get("/volumes/{$volume->id}")->assertNotFound();
    $this->actingAs($stranger)->getJson("/volumes/{$volume->id}")->assertNotFound();
    $this->actingAs($stranger)->getJson("/volumes/{$volume->id}/browse")->assertNotFound();
    $this->actingAs($stranger)->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertNotFound();
    $this->actingAs($stranger)->get("/servers/{$this->server->id}/volumes")->assertNotFound();
    $this->actingAs($stranger)->post('/volumes', ['server_id' => $this->server->id, 'name' => 'x', 'kind' => 'docker'])->assertSessionHasErrors('server_id');

    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $mine = volumes_volume($stranger->current_organization_id, volumes_server($stranger->current_organization_id, 'theirs'));
    $this->actingAs($stranger)->post("/volumes/{$mine->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/data'])->assertSessionHasErrors('site_id');
});

it('reports usage periodically and alerts once when a volume crosses 85% of its limit', function () {
    Event::fake([VolumeAlmostFull::class]);
    $volume = volumes_volume($this->organization->id, $this->server);

    (new RefreshVolumeUsage)->handle(app(AgentCommands::class), app(SiteDirectory::class));
    $command = $this->agents->last('volume.inventory');
    expect(volumes_schema_errors($command))->toBe([]);

    $report = fn (int $used) => ['volumes' => [['id' => $volume->id, 'kind' => 'sized', 'exists' => true, 'used_bytes' => $used, 'size_bytes' => 10 * 1024 ** 3]]];
    $this->agents->succeed($command['handle'], $report(9 * 1024 ** 3));
    expect($volume->refresh()->used_bytes)->toBe(9 * 1024 ** 3);

    foreach ([9 * 1024 ** 3 + 1, 1024 ** 3, 9 * 1024 ** 3] as $used) {
        $handle = $this->agents->dispatch($this->server->id, 'volume.inventory', ['volumes' => [$volume->ref()]]);
        $this->agents->succeed($handle, $report($used));
    }

    Event::assertDispatchedTimes(VolumeAlmostFull::class, 2);
});

it('lists a service’s volumes and the ones it could mount for its settings', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $attached = volumes_volume($this->organization->id, $this->server, 'attached');
    $attached->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);
    $free = volumes_volume($this->organization->id, $this->server, 'free', VolumeKind::Docker);

    $this->getJson("/sites/{$site->id}/volumes")->assertOk()
        ->assertJsonPath('data.attachable', true)
        ->assertJsonPath('data.volumes.0.id', $attached->id)
        ->assertJsonPath('data.available.0.id', $free->id);
});

it('renders the server, project and volume pages', function () {
    $volume = volumes_volume($this->organization->id, $this->server);

    $this->get("/servers/{$this->server->id}/volumes")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Volumes/Server', false)->where('volumes.0.id', $volume->id));
    $this->get("/volumes/{$volume->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Volumes/Show', false)->where('volume.name', 'data')->where('can.browse', true));
    // The canvas panel's volume layer reads the same props as JSON.
    $this->getJson("/volumes/{$volume->id}")->assertOk()
        ->assertJsonPath('data.volume.id', $volume->id)
        ->assertJsonPath('data.can.manage', true)
        ->assertJsonStructure(['data' => ['backups', 'schedules', 'operations', 'storage_providers', 'servers', 'attachable_sites', 'browsable', 'download_max_bytes']]);
});
