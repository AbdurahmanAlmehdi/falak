<?php

namespace Falak\Network\Application;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Block and unblock changes per user (or organization, for API tokens without a user): one request is one change,
 * however many servers it reaches.
 */
final class ProtectionRateLimit
{
    /**
     * @throws ValidationException
     */
    public static function hit(?string $actorId, string $organizationId, string $field = 'address'): void
    {
        $key = 'network.protect:'.($actorId ?: $organizationId);

        if (RateLimiter::tooManyAttempts($key, (int) config('network.protection.changes_per_minute', 30))) {
            throw ValidationException::withMessages([$field => 'Too many block changes; try again in a minute.']);
        }

        RateLimiter::hit($key, 60);
    }
}
