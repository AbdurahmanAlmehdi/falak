<?php

use Falak\Kernel\Http\LegacyRedirect;
use Falak\SourceControl\Http\Controllers\ConnectionController;
use Falak\SourceControl\Http\Controllers\GitHubAppController;
use Falak\SourceControl\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    // The page lives in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects.
    Route::get('settings/source-control', [ConnectionController::class, 'index'])->name('source-control.index');
    Route::get('source-control', LegacyRedirect::to('/settings/source-control'))->name('source-control.legacy');
});

Route::middleware(['auth', 'org'])->prefix('source-control')->name('source-control.')->group(function () {
    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
    Route::get('connections/{connection}/repositories', [ConnectionController::class, 'repositories'])->name('connections.repositories');
    Route::get('connections/{connection}/branches', [ConnectionController::class, 'branches'])->name('connections.branches');

    // GitHub App: manifest registration, installation and GitHub's setup URL (kept stable for existing apps).
    Route::post('github-app/manifest', [GitHubAppController::class, 'manifest'])->name('github-app.manifest');
    Route::get('github-app/manifest/callback', [GitHubAppController::class, 'manifestCallback'])->name('github-app.manifest.callback');
    Route::get('connect/github-app', [GitHubAppController::class, 'install'])->name('github-app');
    Route::get('github-app/setup', [GitHubAppController::class, 'setup'])->name('github-app.setup');
    Route::delete('github-app/{app}', [GitHubAppController::class, 'destroy'])->name('github-app.destroy');
    Route::get('connect/{provider}', [OAuthController::class, 'redirect'])->name('connect');
    Route::get('callback/{provider}', [OAuthController::class, 'callback'])->name('callback');
});
