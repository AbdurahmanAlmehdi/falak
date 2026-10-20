<?php

use Falak\Projects\Http\Controllers\Api\ProjectApiController;
use Illuminate\Support\Facades\Route;

// Same patterns as the web routes: ULID project ids, environment slugs or ids.
$patterns = ['project' => '[0-9A-Za-z]{26}', 'environment' => '[A-Za-z0-9][A-Za-z0-9-]{0,63}'];

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () use ($patterns) {
    Route::get('projects', [ProjectApiController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectApiController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectApiController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectApiController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectApiController::class, 'destroy'])->name('projects.destroy');

    Route::get('projects/{project}/environments', [ProjectApiController::class, 'environments'])->name('projects.environments.index');
    Route::post('projects/{project}/environments', [ProjectApiController::class, 'storeEnvironment'])->name('projects.environments.store');
    Route::patch('projects/{project}/environments/{environment}', [ProjectApiController::class, 'updateEnvironment'])->name('projects.environments.update');
    Route::delete('projects/{project}/environments/{environment}', [ProjectApiController::class, 'destroyEnvironment'])->name('projects.environments.destroy');
    // Same as the canvas' Create: a database (PostgreSQL, MySQL, MariaDB) or a Redis / Valkey instance, or a site.
    Route::post('projects/{project}/environments/{environment}/services', [ProjectApiController::class, 'storeService'])->where($patterns)->name('projects.services.store');
    // Rename a service or set its canvas icon (same as the canvas).
    Route::patch('projects/{project}/environments/{environment}/services/{service}', [ProjectApiController::class, 'updateService'])->where([...$patterns, 'service' => '[0-9A-Za-z]{26}'])->name('projects.services.update');
});
