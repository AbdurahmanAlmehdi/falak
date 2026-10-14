<?php

use Falak\Uptime\Http\Controllers\MonitorController;
use Falak\Uptime\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// /observability/uptime: tab of the Observability page.
Route::middleware(['auth', 'org'])->prefix('observability/uptime')->name('observability.uptime.')->group(function () {
    Route::get('/', [MonitorController::class, 'index'])->name('index');
    Route::get('status-pages', [StatusPageController::class, 'index'])->name('status-pages');
});

Route::middleware(['auth', 'org'])->prefix('uptime')->name('uptime.')->group(function () use ($ulid) {
    // Service panel Uptime tab (JSON).
    Route::get('sites/{siteId}', [MonitorController::class, 'site'])->where('siteId', $ulid)->name('sites.show');

    Route::post('monitors', [MonitorController::class, 'store'])->name('monitors.store');
    Route::post('monitors/sync', [MonitorController::class, 'sync'])->name('monitors.sync');
    Route::get('monitors/{monitor}/data', [MonitorController::class, 'data'])->name('monitors.data');
    Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
    Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause'])->name('monitors.pause');
    Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume'])->name('monitors.resume');
    Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');

    Route::put('status-pages/{projectId}', [StatusPageController::class, 'save'])->where('projectId', $ulid)->name('status-pages.save');
    Route::delete('status-pages/{page}', [StatusPageController::class, 'destroy'])->name('status-pages.destroy');
});
