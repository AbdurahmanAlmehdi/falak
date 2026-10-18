<?php

use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Identity\Contracts\Role;
use Falak\Recovery\Application\Jobs\AdvanceServerRecoveries;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/** DatabaseRecovery for one PITR instance: records the calls, settles as the test says. */
final class RecoveryFakePitrDatabases implements DatabaseRecovery
{
    /** @var list<string> */
    public array $calls = [];

    public string $pitrState = 'running';

    public function __construct(private readonly string $organizationId, private readonly string $serverId) {}

    private function point(): DatabaseRecoveryPoint
    {
        return new DatabaseRecoveryPoint('db1', $this->organizationId, 'inst1', 'main', 'shop', 'postgresql', null, 'b1',
            now()->subHours(5)->toDateTimeImmutable(), false, null, true, true, true, now()->subMinutes(2)->toDateTimeImmutable(), false);
    }

    public function instancesOn(string $serverId): array
    {
        return [new InstanceRecoveryPoint('inst1', $this->organizationId, $this->serverId, 'main', 'postgresql', '17', null, true, [$this->point()])];
    }

    public function points(array $databaseIds): array
    {
        return ['db1' => $this->point()];
    }

    public function backUp(string $instanceId, string $storageProviderId, ?string $actorId = null): array
    {
        return [];
    }

    public function backupProgress(array $backupIds): array
    {
        return ['state' => 'succeeded', 'message' => null];
    }

    public function restoreBackups(string $instanceId, array $backupIds, ?string $actorId = null): array
    {
        return ['restores' => [], 'skipped' => []];
    }

    public function removeLeftover(string $instanceId, string $serverId): bool
    {
        return true;
    }

    public function relocate(string $instanceId, string $targetServerId, ?string $actorId = null, bool $suspendPitr = false): void
    {
        $this->calls[] = 'relocate'.($suspendPitr ? ':suspend' : '');
    }

    public function restoreLatest(string $instanceId, ?string $actorId = null): array
    {
        $this->calls[] = 'restoreLatest';

        return ['restores' => [], 'skipped' => []];
    }

    public function progress(string $instanceId, array $restoreIds = []): array
    {
        return ['state' => 'ready', 'message' => null];
    }

    public function restoreToLatest(string $instanceId, ?string $actorId = null): string
    {
        $this->calls[] = 'restoreToLatest';

        return 'r1';
    }

    public function pitrProgress(string $restoreId, ?string $actorId = null, bool $retry = false): array
    {
        $this->calls[] = 'pitrProgress'.($retry ? ':retry' : '');

        return ['state' => $this->pitrState, 'message' => null];
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    FakeAgentGateway::install();
    [, $this->organization] = actingAsMember(Role::Admin);
    $this->lost = databases_server($this->organization, attributes: ['name' => 'db-lost']);
    $this->target = databases_server($this->organization, attributes: ['name' => 'db-new']);
    $this->fake = new RecoveryFakePitrDatabases($this->organization->id, $this->lost->id);
    app()->instance(DatabaseRecovery::class, $this->fake);
});

afterEach(fn () => Carbon::setTestNow());

it('restores a PITR database to the latest point, estimating the loss from the PITR lag', function () {
    $plan = $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->json('data');
    expect($plan['databases'][0])->toMatchArray(['method' => 'pitr', 'worst_loss_seconds' => 120])
        ->and($plan['databases'][0]['databases'][0])->toMatchArray(['method' => 'pitr', 'data_loss_seconds' => 120]);

    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'db-lost'])->assertSessionHasNoErrors();
    $recovery = ServerRecovery::query()->firstOrFail();
    expect($this->fake->calls)->toBe(['relocate:suspend', 'restoreToLatest', 'pitrProgress'])
        ->and($recovery->refresh()->step('databases')['status'])->toBe('running');

    $this->fake->pitrState = 'failed';
    dispatch_sync(new AdvanceServerRecoveries);
    expect($recovery->refresh()->status)->toBe('failed');

    // A retry re-tries the swap / restore of the same PITR restore first; never the backup path.
    $this->fake->pitrState = 'succeeded';
    $this->fake->calls = [];
    $this->postJson("/recoveries/{$recovery->id}/steps/databases/retry")->assertOk();
    expect($this->fake->calls)->toBe(['pitrProgress:retry', 'pitrProgress'])
        ->and($recovery->refresh()->step('databases')['status'])->toBe('succeeded');
});
