<?php

namespace Falak\Identity\Domain\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum API token, scoped to one organization. Abilities are Identity permission
 * names (or "*"); a request is authorized only when both the token ability and the
 * owner's role in the token's organization allow it.
 *
 * @property ?string $organization_id
 * @property ?int $idle_expiry_days set for CLI logins: expires_at slides to last use + this many days, never past a year after creation
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'identity_personal_access_tokens';

    /** @var list<string> */
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'organization_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'idle_expiry_days' => 'integer'];
    }

    protected static function booted(): void
    {
        // Sanctum saves last_used_at on every authenticated request: an idle-expiring token moves its expiry along.
        static::saving(function (self $token) {
            if ($token->idle_expiry_days !== null && $token->last_used_at !== null && $token->isDirty('last_used_at')) {
                // A ceiling, so a stolen token used now and then still dies: the owner logs in again once a year.
                $token->expires_at = $token->last_used_at->copy()->addDays($token->idle_expiry_days)
                    ->min(($token->created_at ?? $token->last_used_at)->copy()->addYear());
            }
        });
    }
}
