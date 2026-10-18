<?php

use Falak\Servers\Http\Controllers\MachineCheckController;
use Falak\Servers\Http\Controllers\PhpController;
use Falak\Servers\Http\Controllers\ServerAgentController;
use Falak\Servers\Http\Controllers\ServerController;
use Falak\Servers\Http\Controllers\ServerTabController;
use Falak\Servers\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::get('servers/search', [ServerController::class, 'search'])->name('servers.search');
    Route::post('servers/agents/upgrade', [ServerAgentController::class, 'upgradeAll'])->name('servers.agents.upgrade');
    Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('servers/{server}/reprovision', [ServerController::class, 'reprovision'])->name('servers.reprovision');
    Route::post('servers/{server}/inspection', [MachineCheckController::class, 'store'])->middleware('throttle:10,1')->name('servers.inspection.store');
    Route::post('servers/{server}/provision', [MachineCheckController::class, 'provision'])->name('servers.provision');
    Route::post('servers/{server}/agent/upgrade', [ServerAgentController::class, 'upgrade'])->name('servers.agent.upgrade');
    Route::post('servers/{server}/install-command', [ServerController::class, 'installCommand'])->name('servers.install-command');
    Route::post('servers/{server}/local-server/retry', [ServerController::class, 'retryLocalServer'])->middleware('throttle:6,1')->name('servers.local-server.retry');
    Route::get('servers/{server}/metrics', [ServerController::class, 'metrics'])->name('servers.metrics');

    // Server page tabs owned by Servers (/servers/{id}/{tab}); Network, Terminal and Recipes register theirs.
    Route::get('servers/{server}/overview', [ServerTabController::class, 'overview'])->name('servers.overview');
    Route::get('servers/{server}/processes', [ServerTabController::class, 'processes'])->name('servers.processes');
    Route::get('servers/{server}/ssh-keys', [ServerTabController::class, 'sshKeys'])->name('servers.ssh-keys');
    Route::get('servers/{server}/php', [ServerTabController::class, 'php'])->name('servers.php');
    Route::get('servers/{server}/settings', [ServerTabController::class, 'settings'])->name('servers.settings');

    Route::post('servers/{server}/php', [PhpController::class, 'store'])->name('servers.php.store');
    Route::put('servers/{server}/php/{version}/default', [PhpController::class, 'default'])->name('servers.php.default');
    Route::put('servers/{server}/php/{version}/settings', [PhpController::class, 'settings'])->name('servers.php.settings');
    Route::delete('servers/{server}/php/{version}', [PhpController::class, 'destroy'])->name('servers.php.destroy');

    Route::post('servers/{server}/ssh-keys', [SshKeyController::class, 'attach'])->name('servers.ssh-keys.attach');
    Route::delete('servers/{server}/ssh-keys/{sshKey}', [SshKeyController::class, 'detach'])->name('servers.ssh-keys.detach');

    Route::get('ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
    Route::post('ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
    Route::delete('ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');
});
