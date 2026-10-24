<?php

use Falak\Edge\Http\Controllers\CertificateController;
use Falak\Edge\Http\Controllers\CloudflareAnalyticsController;
use Falak\Edge\Http\Controllers\CloudflareController;
use Falak\Edge\Http\Controllers\DnsController;
use Falak\Edge\Http\Controllers\DnsCredentialController;
use Falak\Edge\Http\Controllers\DomainController;
use Falak\Edge\Http\Controllers\DomainSettingsController;
use Falak\Edge\Http\Controllers\LoadBalancerController;
use Falak\Edge\Http\Controllers\MountController;
use Falak\Edge\Http\Controllers\PathRateLimitController;
use Falak\Edge\Http\Controllers\RateLimitController;
use Falak\Edge\Http\Controllers\RoutingController;
use Falak\Edge\Infrastructure\EdgeCaddyBuilds;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    // A function's Settings → Paths: paths of other sites it serves.
    Route::get('sites/{site}/function-mounts', [MountController::class, 'index'])->name('edge.mounts.index');
    Route::post('sites/{site}/function-mounts', [MountController::class, 'store'])->name('edge.mounts.store');
    Route::delete('sites/{site}/function-mounts/{mount}', [MountController::class, 'destroy'])->where('mount', '[0-9A-Za-z]{26}')->name('edge.mounts.destroy');

    Route::get('sites/{site}/domains', [DomainController::class, 'index'])->name('edge.domains.index');
    Route::post('sites/{site}/domains', [DomainController::class, 'store'])->name('edge.domains.store');
    Route::patch('sites/{site}/domains/{domain}', [DomainController::class, 'update'])->name('edge.domains.update');
    Route::put('sites/{site}/domains/{domain}/primary', [DomainController::class, 'primary'])->name('edge.domains.primary');
    Route::put('sites/{site}/domains/{domain}/cloudflare', [DomainController::class, 'cloudflare'])->name('edge.domains.cloudflare');
    Route::put('sites/{site}/domains/{domain}/cloudflare-cache', [DomainController::class, 'cloudflareCache'])->name('edge.domains.cloudflare-cache');
    Route::get('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'show'])->name('edge.domains.rate-limit.show');
    Route::put('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'update'])->middleware('throttle:30,1')->name('edge.domains.rate-limit.update');
    Route::delete('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'destroy'])->name('edge.domains.rate-limit.destroy');
    Route::post('sites/{site}/cloudflare/purge', [DomainController::class, 'cloudflarePurge'])->middleware('throttle:20,1')->name('edge.cloudflare.purge');
    Route::get('sites/{site}/cloudflare/analytics', [CloudflareAnalyticsController::class, 'show'])->middleware('throttle:30,1')->name('edge.cloudflare.analytics');
    Route::delete('sites/{site}/domains/{domain}', [DomainController::class, 'destroy'])->name('edge.domains.destroy');
    Route::post('sites/{site}/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:10,1')->name('edge.domains.verify');
    Route::post('sites/{site}/edge/apply', [DomainController::class, 'apply'])->name('edge.apply');

    Route::post('sites/{site}/certificates', [CertificateController::class, 'store'])->name('edge.certificates.store');
    Route::delete('sites/{site}/certificates/{certificate}', [CertificateController::class, 'destroy'])->name('edge.certificates.destroy');

    Route::put('sites/{site}/load-balancer', [LoadBalancerController::class, 'update'])->name('edge.load-balancer.update');
    Route::delete('sites/{site}/load-balancer', [LoadBalancerController::class, 'destroy'])->name('edge.load-balancer.destroy');

    Route::get('sites/{site}/routing', [RoutingController::class, 'index'])->name('edge.routing.index');
    Route::post('sites/{site}/redirects', [RoutingController::class, 'storeRedirect'])->name('edge.redirects.store');
    Route::delete('sites/{site}/redirects/{redirect}', [RoutingController::class, 'destroyRedirect'])->name('edge.redirects.destroy');
    Route::post('sites/{site}/security-rules', [RoutingController::class, 'storeRule'])->name('edge.security-rules.store');
    Route::delete('sites/{site}/security-rules/{rule}', [RoutingController::class, 'destroyRule'])->name('edge.security-rules.destroy');
    Route::post('sites/{site}/headers', [RoutingController::class, 'storeHeader'])->name('edge.headers.store');
    Route::delete('sites/{site}/headers/{header}', [RoutingController::class, 'destroyHeader'])->name('edge.headers.destroy');
    Route::put('sites/{site}/edge-settings', [RoutingController::class, 'updateSettings'])->name('edge.settings.update');

    // Domain picker (create forms, Networking) and Settings → Domains.
    Route::get('domains/options', [DnsController::class, 'options'])->name('edge.domains.options');
    Route::get('dns/check', [DnsController::class, 'check'])->middleware('throttle:60,1')->name('edge.dns.check');
    Route::get('settings/domains', [DomainSettingsController::class, 'show'])->name('edge.domain-settings');
    Route::put('settings/domains', [DomainSettingsController::class, 'update'])->name('edge.domain-settings.update');

    Route::get('settings/cloudflare', [CloudflareController::class, 'show'])->name('edge.cloudflare');
    Route::post('settings/cloudflare', [CloudflareController::class, 'connect'])->middleware('throttle:20,1')->name('edge.cloudflare.connect');
    Route::delete('settings/cloudflare/{credential}', [CloudflareController::class, 'disconnect'])->name('edge.cloudflare.disconnect');
    Route::post('settings/cloudflare/{credential}/zones', [CloudflareController::class, 'enable'])->name('edge.cloudflare.zones.enable');
    Route::patch('settings/cloudflare/zones/{zone}', [CloudflareController::class, 'update'])->name('edge.cloudflare.zones.update');
    Route::delete('settings/cloudflare/zones/{zone}', [CloudflareController::class, 'disable'])->name('edge.cloudflare.zones.disable');
    Route::put('settings/cloudflare/zones/{zone}/setting', [CloudflareController::class, 'setting'])->name('edge.cloudflare.zones.setting');
    Route::post('settings/cloudflare/zones/{zone}/sync', [CloudflareController::class, 'sync'])->name('edge.cloudflare.zones.sync');
    Route::put('settings/cloudflare/zones/{zone}/under-attack', [CloudflareController::class, 'underAttack'])->name('edge.cloudflare.zones.under-attack');
    Route::put('settings/cloudflare/servers/{server}/lock', [CloudflareController::class, 'lock'])->name('edge.cloudflare.servers.lock');
    Route::post('settings/cloudflare/tunnels', [CloudflareController::class, 'enableTunnel'])->middleware('throttle:20,1')->name('edge.cloudflare.tunnels.enable');
    Route::delete('settings/cloudflare/tunnels/{tunnel}', [CloudflareController::class, 'disableTunnel'])->name('edge.cloudflare.tunnels.disable');
    Route::post('settings/cloudflare/tunnels/{tunnel}/reinstall', [CloudflareController::class, 'reinstallTunnel'])->name('edge.cloudflare.tunnels.reinstall');

    Route::post('edge/dns-credentials', [DnsCredentialController::class, 'store'])->name('edge.dns-credentials.store');
    Route::delete('edge/dns-credentials/{credential}', [DnsCredentialController::class, 'destroy'])->name('edge.dns-credentials.destroy');

    // Path rate limits at the edge (Network → Rate limits, docs/plans/network/protection.md).
    Route::get('network/rate-limits', [PathRateLimitController::class, 'index'])->name('edge.path-rate-limits.index');
    Route::post('network/rate-limits', [PathRateLimitController::class, 'store'])->middleware('throttle:30,1')->name('edge.path-rate-limits.store');
    Route::delete('network/rate-limits/{limit}', [PathRateLimitController::class, 'destroy'])->middleware('throttle:30,1')->name('edge.path-rate-limits.destroy');
});

// Falak's Caddy build for servers' edges: public like the agent installer (agents download it with its checksum from
// edge.caddy.apply; it holds nothing secret).
Route::get('install/edge/caddy-linux-{arch}', [PathRateLimitController::class, 'caddyBuild'])->middleware('throttle:60,1')
    ->whereIn('arch', EdgeCaddyBuilds::ARCHES)->name('edge.caddy-build');
