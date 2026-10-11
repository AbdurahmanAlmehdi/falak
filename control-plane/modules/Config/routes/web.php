<?php

use Falak\Config\Http\Controllers\Api\DriftApiController;
use Falak\Config\Http\Controllers\ConfigController;
use Falak\Config\Http\Controllers\UnmanagedController;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-Za-z]{26}';

Route::middleware(['auth', 'org'])->group(function () use ($ulid) {
    Route::get('projects/{project}/settings/config', [ConfigController::class, 'show'])->where('project', $ulid)->name('config.show');
    Route::put('projects/{project}/settings/config', [ConfigController::class, 'update'])->where('project', $ulid)->name('config.update');
    Route::post('projects/{project}/settings/config/apply', [ConfigController::class, 'apply'])->where('project', $ulid)->middleware('throttle:10,1')->name('config.apply');
    Route::get('config/runs/{run}', [ConfigController::class, 'run'])->where('run', $ulid)->name('config.runs.show');
    Route::post('config/runs/{run}/cancel', [ConfigController::class, 'cancel'])->where('run', $ulid)->name('config.runs.cancel');
    Route::post('config/runs/{run}/approve', [ConfigController::class, 'approve'])->where('run', $ulid)->name('config.runs.approve');
    Route::post('config/runs/{run}/reject', [ConfigController::class, 'reject'])->where('run', $ulid)->name('config.runs.reject');
    Route::get('projects/{project}/settings/config/drift', [DriftApiController::class, 'show'])->where('project', $ulid)->name('config.drift.show');
    Route::post('projects/{project}/settings/config/drift', [DriftApiController::class, 'check'])->where('project', $ulid)->middleware('throttle:10,1')->name('config.drift.check');
    Route::get('projects/{project}/settings/config/unmanaged', [UnmanagedController::class, 'index'])->where('project', $ulid)->name('config.unmanaged.index');
    Route::delete('config/unmanaged/{item}', [UnmanagedController::class, 'dismiss'])->where('item', $ulid)->name('config.unmanaged.dismiss');
});
