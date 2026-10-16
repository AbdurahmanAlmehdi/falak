<?php

use Falak\Sandboxes\Http\Controllers\Api\SandboxApiController;
use Falak\Sandboxes\Http\Controllers\SandboxPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sandboxes', [SandboxPageController::class, 'index'])->name('sandboxes.index');
    Route::get('sandboxes/{sandbox}', [SandboxPageController::class, 'show'])->name('sandboxes.show');

    // The pages' actions: the API's JSON routes under the session.
    Route::post('sandboxes', [SandboxApiController::class, 'store'])->middleware('throttle:30,1')->name('sandboxes.store');
    Route::patch('sandboxes/{sandbox}', [SandboxApiController::class, 'update'])->name('sandboxes.update');
    Route::delete('sandboxes/{sandbox}', [SandboxApiController::class, 'destroy'])->name('sandboxes.destroy');
    Route::get('sandboxes/{sandbox}/files', [SandboxApiController::class, 'files'])->middleware('throttle:120,1')->name('sandboxes.files');
});
