<?php

use Falak\Identity\Http\Controllers\Api\CliLoginController;
use Falak\Identity\Http\Controllers\Api\MeController;
use Falak\Identity\Http\Controllers\Api\OrganizationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->group(function () {
    Route::get('me', MeController::class)->name('api.v1.me');
    Route::get('organizations', OrganizationsController::class)->name('api.v1.organizations');
    Route::delete('me/token', [CliLoginController::class, 'revoke'])->name('api.v1.me.token.revoke');
});

// `falak login` in the browser: no token yet, so no auth; the device code is the secret.
Route::prefix('v1')->group(function () {
    Route::post('cli/login', [CliLoginController::class, 'start'])->middleware('throttle:10,1,cli-login')->name('api.v1.cli.login');
    Route::post('cli/login/token', [CliLoginController::class, 'token'])->middleware('throttle:60,1,cli-login-poll')->name('api.v1.cli.login.token');
});
