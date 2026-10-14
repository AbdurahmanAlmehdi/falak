<?php

use Falak\Databases\Http\Controllers\BackupController;
use Falak\Databases\Http\Controllers\BackupScheduleController;
use Falak\Databases\Http\Controllers\DatabaseController;
use Falak\Databases\Http\Controllers\DatabaseInstanceController;
use Falak\Databases\Http\Controllers\DatabaseObservabilityController;
use Falak\Databases\Http\Controllers\DatabasePanelController;
use Falak\Databases\Http\Controllers\DatabaseUserController;
use Falak\Databases\Http\Controllers\PitrController;
use Falak\Databases\Http\Controllers\StorageProviderController;
use Falak\Kernel\Http\LegacyRedirect;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    // Backup storage lives in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects.
    Route::get('settings/storage', [StorageProviderController::class, 'index'])->name('databases.storage.index');
    Route::get('databases/storage', LegacyRedirect::to('/settings/storage'))->name('databases.storage.legacy');
});

Route::middleware(['auth', 'org'])->prefix('databases')->name('databases.')->group(function () {
    Route::get('/', [DatabaseInstanceController::class, 'index'])->name('index');

    Route::post('instances', [DatabaseInstanceController::class, 'store'])->name('instances.store');
    Route::get('instances/{instance}', [DatabaseInstanceController::class, 'show'])->name('instances.show');
    Route::put('instances/{instance}', [DatabaseInstanceController::class, 'update'])->name('instances.update');
    Route::post('instances/{instance}/restart', [DatabaseInstanceController::class, 'restart'])->name('instances.restart');
    Route::post('instances/{instance}/network', [DatabaseInstanceController::class, 'network'])->name('instances.network');
    Route::post('instances/{instance}/upgrade', [DatabaseInstanceController::class, 'upgrade'])->name('instances.upgrade');
    Route::post('instances/{instance}/password', [DatabaseInstanceController::class, 'password'])->name('instances.password');
    // Metrics, Logs and Insights tabs (docs/plans/OBSERVABILITY.md phase 3), JSON.
    Route::get('instances/{instance}/metrics/data', [DatabaseObservabilityController::class, 'metrics'])->name('instances.metrics');
    Route::get('instances/{instance}/logs/data', [DatabaseObservabilityController::class, 'logs'])->name('instances.logs');
    Route::get('instances/{instance}/insights', [DatabaseObservabilityController::class, 'insights'])->name('instances.insights');
    Route::post('instances/{instance}/query-insights', [DatabaseObservabilityController::class, 'enableQueryInsights'])->middleware('throttle:10,1')->name('instances.query-insights');
    Route::delete('instances/{instance}', [DatabaseInstanceController::class, 'destroy'])->name('instances.destroy');
    Route::post('instances/{instance}/databases', [DatabaseController::class, 'store'])->name('databases.store');
    Route::post('instances/{instance}/users', [DatabaseUserController::class, 'store'])->name('users.store');
    Route::post('instances/{instance}/schedules', [BackupScheduleController::class, 'store'])->name('schedules.store');
    Route::put('instances/{instance}/pitr', [PitrController::class, 'update'])->name('pitr.update');
    Route::post('instances/{instance}/pitr/base', [PitrController::class, 'base'])->middleware('throttle:10,1')->name('pitr.base');
    Route::post('instances/{instance}/pitr/restore', [PitrController::class, 'restore'])->middleware('throttle:10,1')->name('pitr.restore');
    Route::post('pitr-restores/{restore}/decision', [PitrController::class, 'decide'])->name('pitr.decide');
    Route::post('pitr-restores/{restore}/inspection', [PitrController::class, 'inspection'])->middleware('throttle:30,1')->name('pitr.inspection');

    Route::get('databases/{database}', [DatabasePanelController::class, 'show'])->name('databases.show');
    Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
    Route::post('databases/{database}/backups', [DatabaseController::class, 'backup'])->name('databases.backup');

    Route::put('users/{databaseUser}', [DatabaseUserController::class, 'update'])->name('users.update');
    Route::post('users/{databaseUser}/password', [DatabaseUserController::class, 'password'])->name('users.password');
    Route::post('users/{databaseUser}/reveal', [DatabaseUserController::class, 'reveal'])->middleware('throttle:30,1')->name('users.reveal');
    Route::delete('users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('users.destroy');

    Route::put('schedules/{backupSchedule}', [BackupScheduleController::class, 'update'])->name('schedules.update');
    Route::post('schedules/{backupSchedule}/run', [BackupScheduleController::class, 'run'])->name('schedules.run');
    Route::post('schedules/{backupSchedule}/drill', [BackupScheduleController::class, 'drill'])->middleware('throttle:10,1')->name('schedules.drill');
    Route::delete('schedules/{backupSchedule}', [BackupScheduleController::class, 'destroy'])->name('schedules.destroy');

    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->middleware('throttle:30,1')->name('backups.download');
    Route::post('backups/{backup}/key', [BackupController::class, 'exportKey'])->middleware(['reauthenticated', 'throttle:10,1'])->name('backups.key');
    Route::delete('backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');

    Route::post('storage', [StorageProviderController::class, 'store'])->name('storage.store');
    Route::put('storage/{storageProvider}', [StorageProviderController::class, 'update'])->name('storage.update');
    Route::post('storage/{storageProvider}/verify', [StorageProviderController::class, 'verify'])->name('storage.verify');
    Route::delete('storage/{storageProvider}', [StorageProviderController::class, 'destroy'])->name('storage.destroy');

    // Legacy / short link to one database: opens its canvas panel.
    Route::get('{database}', [DatabasePanelController::class, 'show'])->where('database', '[0-9A-Za-z]{26}')->name('databases.open');
});
