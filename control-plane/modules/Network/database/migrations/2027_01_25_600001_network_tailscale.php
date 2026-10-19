<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tailscale (docs/plans/TAILSCALE.md): the organization's auth key (encrypted) and one node per server on the tailnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_tailscale_settings', function (Blueprint $table) {
            $table->string('organization_id', 26)->primary();
            $table->text('auth_key');
            $table->timestamps();
        });

        Schema::create('network_tailscale_nodes', function (Blueprint $table) {
            $table->string('server_id', 26)->primary();
            $table->string('organization_id', 26)->index();
            // installing | active | error (leaving deletes the row)
            $table->string('status', 16)->default('installing');
            $table->string('hostname', 63);
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();
            $table->boolean('routes_approved')->default(false);
            $table->string('version', 32)->nullable();
            $table->string('error', 1000)->nullable();
            $table->string('command_id', 26)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_tailscale_nodes');
        Schema::dropIfExists('network_tailscale_settings');
    }
};
