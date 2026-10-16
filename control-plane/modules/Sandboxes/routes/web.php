<?php

use Falak\Sandboxes\Http\Controllers\Api\SandboxApiController;
use Falak\Sandboxes\Http\Controllers\SandboxPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    // The pages' actions: the API's JSON routes under the session (snapshots first: {sandbox} never captures them).
    Route::patch('sandboxes/snapshots/{snapshot}', [SandboxApiController::class, 'updateSnapshot'])->name('sandboxes.snapshots.update');
    Route::delete('sandboxes/snapshots/{snapshot}', [SandboxApiController::class, 'destroySnapshot'])->name('sandboxes.snapshots.destroy');
    Route::post('sandboxes/snapshots/{snapshot}/fork', [SandboxApiController::class, 'fork'])->middleware('throttle:30,1')->name('sandboxes.snapshots.fork');

    Route::get('sandboxes', [SandboxPageController::class, 'index'])->name('sandboxes.index');
    Route::get('sandboxes/{sandbox}', [SandboxPageController::class, 'show'])->name('sandboxes.show');

    Route::post('sandboxes', [SandboxApiController::class, 'store'])->middleware('throttle:30,1')->name('sandboxes.store');
    Route::patch('sandboxes/{sandbox}', [SandboxApiController::class, 'update'])->name('sandboxes.update');
    Route::delete('sandboxes/{sandbox}', [SandboxApiController::class, 'destroy'])->name('sandboxes.destroy');
    Route::get('sandboxes/{sandbox}/files', [SandboxApiController::class, 'files'])->middleware('throttle:120,1')->name('sandboxes.files');
    Route::post('sandboxes/{sandbox}/snapshots', [SandboxApiController::class, 'snapshot'])->middleware('throttle:30,1')->name('sandboxes.snapshot');
    Route::post('sandboxes/{sandbox}/pause', [SandboxApiController::class, 'pause'])->middleware('throttle:60,1')->name('sandboxes.pause');
    Route::post('sandboxes/{sandbox}/resume', [SandboxApiController::class, 'resume'])->middleware('throttle:60,1')->name('sandboxes.resume');
    Route::post('sandboxes/{sandbox}/db', [SandboxApiController::class, 'db'])->middleware('throttle:10,1')->name('sandboxes.db');
});
