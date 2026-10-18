<?php

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Restore;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Edge\Contracts\Data\DnsCheckResult;
use Falak\Edge\Contracts\DnsCheck;
use Falak\Edge\Contracts\DnsStatus;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Domain\Models\Domain;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\Role;
use Falak\Recovery\Application\Jobs\AdvanceSiteMoves;
use Falak\Recovery\Application\SiteMoveRunner;
use Falak\Recovery\Domain\Models\SiteMove;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Volumes/tests/Support/helpers.php';

/** The deploys a move triggers: a queued deployment row each, settled by the test. */
final class SiteMoveFakeDeploys implements DeploymentTrigger
{
    /** @var list<string> */
    public array $sites = [];

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        $this->sites[] = $siteId;

        return projects_deployment(Site::query()->findOrFail($siteId), 'queued', ['commit_message' => $message])->id;
    }
}

/** Live DNS: every name points at the servers listed in $pointing (by id), or nowhere. */
final class SiteMoveFakeDns implements DnsCheck
{
    /** @var list<string> */
    public array $pointing = [];

    public function check(string $organizationId, string $name, array $serverIds, ?string $siteId = null, ?string $label = null, bool $probeTls = false): DnsCheckResult
    {
        $matched = array_values(array_intersect($serverIds, $this->pointing));

        return new DnsCheckResult($name, $matched !== [] ? DnsStatus::cases()[0] : DnsStatus::cases()[count(DnsStatus::cases()) - 1], '', [], [], [], $matched, [], null, new DateTimeImmutable);
    }
}

/** DomainRecords with fixed answers (managed Cloudflare records without a zone to talk to). */
final class SiteMoveFakeDomains implements DomainRecords
{
    /** @param list<array{site_id: string, name: string, managed: bool, status: ?string}> $records */
    public function __construct(public array $records = [], public bool $failRename = false) {}

    public function forSites(array $siteIds): array
    {
        return array_values(array_filter($this->records, fn (array $record) => in_array($record['site_id'], $siteIds, true)));
    }

    public function followServer(string $siteId, string $fromIpv4, string $toIpv4): array
    {
        if ($this->failRename) {
            throw new RuntimeException('edge is down');
        }

        return [];
    }
}

beforeEach(function () {
    Http::fake();
    databases_fake_dns();
    Carbon::setTestNow('2026-10-18 12:00:00');
    $this->agents = FakeAgentGateway::install();
    [$this->admin, $this->organization] = actingAsMember(Role::Admin);
    $this->old = databases_server($this->organization, attributes: ['name' => 'app-old', 'ipv4' => '203.0.113.5']);
    $this->target = databases_server($this->organization, attributes: ['name' => 'app-new', 'ipv4' => '198.51.100.7']);
    $this->provider = databases_provider($this->organization);
    $this->deploys = new SiteMoveFakeDeploys;
    app()->instance(DeploymentTrigger::class, $this->deploys);
    $this->dns = new SiteMoveFakeDns;
    app()->instance(DnsCheck::class, $this->dns);
});

afterEach(fn () => Carbon::setTestNow());

function site_move_advance(): void
{
    dispatch_sync(new AdvanceSiteMoves);
}

/** @return array<string, mixed> */
function site_move_step(SiteMove $move, string $key): array
{
    return $move->refresh()->step($key) ?? [];
}

/** Every queued deployment of the site succeeds. */
function site_move_deployed(Site $site): void
{
    Deployment::query()->where('site_id', $site->id)->where('status', 'queued')->update(['status' => 'succeeded']);
}

/** @return list<string> */
function site_move_targets(Site $site, ?TargetRole $role = null): array
{
    return SiteTarget::query()->where('site_id', $site->id)->when($role !== null, fn ($q) => $q->where('role', $role))->orderBy('server_id')->pluck('server_id')->all();
}

