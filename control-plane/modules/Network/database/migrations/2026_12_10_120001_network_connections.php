<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connections (docs/plans/network/connections.md): the agent's latest net.observed report per server (overwritten
 * every minute: it carries peer IPs, kept nowhere else), the probes' latency baselines, the hash of the last
 * net.connections.configure sent; and the reverse DNS names of external peers (cached a day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_observations', function (Blueprint $table) {
            $table->string('server_id', 26)->primary();
            $table->string('organization_id', 26)->index();
            $table->json('report')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->json('baselines')->nullable();
            $table->string('config_hash', 64)->nullable();
            $table->string('config_pending_hash', 64)->nullable();
            $table->string('config_command_id', 26)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('network_peer_names', function (Blueprint $table) {
            $table->string('address', 45)->primary();
            $table->string('name')->nullable();
            $table->timestamp('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_peer_names');
        Schema::dropIfExists('network_observations');
    }
};
