<?php

use Falak\Config\Contracts\ManagedFields;
use Falak\Config\Contracts\ManagedResources;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\User;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = memberOf(role: Role::Developer);
    $this->instance = databases_instance($this->organization);
});

/**
 * @param  list<string>  $abilities
 */
function db_api_token(User $user, string $organizationId, array $abilities = ['*']): string
{
    $token = $user->createToken('cli', $abilities);
    $token->accessToken->forceFill(['organization_id' => $organizationId])->save();
    auth()->forgetGuards();

    return $token->plainTextToken;
}

function db_api_no_secrets(string $body, DatabaseInstance $instance): void
{
    $instance->refresh();
    expect($body)->not->toContain($instance->root_password)
        ->and($body)->not->toContain('"root_password"')
        ->and($body)->not->toContain('"next_root_password"')
        ->and($body)->not->toContain('"previous_password"');
}

it('needs a token', function () {
    $this->getJson('/api/v1/databases/instances')->assertUnauthorized();
    $this->getJson("/api/v1/databases/instances/{$this->instance->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => $this->instance->name])->assertUnauthorized();
});

it('lists and shows the organization\'s containers without passwords', function () {
    $token = db_api_token($this->user, $this->organization->id, ['databases.view']);
    databases_instance(memberOf()[1]); // another organization's

    $list = $this->withToken($token)->getJson('/api/v1/databases/instances')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->instance->id)
        ->assertJsonPath('data.0.databases_count', 0);
    $show = $this->withToken($token)->getJson("/api/v1/databases/instances/{$this->instance->id}")->assertOk()
        ->assertJsonPath('data.name', $this->instance->name)
        ->assertJsonPath('data.status', 'active');

    db_api_no_secrets($list->getContent(), $this->instance);
    db_api_no_secrets($show->getContent(), $this->instance);
});

it('hides another organization\'s container (404)', function () {
    $token = db_api_token($this->user, $this->organization->id);
    $foreign = databases_instance(memberOf()[1]);

    $this->withToken($token)->getJson("/api/v1/databases/instances/{$foreign->id}")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/databases/instances/{$foreign->id}", ['confirm' => $foreign->name])->assertNotFound();
    expect($this->agents->dispatched('db.instance.delete'))->toBe([]);
});

it('deletes with databases.manage like the dashboard: 202, deleting, the volume only when asked', function () {
    $token = db_api_token($this->user, $this->organization->id, ['databases.view', 'databases.manage']);

    $response = $this->withToken($token)->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => $this->instance->name, 'delete_volume' => true])
        ->assertAccepted()
        ->assertJsonPath('data.id', $this->instance->id)
        ->assertJsonPath('data.status', 'deleting');

    db_api_no_secrets($response->getContent(), $this->instance);
    expect($this->instance->refresh()->status)->toBe(InstanceStatus::Deleting)
        ->and($this->instance->delete_volume)->toBeTrue()
        ->and($this->agents->last('db.instance.delete')['payload'])->toBe(['id' => $this->instance->id]);
});

it('refuses delete without the manage permission (token ability or role)', function () {
    $viewOnly = db_api_token($this->user, $this->organization->id, ['databases.view']);
    $this->withToken($viewOnly)->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => $this->instance->name])->assertForbidden();

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->withToken(db_api_token($viewer, $this->organization->id))->getJson("/api/v1/databases/instances/{$this->instance->id}")->assertOk();
    $this->withToken(db_api_token($viewer, $this->organization->id))->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => $this->instance->name])->assertForbidden();

    expect($this->agents->dispatched('db.instance.delete'))->toBe([]);
});

it('answers 422 to a missing or mismatched confirmation', function () {
    $token = db_api_token($this->user, $this->organization->id);

    $this->withToken($token)->deleteJson("/api/v1/databases/instances/{$this->instance->id}")->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->withToken($token)->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => 'nope'])
        ->assertUnprocessable()->assertJsonPath('errors.confirm.0', 'Type the database server name to confirm.');

    expect($this->agents->dispatched('db.instance.delete'))->toBe([]);
});

it('refuses deleting a container falak.yaml declares (422)', function () {
    $token = db_api_token($this->user, $this->organization->id);
    $managed = Mockery::mock(ManagedResources::class)->shouldIgnoreMissing();
    $managed->shouldReceive('managed')->andReturn(new ManagedFields(['/memory'], 'abc1234', 'falak.yaml'));
    app()->instance(ManagedResources::class, $managed);

    $this->withToken($token)->deleteJson("/api/v1/databases/instances/{$this->instance->id}", ['confirm' => $this->instance->name])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirm' => 'declared in the file']);

    expect($this->instance->refresh()->status)->toBe(InstanceStatus::Active)
        ->and($this->agents->dispatched('db.instance.delete'))->toBe([]);
});
