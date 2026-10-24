<?php

namespace App\Http\Middleware;

use Closure;
use Falak\Kernel\Support\SharedProps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\View;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Pages rendered from the root view get a Content-Security-Policy whose nonce the view's scripts (@vite, @routes,
     * the theme script) carry. Other responses (Horizon, files, JSON) keep the edge's baseline policy.
     */
    public function handle(Request $request, Closure $next)
    {
        $nonce = Vite::useCspNonce();
        $response = parent::handle($request, $next);

        if ($response instanceof Response && $response->getOriginalContent() instanceof View
            && $response->getOriginalContent()->name() === $this->rootView && ! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', self::contentSecurityPolicy($nonce));
        }

        return $response;
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        // The Cool Icons library: the icon picker fetches its index (connect-src); icons are images.
        $icons = parse_url((string) config('kernel.icons_url'));
        $iconsOrigin = isset($icons['scheme'], $icons['host'])
            ? $icons['scheme'].'://'.$icons['host'].(isset($icons['port']) ? ':'.$icons['port'] : '')
            : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // React style props, Radix, Monaco, xterm and the Inertia progress bar set inline styles.
            "style-src 'self' 'unsafe-inline'",
            // Template and service icons may come from any HTTPS host (catalog repositories, Cool Icons).
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            // wss: Reverb (same host behind the edge; listed for browsers whose 'self' does not cover wss).
            trim("connect-src 'self' wss: {$iconsOrigin}"),
            // Monaco's editor and TypeScript workers.
            "worker-src 'self' blob:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            // The GitHub App manifest flow submits a real form to GitHub (or a GitHub Enterprise host).
            "form-action 'self' https:",
        ]);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            // Cool Icons base URL (canvas icon picker, picked icons); null when turned off.
            'icons_url' => config('kernel.icons_url') ?: null,
            'auth' => [
                'user' => $request->user(),
            ],
            // One-shot session flashes (`back()->with('success', ...)`), rendered as toasts by the app layout.
            'flash' => fn () => self::flash($request),
            // Props registered by modules (e.g. Projects' `falak` navigation model).
            ...app(SharedProps::class)->for($request),
        ]);
    }

    /**
     * @return array{success?: string, error?: string, warning?: string, status?: string}
     */
    public static function flash(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $flash = [];

        foreach (['success', 'error', 'warning', 'status'] as $key) {
            $value = $request->session()->get($key);

            if (is_string($value) && $value !== '') {
                $flash[$key] = $value;
            }
        }

        return $flash;
    }
}
