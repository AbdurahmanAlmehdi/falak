<?php

namespace Falak\Identity\Domain\Models;

use Falak\Identity\Application\Notifications\QueuedResetPassword;
use Falak\Identity\Application\Notifications\QueuedVerifyEmail;
use Falak\Identity\Database\Factories\UserFactory;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?string $current_organization_id
 * @property ?Carbon $email_verified_at
 * @property ?string $two_factor_secret
 * @property ?string $two_factor_recovery_codes
 * @property ?Carbon $two_factor_confirmed_at
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $table = 'identity_users';

    /** Roles are global; assignments are organization-scoped (see config/permission.php). */
    protected string $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            // Fortify (un)serializes them through CastSealedTwoFactorEncrypter; the casts seal them.
            'two_factor_secret' => Sealed::class,
            'two_factor_recovery_codes' => Sealed::class,
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'identity_memberships')
            ->using(Membership::class)
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->organizations()->whereKey($organizationId)->exists();
    }

    /**
     * Verification links go out only where verification is enforced (a managed install): a self-hosted panel
     * neither asks for nor mails them. Queued, like the other account mail.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (config('controlplane.managed')) {
            $this->notify(new QueuedVerifyEmail);
        }
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication();
    }
}
