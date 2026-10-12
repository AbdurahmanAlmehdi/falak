<?php

use Falak\Servers\Http\Controllers\Api\ServerApiController;
use Falak\Servers\Http\Controllers\MachineCheckController;
use Falak\Servers\Http\Controllers\PhpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('servers', [ServerApiController::class, 'index'])->name('servers.index');
    Route::post('servers', [ServerApiController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServerApiController::class, 'show'])->name('servers.show');
    Route::delete('servers/{server}', [ServerApiController::class, 'destroy'])->name('servers.destroy');
    Route::post('servers/{server}/php', [PhpController::class, 'storeApi'])->middleware('throttle:10,1')->name('servers.php.store');
    Route::post('servers/{server}/agent/upgrade', [ServerApiController::class, 'upgradeAgent'])->middleware('throttle:30,1')->name('servers.agent.upgrade');
    Route::get('servers/{server}/inspection', [MachineCheckController::class, 'show'])->name('servers.inspection.show');
    Route::post('servers/{server}/inspection', [MachineCheckController::class, 'storeApi'])->middleware('throttle:10,1')->name('servers.inspection.store');
    Route::post('servers/{server}/provision', [MachineCheckController::class, 'provisionApi'])->middleware('throttle:10,1')->name('servers.provision');
    Route::post('servers/{server}/reprovision', [MachineCheckController::class, 'reprovisionApi'])->middleware('throttle:10,1')->name('servers.reprovision');
});
