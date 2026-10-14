<?php

namespace Falak\Edge\Http\Controllers;

use Falak\Edge\Application\CloudflareAnalytics;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Settings → Networking → Cloudflare traffic: the site's proxied names as Cloudflare saw them (cached five minutes).
 * `refresh=1` skips the cache, at most once a minute per site; more often it is ignored.
 */
final class CloudflareAnalyticsController extends Controller
{
    use ResolvesSite;

    /** GET — {data: {zones: [...], error}} */
    public function show(Request $request, string $site, CloudflareAnalytics $analytics): JsonResponse
    {
        $siteData = $this->site($request, $site);
        $refresh = $request->boolean('refresh')
            && RateLimiter::attempt('edge:cf-analytics-refresh:'.$siteData->id, 1, fn () => true, 60);

        $zones = $analytics->forSite($siteData, $refresh);
        $errors = array_values(array_filter(array_column($zones, 'error')));

        return response()->json(['data' => [
            'zones' => $zones,
            // Every zone failed the same way (one connection, one missing permission): said once.
            'error' => $zones !== [] && count($errors) === count($zones) && count(array_unique($errors)) === 1 ? $errors[0] : null,
        ]]);
    }
}