/** The agent's db.backup result for $command (encrypted with its key). */
function site_move_backup_result(array $command): array
{
    return ['size_bytes' => 4000, 'sha256' => hash('sha256', 'dump'), 'location' => 's3://falak-backups/x', 'duration_ms' => 4200,
        'plaintext_sha256' => hash('sha256', 'plain dump'), 'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'],
        'cipher' => 'aes-256-gcm', 'compression' => 'zstd'];
}

/** The agent's volume.archive result for $command. */
function site_move_archive_result(array $command): array
{
    return ['size_bytes' => 4096, 'sha256' => hash('sha256', 'tar'), 'location' => 'https://falak-backups.s3.eu-central-1.amazonaws.com/x', 'uncompressed_bytes' => 10 * 1024 ** 2, 'files' => 12, 'duration_ms' => 900,
        'plaintext_sha256' => hash('sha256', 'plain tar'), 'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'],
        'cipher' => 'aes-256-gcm', 'compression' => 'zstd'];
}

/** A docker site on the old server, deployed once, with a PostgreSQL container only it uses and a volume. */
function site_move_stateful(object $test): Site
{
    $site = volumes_docker_site($test->organization->id, [$test->old], 'Shop');
    projects_deployment($site, 'succeeded', ['created_at' => now()->subDay()]);
    $test->instance = databases_instance($test->organization, 'postgresql', $test->old, ['name' => 'main']);
    $test->db = databases_active_db($test->instance, 'shop');
    $test->db->forceFill(['site_id' => $site->id])->save();
    $test->volume = volumes_volume($test->organization->id, $test->old, 'uploads', VolumeKind::Docker);
    $test->volume->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $site->id, 'mount_path' => '/data']);

    return $site;
}

it('plans a move without changing anything', function () {
    $site = site_move_stateful($this);

    $plan = $this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->assertOk()->json('data');

    expect($plan['from']['name'])->toBe('app-old')
        ->and($plan['target']['name'])->toBe('app-new')
        ->and($plan['blocking'])->toBe([])
        ->and($plan['databases'][0])->toMatchArray(['name' => 'main', 'move' => true])
        ->and($plan['volumes'][0]['name'])->toBe('uploads')
        ->and($plan['storage_provider_id'])->toBe($this->provider->id)
        ->and($plan['downtime'])->toContain('not carried over');

    expect(site_move_targets($site))->toBe([$this->old->id])
        ->and(SiteMove::query()->count())->toBe(0);
    $this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->old->id])->assertJsonValidationErrors('server_id');

    // A viewer can't move sites.
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertForbidden();
});

