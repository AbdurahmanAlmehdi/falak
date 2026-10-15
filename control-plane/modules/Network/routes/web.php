<?php

use Falak\Network\Http\Controllers\ConnectionsController;
use Falak\Network\Http\Controllers\EbpfController;
use Falak\Network\Http\Controllers\FirewallController;
use Falak\Network\Http\Controllers\NetworkController;
use Falak\Network\Http\Controllers\PrivateNetworkController;
use Falak\Network\Http\Controllers\ProtectionController;
use Illuminate\Support\Facades\Route;

// Server page tabs (/servers/{id}/{tab}) owned by Network.
Route::middleware(['auth', 'org'])->group(function () {
    Route::get('servers/{server}/firewall', [FirewallController::class, 'show'])->name('network.firewall.show');
    Route::get('servers/{server}/network', [PrivateNetworkController::class, 'forServer'])->name('network.server');
});

Route::middleware(['auth', 'org'])->prefix('network')->name('network.')->group(function () {
    Route::get('/', [NetworkController::class, 'index'])->name('index');

    // Connections (docs/plans/network/connections.md): JSON for the server, site and database Network tabs.
    Route::get('servers/{server}/connections', [ConnectionsController::class, 'forServer'])->name('connections.server');
    Route::get('sites/{site}/connections', [ConnectionsController::class, 'site'])->name('connections.site');
    Route::get('databases/{instance}/connections', [ConnectionsController::class, 'database'])->name('connections.database');

    // eBPF (docs/plans/network/ebpf.md): the per-server setting and the TCP & DNS sections of the Network tabs.
    Route::put('servers/{server}/ebpf', [EbpfController::class, 'settings'])->name('ebpf.settings');
    Route::get('servers/{server}/ebpf', [EbpfController::class, 'forServer'])->name('ebpf.server');
    Route::get('sites/{site}/ebpf', [EbpfController::class, 'site'])->name('ebpf.site');
    Route::get('databases/{instance}/ebpf', [EbpfController::class, 'database'])->name('ebpf.database');

    // Legacy URL: the firewall is a tab of the server page now.
    Route::get('servers/{server}/firewall', fn (string $server) => redirect("/servers/{$server}/firewall", 301))->name('firewall.legacy');
    Route::post('servers/{server}/firewall/rules', [FirewallController::class, 'store'])->name('firewall.rules.store');
    Route::put('servers/{server}/firewall/rules/{rule}', [FirewallController::class, 'update'])->name('firewall.rules.update');
    Route::delete('servers/{server}/firewall/rules/{rule}', [FirewallController::class, 'destroy'])->name('firewall.rules.destroy');
    Route::post('servers/{server}/firewall/apply', [FirewallController::class, 'apply'])->name('firewall.apply');

    // Protection (docs/plans/network/protection.md).
    Route::get('blocked', [ProtectionController::class, 'index'])->name('blocked');
    Route::post('blocks', [ProtectionController::class, 'store'])->name('blocks.store');
    Route::delete('blocks/{block}', [ProtectionController::class, 'destroy'])->name('blocks.destroy');
    Route::put('servers/{server}/protection', [ProtectionController::class, 'settings'])->name('protection.settings');

    Route::post('private-networks', [PrivateNetworkController::class, 'store'])->name('private-networks.store');
    Route::get('private-networks/{network}', [PrivateNetworkController::class, 'show'])->name('private-networks.show');
    Route::delete('private-networks/{network}', [PrivateNetworkController::class, 'destroy'])->name('private-networks.destroy');
    Route::post('private-networks/{network}/apply', [PrivateNetworkController::class, 'apply'])->name('private-networks.apply');
    Route::post('private-networks/{network}/members', [PrivateNetworkController::class, 'addMember'])->name('private-networks.members.store');
    Route::delete('private-networks/{network}/members/{member}', [PrivateNetworkController::class, 'removeMember'])->name('private-networks.members.destroy');
});
