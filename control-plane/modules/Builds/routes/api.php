<?php

use Falak\Builds\Http\Controllers\Internal\ArtifactController;
use Falak\Builds\Http\Controllers\Internal\BuildEventsController;
use Falak\Builds\Http\Controllers\Internal\BuildHeartbeatController;
use Falak\Builds\Http\Controllers\Internal\NextBuildController;
use Falak\Builds\Http\Controllers\Internal\RegistryTokenController;
use Falak\Builds\Http\Middleware\AuthenticateBuilder;
use Illuminate\Support\Facades\Route;

// Internal API for falak-builder (agent/internal/builder/endpoints.go). Mounted under /api.
Route::prefix('internal')->group(function () {
    Route::middleware(AuthenticateBuilder::class)->group(function () {
        Route::get('builds/next', NextBuildController::class)->name('builds.internal.next');
        Route::post('builds/{build}/events', BuildEventsController::class)->name('builds.internal.events');
        Route::post('builds/{build}/heartbeat', BuildHeartbeatController::class)->name('builds.internal.heartbeat');
    });

    // Token server of the built-in registry (its realm): authorized by the basic-auth credential itself.
    Route::get('registry/token', RegistryTokenController::class)->middleware('throttle:600,1')->name('builds.registry.token');

    // Local artifact driver: authorized by the signed, expiring URL itself.
    Route::put('artifacts/{key}', [ArtifactController::class, 'upload'])->where('key', '.*')->name('builds.artifacts.upload');
    Route::get('artifacts/{key}', [ArtifactController::class, 'download'])->where('key', '.*')->name('builds.artifacts.download');
});