it('moves a site with its database and volume: add the server, back up and restore, move the volume, cut over, clean up', function () {
    $site = site_move_stateful($this);
    $generated = Domain::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'shop.203-0-113-5.sslip.io', 'is_primary' => true, 'www_redirect' => 'none', 'tls_mode' => 'auto']);
    Domain::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'shop.example.com', 'is_primary' => false, 'www_redirect' => 'none', 'tls_mode' => 'auto']);

    $move = $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id, 'storage_provider_id' => $this->provider->id])->assertCreated()->json('data');
    $move = SiteMove::query()->findOrFail($move['id']);

    // 1. Checked; 2. the new server joins as a member (the old one stays leader), being prepared.
    expect(site_move_step($move, 'check')['status'])->toBe('succeeded')
        ->and(site_move_step($move, 'add_target')['status'])->toBe('running')
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->old->id])
        ->and(site_move_targets($site))->toContain($this->target->id);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'recovery.site_move_started', 'subject_id' => $site->id]);

    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    expect($this->deploys->sites)->toBe([$site->id]);
    site_move_deployed($site);
    site_move_advance();

    // 3. Databases: a fresh backup on the old server, …
    expect(site_move_step($move, 'add_target')['status'])->toBe('succeeded');
    $backup = $this->agents->last('db.backup', $this->old->id);
    $this->agents->succeed($backup['handle'], site_move_backup_result($backup));
    site_move_advance();

    // … the same instance recreated on the new server, …
    expect($this->instance->refresh()->server_id)->toBe($this->target->id);
    foreach (['db.instance.create', 'db.create'] as $type) {
        foreach ($this->agents->dispatched($type, $this->target->id) as $command) {
            $this->agents->succeed($command['handle']);
        }
    }
    site_move_advance();

    // … and that backup restored into it.
    $restore = $this->agents->last('db.restore', $this->target->id);
    expect($restore['payload']['database'])->toBe('shop')
        ->and(Backup::query()->sole()->id)->toBe(collect(site_move_step($move, 'databases')['items'])->first()['backups'][0] ?? null);
    $this->agents->succeed($restore['handle'], ['bytes' => 100, 'duration_ms' => 50]);
    site_move_advance();
    expect(site_move_step($move, 'databases')['status'])->toBe('succeeded');

    // 4. The volume moves (services stopped from the snapshot), handed over and its source deleted.
    $archive = $this->agents->last('volume.archive', $this->old->id);
    expect($archive['payload']['keep_stopped'] ?? false)->toBeTrue();
    $this->agents->succeed($archive['handle'], site_move_archive_result($archive));
    $this->agents->succeed($this->agents->last('volume.restore', $this->target->id)['handle'], ['bytes' => 100, 'files' => 1]);
    expect($this->agents->last('volume.delete', $this->old->id)['payload']['volume']['id'])->toBe($this->volume->id);
    site_move_deployed($site);
    site_move_advance();
    expect(site_move_step($move, 'volumes')['status'])->toBe('succeeded');

    // 5. Cutover: the new server leads alone, a redeploy on the moved data; the generated name follows the IP.
    expect(site_move_targets($site))->toBe([$this->target->id])
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->target->id])
        ->and(Domain::query()->find($generated->id))->toBeNull()
        ->and(Domain::query()->where('name', 'shop.198-51-100-7.sslip.io')->value('is_primary'))->toBeTrue();
    site_move_deployed($site);
    site_move_advance();

    $cutover = site_move_step($move, 'cutover');
    expect($cutover['status'])->toBe('succeeded')
        ->and(collect($cutover['items'])->firstWhere('id', 'shop.example.com'))->toMatchArray(['state' => 'manual', 'kind' => 'manual'])
        ->and(collect($cutover['items'])->firstWhere('id', 'shop.example.com')['message'])->toContain('198.51.100.7')
        ->and(collect($cutover['items'])->firstWhere('id', 'shop.198-51-100-7.sslip.io')['state'])->toBe('succeeded');

    // 6. Clean up: the old container goes, its data volume and the backup stay.
    $leftover = $this->agents->last('db.instance.delete', $this->old->id);
    expect($leftover['payload'])->toBe(['id' => $this->instance->id])
        ->and($this->instance->refresh()->server_id)->toBe($this->target->id)
        ->and(Backup::query()->count())->toBe(1)
        ->and($move->refresh()->status)->toBe('succeeded');
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'recovery.site_move_succeeded', 'subject_id' => $site->id]);

    $this->getJson("/site-moves/{$move->id}")->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('stops before the cutover with the old server still serving, and cancels cleanly', function () {
    $site = site_move_stateful($this);
    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();

    // The deploy to the new server fails: the move stops there.
    Deployment::query()->where('site_id', $site->id)->where('status', 'queued')->update(['status' => 'failed', 'error' => 'image pull failed']);
    site_move_advance();

    expect($move->refresh()->status)->toBe('failed')
        ->and(site_move_step($move, 'add_target')['items'][0])->toMatchArray(['state' => 'failed', 'message' => 'image pull failed'])
        ->and(site_move_step($move, 'databases')['status'])->toBe('pending')
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->old->id])
        ->and($this->instance->refresh()->server_id)->toBe($this->old->id);
    $this->agents->assertNothingDispatched('db.backup');

    // A second move waits for this one.
    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertJsonValidationErrors('server_id');

    // Cancel: the new server leaves the site again.
    $this->postJson("/site-moves/{$move->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(site_move_targets($site))->toBe([$this->old->id]);
});

