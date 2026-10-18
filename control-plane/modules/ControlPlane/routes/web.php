<?php

use Falak\ControlPlane\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('settings/falak-updates', [UpdateController::class, 'show'])->name('controlplane.updates');
    // Polled every 2 s while a host request runs.
    Route::get('settings/falak-updates/status', [UpdateController::class, 'status'])->middleware('throttle:120,1')->name('controlplane.updates.status');
    Route::post('settings/falak-updates/check', [UpdateController::class, 'check'])->middleware('throttle:6,1')->name('controlplane.updates.check');
    Route::post('settings/falak-updates', [UpdateController::class, 'update'])->middleware('throttle:3,1')->name('controlplane.updates.update');
});
