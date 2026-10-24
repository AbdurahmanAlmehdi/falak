<?php

use App\Http\Middleware\HandleInertiaRequests;
use Falak\Identity\Http\Middleware\Authenticate;
use Falak\Kernel\Http\HostCookies;
use Falak\Kernel\Http\StreamedUploadsPostSize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\ValidatePostSize;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind the edge (Caddy/FrankenPHP) every request arrives from the proxy; trust only its CIDRs
        // so $request->ip() (rate limits, audit log, agent last_ip) is the real client address.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);

        // The UI theme cookie is read by the root Blade view before first paint (and written by JS): not a secret.
        $middleware->encryptCookies(except: ['appearance']);

        // Builder artifact uploads stream to disk under their own cap (StreamedUploadsPostSize).
        $middleware->replace(ValidatePostSize::class, StreamedUploadsPostSize::class);

        // The CSRF cookie is `__Host-XSRF-TOKEN` (like the session cookie: previews share the panel's registrable domain).
        $middleware->web(replace: [ValidateCsrfToken::class => HostCookies::class]);

        // `auth` also requires a verified email address on a managed install (Identity's Authenticate).
        $middleware->alias(['auth' => Authenticate::class]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Never flashed back into the session with validation errors (old input): passwords, codes, secret values,
        // secret provider credentials (config), a customer's age identity given for one backup restore (identity).
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'recovery_code', 'value', 'reference', 'content', 'set', 'config', 'identity', 'auth_key']);
    })->create();