it('stops when the database backup fails: nothing moved, retry picks it up', function () {
    $site = site_move_stateful($this);
    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();

    $this->agents->fail($this->agents->last('db.backup', $this->old->id)['handle'], 'disk full');
    site_move_advance();

    expect($move->refresh()->status)->toBe('failed')
        ->and(site_move_step($move, 'databases')['items'][0]['message'])->toContain('disk full')
        ->and($this->instance->refresh()->server_id)->toBe($this->old->id)
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->old->id]);
    $this->agents->assertNothingDispatched('db.instance.create');
    $this->agents->assertNothingDispatched('volume.archive');

    $this->postJson("/site-moves/{$move->id}/steps/databases/retry")->assertOk()->assertJsonPath('data.status', 'running');
    expect($this->agents->dispatched('db.backup', $this->old->id))->toHaveCount(2);
});

it('leaves a database other sites use where it is', function () {
    $site = site_move_stateful($this);
    $other = volumes_docker_site($this->organization->id, [$this->old], 'Blog');
    databases_active_db($this->instance, 'blog')->forceFill(['site_id' => $other->id])->save();

    $plan = $this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->json('data');
    expect($plan['databases'][0])->toMatchArray(['move' => false, 'reason' => 'other sites use it too'])
        ->and($plan['problems'])->toContain('main is shared (other sites use it too): it stays on app-old — move it separately.');

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();

    expect(site_move_step($move, 'databases'))->toMatchArray(['status' => 'succeeded'])
        ->and(site_move_step($move, 'databases')['items'][0]['state'])->toBe('skipped')
        ->and($this->instance->refresh()->server_id)->toBe($this->old->id);
    $this->agents->assertNothingDispatched('db.backup');
});

it('moves a compose stack’s volume as a copy the stack switches to at the cutover, then deletes the source', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Stack');
    $site->forceFill(['runtime' => 'compose', 'build_mode' => 'docker', 'docker_image' => null])->save();
    projects_deployment($site, 'succeeded', ['created_at' => now()->subDay()]);
    $volume = volumes_volume($this->organization->id, $this->old, "{$site->slug}-data", VolumeKind::Docker, [
        'docker_name' => "{$site->slug}_data", 'options' => ['compose' => ['site_id' => $site->id, 'key' => 'data'], 'external' => false],
    ]);
    $volume->attachments()->create(['attachable_type' => AttachableType::ComposeService, 'attachable_id' => $site->id, 'service' => 'db', 'mount_path' => '/var/lib/data']);

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();

    // No deploy to the new server yet: the stack's first `up` there must find its volume.
    expect(site_move_step($move, 'add_target')['status'])->toBe('succeeded')
        ->and($this->deploys->sites)->toBe([]);

    $archive = $this->agents->last('volume.archive', $this->old->id);
    $this->agents->succeed($archive['handle'], site_move_archive_result($archive));
    $restore = $this->agents->last('volume.restore', $this->target->id);
    $copy = Volume::query()->where('server_id', $this->target->id)->sole();
    expect($copy->docker_name)->toBe("{$site->slug}_data")
        ->and($copy->composeSiteId())->toBe($site->id);
    $this->agents->succeed($restore['handle'], ['bytes' => 100, 'files' => 1]);

    // Nothing handed over or deleted yet: the stack still mounts the source on the old server.
    expect($volume->refresh()->attachments()->count())->toBe(1)
        ->and($this->deploys->sites)->toBe([]);
    $this->agents->assertNothingDispatched('volume.delete');
    site_move_advance();

    // Cutover: the new server alone, the stack deployed there; then the source goes.
    expect(site_move_targets($site))->toBe([$this->target->id])->and($this->deploys->sites)->toBe([$site->id]);
    site_move_deployed($site);
    site_move_advance();

    expect($move->refresh()->status)->toBe('succeeded')
        ->and($this->agents->last('volume.delete', $this->old->id)['payload']['volume']['id'])->toBe($volume->id)
        ->and($volume->refresh()->attachments()->count())->toBe(0);
});

