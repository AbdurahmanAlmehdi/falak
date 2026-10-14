<?php

use Falak\Sites\Http\Controllers\Api\SiteApiController;
use Falak\Sites\Http\Controllers\SiteLimitsController;
use Falak\Sites\Http\Controllers\SiteLogSourcesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites', [SiteApiController::class, 'index'])->name('sites.index');
    Route::post('sites', [SiteApiController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', [SiteApiController::class, 'show'])->name('sites.show');
    Route::delete('sites/{site}', [SiteApiController::class, 'destroy'])->name('sites.destroy');
    Route::get('sites/{site}/env', [SiteApiController::class, 'env'])->middleware('throttle:60,1')->name('sites.env');
    Route::put('sites/{site}/env', [SiteApiController::class, 'updateEnv'])->middleware('throttle:60,1')->name('sites.env.update');
    Route::put('sites/{site}/laravel', [SiteApiController::class, 'updateLaravel'])->middleware('throttle:60,1')->name('sites.laravel.update');
    Route::get('sites/{site}/limits', [SiteLimitsController::class, 'show'])->name('sites.limits');
    Route::put('sites/{site}/limits', [SiteLimitsController::class, 'update'])->middleware('throttle:60,1')->name('sites.limits.update');
    Route::put('sites/{site}/compose/services/{service}/limits', [SiteLimitsController::class, 'update'])->where('service', '[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}')->middleware('throttle:60,1')->name('sites.compose.limits.update');
    Route::get('sites/{site}/log-sources', [SiteLogSourcesController::class, 'show'])->name('sites.log-sources');
    Route::put('sites/{site}/log-sources', [SiteLogSourcesController::class, 'update'])->middleware('throttle:60,1')->name('sites.log-sources.update');
});
