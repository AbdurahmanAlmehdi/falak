<?php

namespace Falak\Identity\Application;

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\CliLogin;
use Falak\Identity\Domain\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `falak login` in the browser: RFC 8628 device authorization on top of the panel's own sign-in.
 *
 * The CLI starts a login and gets a device code (secret, kept by the CLI) and a user code (shown to the person).
 * The person opens /cli/authorize/{user code}, signs in as usual and approves with a fresh password (+ 2FA) check,
 * the same as minting a token in Settings. The CLI polls with its device code and collects the token exactly once.
 */
final class CliLogins
{
    public const TTL_SECONDS = 600;

    public const INTERVAL_SECONDS = 5;

    /** A CLI token expires after this many days without use. */
    public const IDLE_EXPIRY_DAYS = 90;

    /** RFC 8628 §6.1: no vowels (no words), no look-alikes. */
    private const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public function __construct(
        private readonly CreateApiToken $createToken,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{device_code: string, user_code: string, expires_in: int, interval: int}
     */
    public function start(string $clientName, ?string $ip): array
    {
        $deviceCode = Str::random(48);
        $userCode = '';
        for ($i = 0; $i < 8; $i++) {
            $userCode .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
        }

        CliLogin::query()->where('expires_at', '<', Carbon::now()->subDay())->delete();
        CliLogin::query()->create([
            'device_code_hash' => hash('sha256', $deviceCode),
            'user_code_hash' => hash('sha256', $userCode),
            'client_name' => Str::limit(trim($clientName) !== '' ? trim($clientName) : 'unknown device', 100, ''),
            'ip_address' => $ip,
            'expires_at' => Carbon::now()->addSeconds(self::TTL_SECONDS),
        ]);

        return [
            'device_code' => $deviceCode,
            'user_code' => substr($userCode, 0, 4).'-'.substr($userCode, 4),
            'expires_in' => self::TTL_SECONDS,
            'interval' => self::INTERVAL_SECONDS,
        ];
    }

    /** The pending login a user code names ("wdjb mjht", "WDJB-MJHT" …), or null. */
    public function findPending(string $userCode): ?CliLogin
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $userCode));
        if (strlen($normalized) !== 8) {
            return null;
        }
        $login = CliLogin::query()->where('user_code_hash', hash('sha256', $normalized))->first();

        return $login?->isPending() ? $login : null;
    }

    public function approve(CliLogin $login, User $user, string $organizationId): void
    {
        if (! $user->belongsToOrganization($organizationId)) {
            throw ValidationException::withMessages(['organization_id' => 'You are not a member of that organization.']);
        }
        $updated = CliLogin::query()->whereKey($login->id)->where('status', 'pending')->where('expires_at', '>', Carbon::now())
            ->update(['status' => 'approved', 'user_id' => $user->id, 'organization_id' => $organizationId, 'updated_at' => Carbon::now()]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['code' => 'This login request has expired or was already answered. Run `falak login` again.']);
        }
        $this->audit->record('cli_login.approved', 'cli_login', $login->id, ['client' => $login->client_name, 'ip' => $login->ip_address], $organizationId, $user->id);
    }

    public function deny(CliLogin $login, User $user): void
    {
        CliLogin::query()->whereKey($login->id)->where('status', 'pending')->update(['status' => 'denied', 'user_id' => $user->id, 'updated_at' => Carbon::now()]);
        $this->audit->recordPersonal('cli_login.denied', $user->id, ['client' => $login->client_name, 'ip' => $login->ip_address]);
    }

    /**
     * The CLI's poll. Returns the new token once approved; otherwise an RFC 8628 error code:
     * authorization_pending, slow_down, access_denied, expired_token.
     *
     * @return array{error: string}|array{token: string, user: User, organization_id: string}
     */
    public function collect(string $deviceCode): array
    {
        $login = CliLogin::query()->where('device_code_hash', hash('sha256', $deviceCode))->first();
        if ($login === null || $login->status === 'consumed' || $login->expires_at->isPast()) {
            return ['error' => 'expired_token'];
        }
        if ($login->status === 'denied') {
            return ['error' => 'access_denied'];
        }
        $tooSoon = $login->last_polled_at !== null && $login->last_polled_at->diffInSeconds(Carbon::now(), true) < self::INTERVAL_SECONDS - 1;
        $login->forceFill(['last_polled_at' => Carbon::now()])->save();
        if ($tooSoon) {
            return ['error' => 'slow_down'];
        }
        if ($login->status !== 'approved') {
            return ['error' => 'authorization_pending'];
        }

        return DB::transaction(function () use ($login) {
            // Exactly once: a second poll racing this one finds the row consumed.
            if (CliLogin::query()->whereKey($login->id)->where('status', 'approved')->update(['status' => 'consumed', 'updated_at' => Carbon::now()]) !== 1) {
                return ['error' => 'expired_token'];
            }
            /** @var User $user */
            $user = User::query()->findOrFail($login->user_id);
            $token = ($this->createToken)($user, (string) $login->organization_id, 'CLI — '.$login->client_name, ['*'], Carbon::now()->addDays(self::IDLE_EXPIRY_DAYS));
            $token->accessToken->forceFill(['idle_expiry_days' => self::IDLE_EXPIRY_DAYS])->save();

            return ['token' => $token->plainTextToken, 'user' => $user, 'organization_id' => (string) $login->organization_id];
        });
    }
}
