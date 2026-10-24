<?php

use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Events\OrganizationDeleted;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects members without an organization to create one', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/organization')
        ->assertRedirect(route('organizations.create'));

    $this->get('/organizations/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Identity/organizations/create', false));
});

it('creates an organization owned by the user and switches to it', function () {
    [$user] = actingAsMember();

    $this->post('/organizations', ['name' => 'Umbrella Corp'])->assertRedirect('/projects');

    $organization = Organization::query()->where('name', 'Umbrella Corp')->sole();
    expect($organization->slug)->toBe('umbrella-corp')
        ->and($organization->personal)->toBeFalse()
        ->and($user->fresh()->current_organization_id)->toBe($organization->id)
        ->and(app(OrganizationAccess::class)->roleOf($user->id, $organization->id))->toBe(Role::Owner);

    $this->post('/organizations', ['name' => 'Umbrella Corp'])->assertRedirect();
    expect(Organization::query()->where('name', 'Umbrella Corp')->pluck('slug')->unique())->toHaveCount(2);

    $this->post('/organizations', ['name' => ''])->assertSessionHasErrors('name');
});

it('switches between organizations the user belongs to only', function () {
    [$user, $first] = actingAsMember();
    $second = app(CreateOrganization::class)($user, 'Second');
    [, $foreign] = memberOf();

    $this->put('/organizations/current', ['organization_id' => $first->id])->assertRedirect('/projects');
    expect($user->fresh()->current_organization_id)->toBe($first->id);
    $this->get('/settings/organization')->assertInertia(fn (Assert $page) => $page->where('details.id', $first->id));

    $this->put('/organizations/current', ['organization_id' => $second->id]);
    $this->get('/settings/organization')->assertInertia(fn (Assert $page) => $page->where('details.id', $second->id));

    $this->put('/organizations/current', ['organization_id' => $foreign->id])->assertForbidden();
    expect($user->fresh()->current_organization_id)->toBe($second->id);
});

it('shows settings with permissions per role', function () {
    [, $organization] = actingAsMember();
    [$viewer] = memberOf($organization, Role::Viewer);

    $this->get('/settings/organization')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/organizations/settings', false)
        ->where('can.update', true)
        ->where('can.transfer', true)
        ->where('can.delete', true)
        ->has('members', 1));

    $this->actingAs($viewer)->get('/settings/organization')->assertInertia(fn (Assert $page) => $page
        ->where('can.update', false)
        ->where('can.delete', false)
        ->where('can.transfer', false));
});

it('renames the organization when allowed', function () {
    [, $organization] = actingAsMember(Role::Admin);

    $this->patch('/organization', ['name' => 'Renamed'])->assertRedirect();
    expect($organization->fresh()->name)->toBe('Renamed')
        ->and(AuditEntry::query()->where('action', 'organization.updated')->exists())->toBeTrue();

    [$developer] = memberOf($organization, Role::Developer);
    $this->actingAs($developer)->patch('/organization', ['name' => 'Nope'])->assertForbidden();
});

it('transfers ownership to another member', function () {
    [$owner, $organization] = actingAsMember();
    [$developer] = memberOf($organization, Role::Developer);
    $access = app(OrganizationAccess::class);

    $this->post('/organization/transfer', ['user_id' => $developer->id, 'password' => 'wrong'])->assertSessionHasErrors('password');

    $this->post('/organization/transfer', ['user_id' => $developer->id, 'password' => 'password'])->assertSessionHasNoErrors();

    expect($organization->fresh()->owner_id)->toBe($developer->id)
        ->and($access->roleOf($developer->id, $organization->id))->toBe(Role::Owner)
        ->and($access->roleOf($owner->id, $organization->id))->toBe(Role::Admin);

    // The former owner can no longer transfer.
    $this->post('/organization/transfer', ['user_id' => $developer->id, 'password' => 'password'])->assertForbidden();
});

it('rejects transferring to non-members', function () {
    actingAsMember();
    [$stranger] = memberOf();

    $error = ['user_id' => 'The new owner must be another member of this organization.'];

    // A stranger and an unknown id get the same answer.
    $this->post('/organization/transfer', ['user_id' => $stranger->id, 'password' => 'password'])->assertSessionHasErrors($error);
    $this->post('/organization/transfer', ['user_id' => '01JUNKNOWN0000000000000000', 'password' => 'password'])->assertSessionHasErrors($error);
});

it('does not transfer or delete personal organizations', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $organization = $user->organizations()->sole();
    $this->actingAs($user);

    $this->delete('/organization', ['name' => $organization->name, 'password' => 'password'])->assertSessionHasErrors('organization');
    expect($organization->fresh())->not->toBeNull();

    $this->post('/organization/transfer', ['user_id' => $user->id, 'password' => 'password'])->assertForbidden();
});

it('deletes an organization after typed confirmation', function () {
    Event::fake([OrganizationDeleted::class]);
    [$owner, $organization] = actingAsMember();
    [$admin] = memberOf($organization, Role::Admin);

    $this->actingAs($admin)->delete('/organization', ['name' => $organization->name, 'password' => 'password'])->assertForbidden();

    $this->actingAs($owner)->delete('/organization', ['name' => 'wrong', 'password' => 'password'])->assertSessionHasErrors('name');

    $this->delete('/organization', ['name' => $organization->name, 'password' => 'password'])->assertRedirect('/projects');

    expect(Organization::query()->find($organization->id))->toBeNull()
        ->and($admin->fresh()->current_organization_id)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'organization.deleted')->where('subject_id', $organization->id)->exists())->toBeTrue();
    Event::assertDispatched(OrganizationDeleted::class, fn ($event) => $event->organizationId === $organization->id);
});

it('shares the current organization, switcher list and permissions with every page', function () {
    [$user, $organization] = actingAsMember(Role::Viewer);

    $this->get('/settings/organization')->assertInertia(fn (Assert $page) => $page
        ->where('organization.current.id', $organization->id)
        ->where('organization.current.name', $organization->name)
        ->where('organization.current.role', 'viewer')
        ->where('organization.current.permissions', fn ($permissions) => collect($permissions)->contains('members.view') && ! collect($permissions)->contains('members.manage'))
        ->has('organization.all', 1)
        ->where('organization.all.0.id', $organization->id)
        ->where('auth.user.id', $user->id)
        ->missing('auth.user.two_factor_secret'));
});

it('shares null organization props with guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('organization', null));
});

it('refuses account deletion while owning a shared organization and cleans up the personal one', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $personal = $user->organizations()->sole();
    $shared = app(CreateOrganization::class)($user, 'Shared');
    $this->actingAs($user);

    $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    expect($user->fresh())->not->toBeNull();

    $shared->delete();
    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->find($user->id))->toBeNull()
        ->and(Organization::query()->find($personal->id))->toBeNull();
});
