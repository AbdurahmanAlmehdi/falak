<?php

namespace App\Http\Middleware;

use Falak\Kernel\Support\SharedProps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
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
