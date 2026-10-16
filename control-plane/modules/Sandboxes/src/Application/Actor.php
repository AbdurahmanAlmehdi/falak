<?php

namespace Falak\Sandboxes\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who does something to a sandbox: a signed-in user, or an API token (its user's id, the token's name).
 */
final readonly class Actor
{
    public function __construct(
        public string $type,
        public ?string $id,
        public ?string $name,
        public ?string $tokenId = null,
    ) {}

    public static function of(?Authenticatable $user): self
    {
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $id = $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;

        if ($token instanceof PersonalAccessToken) {
            return new self('token', $id, (string) $token->name, (string) $token->getKey());
        }

        return new self('user', $id, $user !== null ? (string) ($user->name ?? '') : null);
    }
}
