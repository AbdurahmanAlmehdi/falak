<?php

use Falak\Terminal\Http\Controllers\Api\ConsoleApiController;
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
