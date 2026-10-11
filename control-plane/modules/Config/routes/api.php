<?php

use Falak\Config\Http\Controllers\Api\ConfigApiController;
use Illuminate\Support\Facades\Route;

// Mounted under /api (docs/API.md "Config as code").
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.config.')->group(function () {
    Route::post('projects/{project}/config/validate', [ConfigApiController::class, 'validateFile'])->middleware('throttle:60,1')->name('validate');
    Route::post('projects/{project}/config/plan', [ConfigApiController::class, 'plan'])->middleware('throttle:30,1')->name('plan');
    Route::post('projects/{project}/config/runs', [ConfigApiController::class, 'store'])->middleware('throttle:10,1')->name('runs.store');
    Route::get('projects/{project}/config/runs', [ConfigApiController::class, 'index'])->name('runs.index');
    Route::get('projects/{project}/config/export', [ConfigApiController::class, 'export'])->name('export');
    Route::get('config/runs/{run}', [ConfigApiController::class, 'show'])->name('runs.show');
    Route::post('config/runs/{run}/approve', [ConfigApiController::class, 'approve'])->middleware('throttle:30,1')->name('runs.approve');
    Route::post('config/runs/{run}/cancel', [ConfigApiController::class, 'cancel'])->middleware('throttle:30,1')->name('runs.cancel');
});
