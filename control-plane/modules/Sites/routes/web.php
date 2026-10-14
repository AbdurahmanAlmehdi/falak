<?php

use Falak\Sites\Http\Controllers\ComposeController;
use Falak\Sites\Http\Controllers\ComposePolicyController;
use Falak\Sites\Http\Controllers\ComposeRepositoryController;
use Falak\Sites\Http\Controllers\DeployScriptController;
use Falak\Sites\Http\Controllers\EnvironmentController;
use Falak\Sites\Http\Controllers\SiteCommandController;
use Falak\Sites\Http\Controllers\SiteController;
use Falak\Sites\Http\Controllers\SiteLimitsController;
use Falak\Sites\Http\Controllers\SiteLogSourcesController;
use Falak\Sites\Http\Controllers\SiteSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/create', [SiteController::class, 'create'])->name('sites.create');
    Route::get('sites/search', [SiteController::class, 'search'])->name('sites.search');
    Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    Route::get('sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings');
    Route::patch('sites/{site}', [SiteSettingsController::class, 'update'])->name('sites.update');
    Route::put('sites/{site}/targets', [SiteSettingsController::class, 'updateTargets'])->name('sites.targets.update');
    Route::post('sites/{site}/targets/{target}/retry', [SiteSettingsController::class, 'retryTarget'])->name('sites.targets.retry');
    Route::put('sites/{site}/shared-paths', [SiteSettingsController::class, 'sharedPaths'])->name('sites.shared-paths.update');
    Route::put('sites/{site}/laravel', [SiteSettingsController::class, 'laravel'])->name('sites.laravel.update');
    Route::get('sites/{site}/limits', [SiteLimitsController::class, 'show'])->name('sites.limits');
    Route::put('sites/{site}/limits', [SiteLimitsController::class, 'update'])->name('sites.limits.update');
    Route::put('sites/{site}/compose/services/{service}/limits', [SiteLimitsController::class, 'update'])->where('service', '[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}')->name('sites.compose.limits.update');
    Route::get('sites/{site}/log-sources', [SiteLogSourcesController::class, 'show'])->name('sites.log-sources');
    Route::put('sites/{site}/log-sources', [SiteLogSourcesController::class, 'update'])->name('sites.log-sources.update');

    Route::get('sites/{site}/environment', [EnvironmentController::class, 'show'])->name('sites.environment');
    Route::put('sites/{site}/environment', [EnvironmentController::class, 'update'])->name('sites.environment.update');
    Route::patch('sites/{site}/environment', [EnvironmentController::class, 'patch'])->name('sites.environment.patch');
    Route::post('sites/{site}/environment/reveal', [EnvironmentController::class, 'reveal'])->middleware('throttle:30,1')->name('sites.environment.reveal');
    Route::post('sites/{site}/environment/versions/{version}/restore', [EnvironmentController::class, 'restore'])->whereNumber('version')->name('sites.environment.restore');

    Route::get('sites/{site}/compose', [ComposeController::class, 'show'])->name('sites.compose');
    Route::put('sites/{site}/compose', [ComposeController::class, 'update'])->name('sites.compose.update');
    Route::post('sites/{site}/compose/validate', [ComposeController::class, 'validateContent'])->middleware('throttle:120,1')->name('sites.compose.validate');
    Route::post('sites/{site}/compose/inspect', [ComposeRepositoryController::class, 'inspectSite'])->middleware('throttle:60,1')->name('sites.compose.inspect');
    Route::post('sites/{site}/compose/candidates', [ComposeRepositoryController::class, 'candidatesForSite'])->middleware('throttle:60,1')->name('sites.compose.candidates');
    Route::post('sites/compose/candidates', [ComposeRepositoryController::class, 'candidates'])->middleware('throttle:60,1')->name('sites.compose-repo.candidates');
    Route::post('sites/compose/inspect', [ComposeRepositoryController::class, 'inspect'])->middleware('throttle:60,1')->name('sites.compose-repo.inspect');
    Route::get('sites/{site}/compose/versions/{version}', [ComposeController::class, 'version'])->whereNumber('version')->name('sites.compose.version');
    Route::post('sites/{site}/compose/versions/{version}/restore', [ComposeController::class, 'restore'])->whereNumber('version')->name('sites.compose.restore');
    Route::get('sites/{site}/compose/services', [ComposeController::class, 'services'])->name('sites.compose.services');
    Route::post('sites/{site}/compose/restart', [ComposeController::class, 'restart'])->middleware('throttle:30,1')->name('sites.compose.restart');

    Route::get('settings/compose', [ComposePolicyController::class, 'show'])->name('sites.compose-policy');
    Route::put('settings/compose', [ComposePolicyController::class, 'update'])->name('sites.compose-policy.update');

    Route::get('sites/{site}/deploy-script', [DeployScriptController::class, 'show'])->name('sites.deploy-script');
    Route::put('sites/{site}/deploy-script', [DeployScriptController::class, 'update'])->name('sites.deploy-script.update');

    Route::get('sites/{site}/commands', [SiteCommandController::class, 'index'])->name('sites.commands');
    Route::post('sites/{site}/commands', [SiteCommandController::class, 'store'])->middleware('throttle:60,1')->name('sites.commands.store');
});
