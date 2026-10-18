<?php

use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Application\Actions\CreateVolume;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\ServiceVolumes;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/*
| Security review fixes: compose aliases of other Docker volumes, busy and protected volumes, admin-only host paths and
| protection, database data volumes, moves that keep services stopped, usage reports confined to their server.
*/

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    $this->deployments = VolumesFakeDeployments::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = volumes_server($this->organization->id);
});

it('records a compose `name:` (and legacy external name) as external, so its stack can never delete the volume it points at', function () {
    $victim = volumes_volume($this->organization->id, $this->server, 'db', VolumeKind::Docker, ['protected' => true, 'docker_name' => 'shared-db']);
    $site = volumes_docker_site($this->organization->id, [$this->server], 'Evil');
    $yaml = "services:\n  app:\n    image: alpine\n    volumes: [steal:/steal, old:/old]\nvolumes:\n  steal:\n    name: shared-db\n  old:\n    external:\n      name: legacy-vol\n";

    app(ServiceVolumes::class)->composeDeployed($this->organization->id, $site->id, $this->server->id, 'evil', $yaml);

    $alias = Volume::query()->where('docker_name', 'shared-db')->whereKeyNot($victim->id)->sole();
    expect($alias->external())->toBeTrue()
        ->and(Volume::query()->where('docker_name', 'legacy-vol')->sole()->external())->toBeTrue();

    app(SiteFactory::class)->delete($site->id, [$alias->id]);

    // Forgotten, never deleted on the server; the protected volume it named is untouched.
    expect(Volume::query()->find($alias->id))->toBeNull()
        ->and($this->agents->dispatched('volume.delete'))->toBe([])
        ->and($victim->refresh()->status)->toBe(VolumeStatus::Active);
});

it('refuses to delete a Docker volume another row also names', function () {
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Docker);
    volumes_volume($this->organization->id, $this->server, 'alias', VolumeKind::Docker, ['docker_name' => 'falak-data']);

    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasErrors('volume');
    expect($this->agents->dispatched('volume.delete'))->toBe([]);
});

it('refuses to delete a volume while a backup, clone, move or restore uses it', function () {
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Docker);
    $operation = Operation::query()->create(['organization_id' => $this->organization->id, 'volume_id' => null, 'kind' => OperationKind::Clone,
        'status' => OperationStatus::Running, 'meta' => ['source_id' => $volume->id]]);

    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasErrors('volume');

    $operation->forceFill(['status' => OperationStatus::Succeeded])->save();
    $this->delete("/volumes/{$volume->id}", ['confirm' => 'data'])->assertSessionHasNoErrors();
});

it('validates the volumes picked for deletion before touching the servers', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $volume = volumes_volume($this->organization->id, $this->server, 'data', VolumeKind::Docker, ['protected' => true]);
    $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);

    expect(fn () => app(SiteFactory::class)->delete($site->id, [$volume->id]))->toThrow(ValidationException::class);
    expect($this->agents->dispatched())->toBe([])->and($site->fresh())->not->toBeNull();
});

it('lets only admins mount host paths and lift protection', function () {
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    $bind = volumes_volume($this->organization->id, $this->server, 'media', VolumeKind::Bind, ['host_path' => '/srv/data/media']);
    $protected = volumes_volume($this->organization->id, $this->server, 'keep', VolumeKind::Sized, ['protected' => true]);
    [$developer] = memberOf($this->organization, Role::Developer);

    $this->actingAs($developer)->post("/volumes/{$bind->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/media'])->assertSessionHasErrors('volume');
    $this->actingAs($developer)->patch("/volumes/{$protected->id}", ['protected' => false])->assertSessionHasErrors('protected');
    $this->actingAs($developer)->patch("/volumes/{$protected->id}", ['protected' => true])->assertSessionHasNoErrors();
    expect($protected->refresh()->protected)->toBeTrue();

    $this->actingAs($this->user)->patch("/volumes/{$protected->id}", ['protected' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post("/volumes/{$bind->id}/attachments", ['site_id' => $site->id, 'mount_path' => '/media'])->assertSessionHasNoErrors();
    expect($protected->refresh()->protected)->toBeFalse();
});

it('never clones, backs up or restores a database’s data volume outside the database', function () {
    $provider = volumes_provider($this->organization->id);
    $volume = volumes_volume($this->organization->id, $this->server, 'pgdata');
    $volume->attachments()->create(['attachable_type' => AttachableType::Database, 'attachable_id' => strtolower((string) Str::ulid()), 'mount_path' => '/var/lib/postgresql/data']);

    $this->post("/volumes/{$volume->id}/clone", ['server_id' => $this->server->id, 'name' => 'copy'])->assertSessionHasErrors('volume');
    $this->post("/volumes/{$volume->id}/backups", ['storage_provider_id' => $provider->id])->assertSessionHasErrors('volume');
    $this->post("/volumes/{$volume->id}/schedules", ['storage_provider_id' => $provider->id, 'cron' => '0 3 * * *'])->assertSessionHasErrors('volume');

    expect($this->agents->dispatched())->toBe([]);
});

it('keeps a moved volume’s services stopped from the archive, and brings them back where they were when the move fails', function () {
    Http::fake();
    $provider = volumes_provider($this->organization->id);
    $target = volumes_server($this->organization->id, 'app-2');
    $volume = volumes_volume($this->organization->id, $this->server);
    $site = volumes_docker_site($this->organization->id, [$this->server]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $target->id, 'role' => 'member', 'status' => 'ready']);
    $volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);

    $this->post("/volumes/{$volume->id}/move", ['server_id' => $target->id, 'storage_provider_id' => $provider->id, 'consistency' => 'none', 'confirm' => 'data'])
        ->assertSessionHasNoErrors();

    $archive = $this->agents->last('volume.archive');
    expect($archive['payload'])->toMatchArray(['consistency' => 'stop', 'keep_stopped' => true])
        ->and(volumes_schema_errors($archive))->toBe([]);

    $this->agents->succeed($archive['handle'], ['size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => 'cp', 'key_id' => $archive['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd']);
    $this->agents->fail($this->agents->last('volume.restore')['handle'], 'disk full');

    expect(Operation::query()->sole()->status)->toBe(OperationStatus::Failed)
        ->and($volume->refresh()->attachments()->count())->toBe(1)
        ->and($this->deployments->deployed)->toBe([['site' => $site->id, 'message' => 'Volume data could not be moved: back on its server']]);
});

it('takes usage reports only for the reporting server’s volumes', function () {
    $other = volumes_server($this->organization->id, 'app-2');
    $volume = volumes_volume($this->organization->id, $other);

    $handle = $this->agents->dispatch($this->server->id, 'volume.inventory', ['volumes' => []]);
    $this->agents->succeed($handle, ['volumes' => [['id' => $volume->id, 'kind' => 'sized', 'exists' => true, 'used_bytes' => 123]]]);

    expect($volume->refresh()->used_bytes)->toBeNull();
});

it('answers a lost race for a volume name with a validation error', function () {
    $create = app(CreateVolume::class);
    $first = $create->prepare($this->organization->id, $this->server->id, 'race', VolumeKind::Docker);
    $second = $create->prepare($this->organization->id, $this->server->id, 'race', VolumeKind::Docker);
    CreateVolume::save($first);

    expect(fn () => CreateVolume::save($second))->toThrow(ValidationException::class);
});
