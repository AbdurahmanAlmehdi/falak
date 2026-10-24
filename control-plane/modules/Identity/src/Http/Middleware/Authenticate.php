<?php

namespace Falak\Identity\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;

/**
 * The `auth` middleware (web sessions and `auth:sanctum` tokens alike). On a managed install (controlplane.managed)
 * an account must also have verified its email address: until then it only reaches the verification pages, its
 * profile (to fix a mistyped address), invitations and logout. JSON requests get 403; pages go to the verification notice.
 */
final class Authenticate extends BaseAuthenticate
{
    /** What an unverified account may still use (an accepted invitation verifies the address too). */
    private const UNVERIFIED_ROUTES = ['verification.*', 'logout', 'profile.*', 'invitations.show', 'invitations.accept'];

    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        if (self::blocked($request)) {
            if ($request->expectsJson() && $request->header('X-Inertia') === null) {
                return response()->json(['message' => 'Verify your email address to continue.'], 403);
            }

            return redirect()->guest(route('verification.notice'));
        }

        return $next($request);
    }

    private static function blocked(Request $request): bool
    {
        $user = $request->user();

        return config('controlplane.managed')
            && $user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()
            && ! $request->routeIs(...self::UNVERIFIED_ROUTES);
    }
}
