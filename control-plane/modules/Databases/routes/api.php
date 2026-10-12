<?php

use Falak\Databases\Http\Controllers\DatabaseInstanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('databases/instances', [DatabaseInstanceController::class, 'indexApi'])->name('databases.instances.index');
    Route::get('databases/instances/{instance}', [DatabaseInstanceController::class, 'showApi'])->name('databases.instances.show');
    Route::delete('databases/instances/{instance}', [DatabaseInstanceController::class, 'destroyApi'])->name('databases.instances.destroy');
});
