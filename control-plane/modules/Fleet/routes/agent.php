<?php

use Falak\Fleet\Http\Controllers\Agent\CommandEventsController;
use Falak\Fleet\Http\Controllers\Agent\CommandPollController;
use Falak\Fleet\Http\Controllers\Agent\EnrollController;
use Falak\Fleet\Http\Controllers\Agent\HeartbeatController;
use Falak\Fleet\Http\Controllers\Agent\InsightsController;
use Falak\Fleet\Http\Controllers\Agent\PingController;
use Falak\Fleet\Http\Controllers\Agent\RenewController;
use Falak\Fleet\Http\Controllers\Agent\RequestController;
use Falak\Fleet\Http\Controllers\Agent\UploadController;
use Falak\Fleet\Http\Middleware\AuthenticateAgent;
use Falak\Fleet\Http\Middleware\ForceJson;
use Illuminate\Support\Facades\Route;

// Mounted at /agent/v1 by the Kernel. Contract: contracts/agent-protocol/README.md.

Route::middleware(ForceJson::class)->name('fleet.agent.')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:fleet-enroll')->name('enroll');

    Route::middleware(AuthenticateAgent::class)->group(function () {
        Route::get('ping', PingController::class)->name('ping');
        Route::post('renew', RenewController::class)->name('renew');
        Route::post('heartbeat', HeartbeatController::class)->middleware('throttle:fleet-agent-heartbeat')->name('heartbeat');
        Route::get('commands', CommandPollController::class)->middleware('throttle:fleet-agent-commands')->name('commands');
        Route::post('commands/{command}/events', CommandEventsController::class)->middleware('throttle:fleet-agent-events')->name('commands.events');
        Route::post('insights', InsightsController::class)->middleware('throttle:fleet-agent-insights')->name('insights');
        Route::post('requests/{type}', RequestController::class)->where('type', '[a-z_]+(\.[a-z_]+)+')->middleware('throttle:fleet-agent-requests')->name('requests');
        Route::post('uploads/{type}/{id}', UploadController::class)->where('type', '[a-z_]+(\.[a-z_]+)+')->where('id', '[A-Za-z0-9_-]{1,64}')->middleware('throttle:fleet-agent-uploads')->name('uploads');
    });
});