it('treats a domain in a managed zone without a record as one to move by hand', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    projects_deployment($site, 'succeeded', ['created_at' => now()->subDay()]);
    app()->instance(DomainRecords::class, new SiteMoveFakeDomains([
        ['site_id' => $site->id, 'name' => 'new.example.org', 'managed' => true, 'status' => null],
    ]));

    expect($this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->json('data.domains.0.kind'))->toBe('manual');

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();

    // The old server stays a member until the name points at the new one.
    expect(site_move_targets($site, TargetRole::Leader))->toBe([$this->target->id])
        ->and(site_move_targets($site))->toContain($this->old->id)
        ->and(collect(site_move_step($move, 'cutover')['items'])->firstWhere('id', 'new.example.org'))->toMatchArray(['state' => 'manual', 'kind' => 'manual'])
        ->and($move->refresh()->status)->toBe('running');

    $this->dns->pointing = [$this->target->id];
    site_move_advance();
    expect($move->refresh()->status)->toBe('succeeded')->and(site_move_targets($site))->toBe([$this->target->id]);
});

it('keeps the old server serving a stateless site until its DNS moves; managed records move on their own', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    projects_deployment($site, 'succeeded', ['created_at' => now()->subDay()]);
    app()->instance(DomainRecords::class, new SiteMoveFakeDomains([
        ['site_id' => $site->id, 'name' => 'web.example.com', 'managed' => false, 'status' => null],
        ['site_id' => $site->id, 'name' => 'cf.example.org', 'managed' => true, 'status' => 'synced'],
    ]));

    $plan = $this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->json('data');
    expect(collect($plan['domains'])->pluck('kind', 'name')->all())->toBe(['web.example.com' => 'manual', 'cf.example.org' => 'managed'])
        ->and($plan['stateful'])->toBeFalse()
        ->and($plan['downtime'])->toStartWith('None');

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();

    // Cutover: the new server leads, the old one stays a member while web.example.com points at it.
    $cutover = site_move_step($move, 'cutover');
    expect(site_move_targets($site, TargetRole::Leader))->toBe([$this->target->id])
        ->and(site_move_targets($site))->toContain($this->old->id)
        ->and(collect($cutover['items'])->firstWhere('id', 'cf.example.org'))->toMatchArray(['state' => 'succeeded', 'kind' => 'managed'])
        ->and(collect($cutover['items'])->firstWhere('id', 'web.example.com'))->toMatchArray(['state' => 'manual', 'kind' => 'manual'])
        ->and(site_move_step($move, 'cleanup')['items'][0]['message'])->toContain('web.example.com still points at app-old')
        ->and($move->refresh()->status)->toBe('running');

    // DNS moved: the old server leaves.
    $this->dns->pointing = [$this->target->id];
    site_move_advance();
    expect($move->refresh()->status)->toBe('succeeded')
        ->and(site_move_targets($site))->toBe([$this->target->id]);
});

it('moves all sites of a server one after another', function () {
    $first = volumes_docker_site($this->organization->id, [$this->old], 'One');
    $second = volumes_docker_site($this->organization->id, [$this->old], 'Two');
    projects_deployment($first, 'succeeded', ['created_at' => now()->subDay()]);
    projects_deployment($second, 'succeeded', ['created_at' => now()->subDay()]);

    $this->postJson("/servers/{$this->old->id}/move-sites", ['server_id' => $this->target->id])->assertCreated()->assertJsonCount(2, 'data');
    expect(SiteMove::query()->orderBy('created_at')->orderBy('id')->pluck('status')->all())->toBe(['running', 'queued']);

    $one = SiteMove::query()->where('site_id', $first->id)->sole();
    expect($this->deploys->sites)->toBe([$first->id]);
    site_move_deployed($first);
    site_move_advance();

    // Stateless, no DNS: the first one is done once deployed there, the second starts.
    expect($one->refresh()->status)->toBe('succeeded')
        ->and(SiteMove::query()->where('site_id', $second->id)->value('status'))->toBe('running')
        ->and($this->deploys->sites)->toBe([$first->id, $second->id]);
    site_move_deployed($second);
    site_move_advance();

    expect(SiteMove::query()->pluck('status')->unique()->all())->toBe(['succeeded'])
        ->and(Server::query()->find($this->old->id))->not->toBeNull()
        ->and(site_move_targets($second))->toBe([$this->target->id]);
    $this->getJson("/servers/{$this->old->id}/site-moves")->assertOk()->assertJsonCount(2, 'data.moves');
});

