<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Invitation;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Events\MemberJoined;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptInvitation
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly SwitchOrganization $switch,
        private readonly AuditLog $audit,
    ) {}

    public static function findPending(string $token): ?Invitation
    {
        return Invitation::query()->pending()->where('token_hash', Invitation::hashToken($token))->first();
    }

    public function __invoke(User $user, string $token): Organization
    {
        $invitation = self::findPending($token);

        if (! $invitation) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is invalid or has expired.']);
        }

        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to a different email address.']);
        }

        /** @var Organization $organization */
        $organization = $invitation->organization;

        DB::transaction(function () use ($invitation, $organization, $user) {
            $organization->members()->syncWithoutDetaching([$user->id]);
            ($this->assignRole)($user, $organization->id, $invitation->role);
            $invitation->update(['accepted_at' => now()]);

            // The invitation link went to this address (checked above): following it proves the mailbox.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        });

        ($this->switch)($user, $organization->id);

        $this->audit->record('member.joined', 'user', $user->id, ['role' => $invitation->role->value, 'invitation' => $invitation->id], $organization->id, $user->id);
        MemberJoined::dispatch($organization->id, $user->id, $invitation->role->value);

        return $organization;
    }
}
