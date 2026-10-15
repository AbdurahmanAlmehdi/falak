<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network protection (docs/plans/network/protection.md): blocked addresses, their hits per server, per-server apply
 * state and auto-ban settings, and the firewall rules' counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_ip_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->nullable()->index();
            $table->string('cidr', 43);
            $table->string('family', 4);
            $table->string('reason', 500)->nullable();
            $table->string('source', 8);
            $table->timestamp('expires_at')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->json('meta')->nullable();
            // Set when the prefix became protected after it was blocked (a new server, a member's address): no longer
            // applied, kept for the record.
            $table->string('suspended_reason', 500)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'cidr']);
        });

        Schema::create('network_ip_block_hits', function (Blueprint $table) {
            $table->foreignUlid('block_id')->constrained('network_ip_blocks')->cascadeOnDelete();
            $table->ulid('server_id');
            $table->unsignedBigInteger('packets')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->primary(['block_id', 'server_id']);
        });

        Schema::create('network_protection_states', function (Blueprint $table) {
            $table->ulid('server_id')->primary();
            $table->ulid('organization_id')->index();
            $table->boolean('auto_ban')->default(false);
            $table->unsignedInteger('failures')->default(10);
            $table->unsignedInteger('window_s')->default(600);
            $table->unsignedInteger('ban_s')->default(3600);
            $table->unsignedInteger('revision')->default(0);
            $table->char('desired_hash', 64)->nullable();
            $table->char('applied_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->ulid('command_id')->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->json('failed')->nullable();
            $table->boolean('set_counters')->default(true);
            $table->json('released')->nullable();
            $table->unsignedBigInteger('blocked_packets')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('network_firewall_states', function (Blueprint $table) {
            $table->json('counters')->nullable();
            $table->timestamp('counters_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_firewall_states', function (Blueprint $table) {
            $table->dropColumn(['counters', 'counters_at']);
        });
        Schema::dropIfExists('network_protection_states');
        Schema::dropIfExists('network_ip_block_hits');
        Schema::dropIfExists('network_ip_blocks');
    }
};