/** A stateful move driven up to its database restore: backed up, relocated, the container and database created. */
function site_move_until_restore(object $test, Site $site): SiteMove
{
    $test->postJson("/sites/{$site->id}/move", ['server_id' => $test->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $test->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();
    $backup = $test->agents->last('db.backup', $test->old->id);
    $test->agents->succeed($backup['handle'], site_move_backup_result($backup));
    site_move_advance();

    foreach (['db.instance.create', 'db.create'] as $type) {
        foreach ($test->agents->dispatched($type, $test->target->id) as $command) {
            $test->agents->succeed($command['handle']);
        }
    }

    return $move;
}

it('waits for a managed domain’s record without removing the old server a manual domain still needs', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    projects_deployment($site, 'succeeded', ['created_at' => now()->subDay()]);
    $domains = new SiteMoveFakeDomains([
        ['site_id' => $site->id, 'name' => 'web.example.com', 'managed' => false, 'status' => null],
        ['site_id' => $site->id, 'name' => 'cf.example.org', 'managed' => true, 'status' => 'pending'],
    ]);
    app()->instance(DomainRecords::class, $domains);

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();
    site_move_deployed($site);
    site_move_advance();
    site_move_advance();

    // The pending record keeps the cutover running; the old server stays a member (the manual domain points there).
    $cutover = site_move_step($move, 'cutover');
    expect($cutover['status'])->toBe('running')
        ->and(collect($cutover['items'])->firstWhere('id', 'cf.example.org')['state'])->toBe('running')
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->target->id])
        ->and(site_move_targets($site))->toContain($this->old->id);

    $domains->records[1]['status'] = 'synced';
    site_move_advance();
    expect(site_move_step($move, 'cutover')['status'])->toBe('succeeded')
        ->and(site_move_targets($site))->toContain($this->old->id)
        ->and($move->refresh()->status)->toBe('running');
});

it('restores the move’s own backup, not a newer one', function () {
    $site = site_move_stateful($this);
    site_move_until_restore($this, $site);
    $ours = Backup::query()->sole();
    $newer = $ours->replicate();
    $newer->id = strtolower((string) Str::ulid());
    $newer->forceFill(['finished_at' => now()->addHour(), 'sha256' => hash('sha256', 'empty')])->save();

    site_move_advance();

    expect(Restore::query()->sole()->backup_id)->toBe($ours->id);
});

it('fails the move when the move’s backup can’t be restored, before any cutover', function () {
    $site = site_move_stateful($this);
    $move = site_move_until_restore($this, $site);
    Backup::query()->update(['sha256' => null]);

    site_move_advance();

    expect($move->refresh()->status)->toBe('failed')
        ->and(site_move_step($move, 'databases')['items'][0]['state'])->toBe('failed')
        ->and(site_move_step($move, 'databases')['items'][0]['message'])->toContain('Not restored')
        ->and(site_move_step($move, 'cutover')['status'])->toBe('pending')
        ->and(site_move_targets($site, TargetRole::Leader))->toBe([$this->old->id]);
    $this->agents->assertNothingDispatched('db.restore');
});

