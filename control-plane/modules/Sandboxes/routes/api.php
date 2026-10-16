<?php

use Falak\Sandboxes\Http\Controllers\Api\SandboxApiController;
use Illuminate\Support\Facades\Route;

// Mounted under /api; used by `falak sandbox …` and `falak mcp` (docs/plans/SANDBOXES.md "HTTP API"). The console
// route (POST v1/sandboxes/{sandbox}/console) is Terminal's.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    // Snapshots first: {sandbox} must never capture "snapshots".
    Route::get('sandboxes/snapshots', [SandboxApiController::class, 'snapshots'])->name('sandboxes.snapshots.index');
    Route::get('sandboxes/snapshots/{snapshot}', [SandboxApiController::class, 'showSnapshot'])->name('sandboxes.snapshots.show');
    Route::patch('sandboxes/snapshots/{snapshot}', [SandboxApiController::class, 'updateSnapshot'])->name('sandboxes.snapshots.update');
    Route::delete('sandboxes/snapshots/{snapshot}', [SandboxApiController::class, 'destroySnapshot'])->name('sandboxes.snapshots.destroy');
    Route::post('sandboxes/snapshots/{snapshot}/fork', [SandboxApiController::class, 'fork'])->middleware('throttle:30,1')->name('sandboxes.snapshots.fork');

    Route::get('sandboxes', [SandboxApiController::class, 'index'])->name('sandboxes.index');
    Route::post('sandboxes', [SandboxApiController::class, 'store'])->middleware('throttle:30,1')->name('sandboxes.store');
    Route::get('sandboxes/{sandbox}', [SandboxApiController::class, 'show'])->name('sandboxes.show');
    Route::patch('sandboxes/{sandbox}', [SandboxApiController::class, 'update'])->name('sandboxes.update');
    Route::delete('sandboxes/{sandbox}', [SandboxApiController::class, 'destroy'])->name('sandboxes.destroy');
    Route::post('sandboxes/{sandbox}/exec', [SandboxApiController::class, 'exec'])->middleware('throttle:120,1')->name('sandboxes.exec');
    Route::get('sandboxes/{sandbox}/files', [SandboxApiController::class, 'files'])->middleware('throttle:120,1')->name('sandboxes.files');
    Route::put('sandboxes/{sandbox}/files', [SandboxApiController::class, 'write'])->middleware('throttle:120,1')->name('sandboxes.files.write');
    Route::delete('sandboxes/{sandbox}/files', [SandboxApiController::class, 'deleteFile'])->middleware('throttle:120,1')->name('sandboxes.files.delete');
    Route::get('sandboxes/{sandbox}/execs', [SandboxApiController::class, 'execs'])->name('sandboxes.execs');
    Route::post('sandboxes/{sandbox}/snapshots', [SandboxApiController::class, 'snapshot'])->middleware('throttle:30,1')->name('sandboxes.snapshot');
    Route::post('sandboxes/{sandbox}/pause', [SandboxApiController::class, 'pause'])->middleware('throttle:60,1')->name('sandboxes.pause');
    Route::post('sandboxes/{sandbox}/resume', [SandboxApiController::class, 'resume'])->middleware('throttle:60,1')->name('sandboxes.resume');
    Route::post('sandboxes/{sandbox}/db', [SandboxApiController::class, 'db'])->middleware('throttle:10,1')->name('sandboxes.db');
});
