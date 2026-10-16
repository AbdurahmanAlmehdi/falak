<?php

use Falak\Sandboxes\Http\Controllers\Api\SandboxApiController;
use Illuminate\Support\Facades\Route;

// Mounted under /api; used by `falak sandbox …` and `falak mcp` (docs/plans/SANDBOXES.md "HTTP API"). The console
// route (POST v1/sandboxes/{sandbox}/console) is Terminal's.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
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
});