it('starts the next move of a batch while one only waits for DNS in its cleanup', function () {
    $first = volumes_docker_site($this->organization->id, [$this->old], 'One');
    $second = volumes_docker_site($this->organization->id, [$this->old], 'Two');
    projects_deployment($first, 'succeeded', ['created_at' => now()->subDay()]);
    projects_deployment($second, 'succeeded', ['created_at' => now()->subDay()]);
    Domain::query()->create(['organization_id' => $this->organization->id, 'site_id' => $first->id, 'name' => 'one.example.com', 'is_primary' => true, 'www_redirect' => 'none', 'tls_mode' => 'auto']);

    $this->postJson("/servers/{$this->old->id}/move-sites", ['server_id' => $this->target->id])->assertCreated();
    site_move_deployed($first);
    site_move_advance();

    $one = SiteMove::query()->where('site_id', $first->id)->sole();
    expect($one->status)->toBe('running')
        ->and($one->current_step)->toBe('cleanup')
        ->and(SiteMove::query()->where('site_id', $second->id)->value('status'))->toBe('running');
});

it('refuses a second start while one is being created for the site', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    $lock = Cache::lock(SiteMoveRunner::siteLock($site->id), 30);
    expect($lock->get())->toBeTrue();

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertJsonValidationErrors(['server_id' => 'right now']);
    expect(SiteMove::query()->count())->toBe(0);

    $lock->release();
    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
});

it('lets an operator abandon a failed move that can no longer be cancelled', function () {
    $site = site_move_stateful($this);
    $move = site_move_until_restore($this, $site);
    site_move_advance();
    $this->agents->fail($this->agents->last('db.restore', $this->target->id)['handle'], 'corrupt dump');
    site_move_advance();

    expect($move->refresh()->status)->toBe('failed');
    $this->getJson("/site-moves/{$move->id}")->assertJsonPath('data.cancellable', false)->assertJsonPath('data.abandonable', true);
    $this->postJson("/site-moves/{$move->id}/cancel")->assertJsonValidationErrors('move');

    $this->postJson("/site-moves/{$move->id}/abandon")->assertOk()->assertJsonPath('data.status', 'abandoned');
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'recovery.site_move_abandoned', 'subject_id' => $site->id]);
    // Nothing changed on the servers; the site can be moved again.
    expect($this->instance->refresh()->server_id)->toBe($this->target->id)
        ->and(SiteMove::query()->whereIn('status', SiteMove::ACTIVE)->exists())->toBeFalse();
    $this->postJson("/site-moves/{$move->id}/abandon")->assertJsonValidationErrors('move');
});

it('leaves the control plane’s reserved memory out of the target’s free memory', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    Agent::factory()->create(['server_id' => $this->target->id, 'organization_id' => $this->organization->id, 'facts' => ['memory_bytes' => 4096 * 1024 ** 2, 'cpus' => 2]]);
    config(['servers.control_plane_reserve_mb' => 1536]);

    expect($this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->json('data.capacity.free_mb'))->toBe(4096);

    Server::query()->whereKey($this->target->id)->update(['control_plane_host' => true]);
    expect($this->postJson("/sites/{$site->id}/move/plan", ['server_id' => $this->target->id])->json('data.capacity.free_mb'))->toBe(4096 - 1536);
});

it('keeps a switched move switched when renaming its generated domains fails', function () {
    $site = volumes_docker_site($this->organization->id, [$this->old], 'Web');
    app()->instance(DomainRecords::class, new SiteMoveFakeDomains(failRename: true));

    $this->postJson("/sites/{$site->id}/move", ['server_id' => $this->target->id])->assertCreated();
    $move = SiteMove::query()->sole();
    SiteTarget::query()->where('site_id', $site->id)->where('server_id', $this->target->id)->update(['status' => TargetStatus::Ready]);
    site_move_advance();

    expect($move->refresh()->status)->toBe('failed')
        ->and($move->switched())->toBeTrue()
        ->and(site_move_step($move, 'cutover')['items'][0]['message'])->toContain('edge is down');
    $this->postJson("/site-moves/{$move->id}/cancel")->assertJsonValidationErrors('move');
});
