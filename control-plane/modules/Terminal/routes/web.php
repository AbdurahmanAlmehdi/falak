<?php

use Falak\Terminal\Http\Controllers\ConsoleController;
use Falak\Terminal\Http\Controllers\NetCaptureController;
use Falak\Terminal\Http\Controllers\NetToolController;
use Falak\Terminal\Http\Controllers\RecordingController;
use Falak\Terminal\Http\Controllers\SessionController;
use Falak\Terminal\Http\Controllers\StreamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('terminal', [SessionController::class, 'index'])->name('terminal.index');
    Route::get('servers/{server}/terminal', [SessionController::class, 'server'])->name('terminal.server');
    Route::post('terminal/servers/{server}/sessions', [SessionController::class, 'store'])->name('terminal.sessions.store');
    Route::get('terminal/sessions/{session}', [SessionController::class, 'show'])->name('terminal.sessions.show');
    Route::patch('terminal/sessions/{session}/share', [SessionController::class, 'share'])->name('terminal.sessions.share');
    Route::delete('terminal/sessions/{session}', [SessionController::class, 'destroy'])->name('terminal.sessions.destroy');

    Route::get('terminal/sessions/{session}/frames', [StreamController::class, 'frames'])->name('terminal.sessions.frames');
    Route::post('terminal/sessions/{session}/resize', [StreamController::class, 'resize'])->name('terminal.sessions.resize');

    Route::post('terminal/sessions/{session}/ticket', [ConsoleController::class, 'ticket'])->middleware('throttle:120,1')->name('terminal.sessions.ticket');

    // Consoles (docs/plans/CONSOLE.md): a site's shell or container, a database container.
    Route::get('sites/{site}/console', [ConsoleController::class, 'siteOptions'])->name('terminal.consoles.site.options');
    Route::post('sites/{site}/console', [ConsoleController::class, 'site'])->name('terminal.consoles.site');
    Route::post('databases/instances/{instance}/console', [ConsoleController::class, 'database'])->name('terminal.consoles.database');
    Route::post('sandboxes/{sandbox}/console', [ConsoleController::class, 'sandbox'])->name('terminal.consoles.sandbox');

    Route::get('terminal/sessions/{session}/recording', [RecordingController::class, 'show'])->name('terminal.sessions.recording');
    Route::get('terminal/sessions/{session}/recording.cast', [RecordingController::class, 'download'])->name('terminal.sessions.recording.cast');
});

// Keystroke hot path: its own rate limiter so typing never competes with the app-wide limits.
Route::middleware(['auth', 'org', 'throttle:terminal-input'])
    ->post('terminal/sessions/{session}/input', [StreamController::class, 'input'])
    ->name('terminal.sessions.input');

// Network tools (docs/plans/network/tools.md): runs are rate limited per user (the action also caps concurrent runs).
Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites/{site}/net-tools', [NetToolController::class, 'siteOptions'])->name('terminal.net-tools.site.options');
    Route::get('net-tools/runs', [NetToolController::class, 'index'])->name('terminal.net-tools.runs.index');
    Route::get('net-tools/runs/{run}', [NetToolController::class, 'show'])->name('terminal.net-tools.runs.show');

    Route::middleware('throttle:net-tools')->group(function () {
        Route::post('servers/{server}/net-tools', [NetToolController::class, 'server'])->name('terminal.net-tools.server');
        Route::post('sites/{site}/net-tools', [NetToolController::class, 'site'])->name('terminal.net-tools.site');
        Route::post('databases/instances/{instance}/net-tools', [NetToolController::class, 'database'])->name('terminal.net-tools.database');
    });
});

// Packet capture (docs/plans/network/capture.md): Admin only, rate limited per organization by the action.
Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites/{site}/net-captures', [NetCaptureController::class, 'siteOptions'])->name('terminal.net-captures.site.options');
    Route::get('net-captures', [NetCaptureController::class, 'index'])->name('terminal.net-captures.index');
    Route::get('net-captures/{capture}', [NetCaptureController::class, 'show'])->name('terminal.net-captures.show');
    Route::post('net-captures/{capture}/cancel', [NetCaptureController::class, 'cancel'])->name('terminal.net-captures.cancel');
    Route::get('net-captures/{capture}/download', [NetCaptureController::class, 'download'])->name('terminal.net-captures.download');

    Route::middleware('throttle:net-captures')->group(function () {
        Route::post('servers/{server}/net-captures', [NetCaptureController::class, 'server'])->name('terminal.net-captures.server');
        Route::post('sites/{site}/net-captures', [NetCaptureController::class, 'site'])->name('terminal.net-captures.site');
        Route::post('databases/instances/{instance}/net-captures', [NetCaptureController::class, 'database'])->name('terminal.net-captures.database');
    });
});
