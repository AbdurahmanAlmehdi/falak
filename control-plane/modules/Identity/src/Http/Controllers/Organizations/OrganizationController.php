<?php

namespace Falak\Identity\Http\Controllers\Organizations;

use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Application\Actions\DeleteOrganization;
use Falak\Identity\Application\Actions\SwitchOrganization;
use Falak\Identity\Application\Actions\TransferOwnership;
use Falak\Identity\Application\Actions\UpdateOrganization;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationController extends Controller
{
    use ResolvesOrganization;

    public function create(): Response
    {
        return Inertia::render('Identity/organizations/create');
    }

    public function store(Request $request, CreateOrganization $create): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $create($this->user($request), $data['name']);

        return redirect(config('fortify.home'));
    }

    public function switch(Request $request, SwitchOrganization $switch): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string']]);

        $switch($this->user($request), $data['organization_id']);

        return redirect(config('fortify.home'));
    }

    public function edit(Request $request): Response
    {
        $organization = $this->organization();
        $user = $this->user($request);

        // Not "organization": that key is the shared prop used by the org switcher.
        return Inertia::render('Identity/organizations/settings', [
            'details' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'personal' => $organization->personal,
                'owner_id' => $organization->owner_id,
                'created_at' => $organization->created_at?->toIso8601String(),
            ],
            'can' => [
                'update' => $user->can('update', $organization),
                'delete' => $user->can('delete', $organization) && ! $organization->personal,
                'transfer' => $user->can('transfer', $organization),
            ],
            'members' => $organization->members()
                ->whereKeyNot($user->id)
                ->orderBy('name')
                ->get(['identity_users.id', 'name', 'email'])
                ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email]),
        ]);
    }

    public function update(Request $request, UpdateOrganization $update): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('update', $organization);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $update($organization, $data['name']);

        return back();
    }

    public function transfer(Request $request, TransferOwnership $transfer): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('transfer', $organization);

        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'password' => ['required', 'current_password'],
        ]);

        // Only members are looked up: an unknown id and a non-member get the same answer (no account probing).
        $newOwner = $organization->members()->whereKey($data['user_id'])->first()
            ?? throw ValidationException::withMessages(['user_id' => 'The new owner must be another member of this organization.']);

        $transfer($organization, $this->user($request), $newOwner);

        return back();
    }

    public function destroy(Request $request, DeleteOrganization $delete): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('delete', $organization);

        $request->validate([
            'name' => ['required', 'string', 'in:'.$organization->name],
            'password' => ['required', 'current_password'],
        ], ['name.in' => 'Type the organization name to confirm.']);

        $delete($organization, $this->user($request));

        return redirect(config('fortify.home'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
