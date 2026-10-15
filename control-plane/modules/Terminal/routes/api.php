<?php

use Falak\Terminal\Http\Controllers\Api\ConsoleApiController;
use Falak\Terminal\Http\Controllers\NetCaptureController;
use Falak\Terminal\Http\Controllers\NetToolController;
use Illuminate\Support\Facades\Route;

// `falak console` (docs/plans/CONSOLE.md): open a console and dial the relay URL it answers with.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites/{site}/console', [ConsoleApiController::class, 'siteOptions'])->name('sites.console.options');
    Route::post('sites/{site}/console', [ConsoleApiController::class, 'site'])->middleware('throttle:30,1')->name('sites.console');
    Route::post('databases/instances/{instance}/console', [ConsoleApiController::class, 'database'])->middleware('throttle:30,1')->name('databases.instances.console');
    Route::post('servers/{server}/console', [ConsoleApiController::class, 'server'])->middleware('throttle:30,1')->name('servers.console');
    Route::post('terminal/sessions/{session}/ticket', [ConsoleApiController::class, 'ticket'])->middleware('throttle:120,1')->name('terminal.sessions.ticket');
    Route::delete('terminal/sessions/{session}', [ConsoleApiController::class, 'destroy'])->name('terminal.sessions.destroy');
});

// `falak net` (docs/plans/network/tools.md): run a tool, then poll the run for its output.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('net-tools/runs/{run}', [NetToolController::class, 'show'])->name('net-tools.runs.show');

    Route::middleware('throttle:net-tools')->group(function () {
        Route::post('servers/{server}/net-tools', [NetToolController::class, 'server'])->name('servers.net-tools');
        Route::post('sites/{site}/net-tools', [NetToolController::class, 'site'])->name('sites.net-tools');
        Route::post('databases/instances/{instance}/net-tools', [NetToolController::class, 'database'])->name('databases.instances.net-tools');
    });
});

// `falak net capture` (docs/plans/network/capture.md): start, follow, cancel, download.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('net-captures', [NetCaptureController::class, 'index'])->name('net-captures.index');
    Route::get('net-captures/{capture}', [NetCaptureController::class, 'show'])->name('net-captures.show');
    Route::post('net-captures/{capture}/cancel', [NetCaptureController::class, 'cancel'])->name('net-captures.cancel');
    Route::get('net-captures/{capture}/download', [NetCaptureController::class, 'download'])->name('net-captures.download');

    Route::middleware('throttle:net-captures')->group(function () {
        Route::post('servers/{server}/net-captures', [NetCaptureController::class, 'server'])->name('servers.net-captures');
        Route::post('sites/{site}/net-captures', [NetCaptureController::class, 'site'])->name('sites.net-captures');
        Route::post('databases/instances/{instance}/net-captures', [NetCaptureController::class, 'database'])->name('databases.instances.net-captures');
    });
});
