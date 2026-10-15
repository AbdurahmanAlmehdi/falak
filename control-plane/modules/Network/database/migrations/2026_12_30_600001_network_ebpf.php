<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eBPF (docs/plans/network/ebpf.md): the per-server setting (default off) and the delivery of net.ebpf.configure. The
 * probe's own status is the agent's facts.ebpf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_ebpf_settings', function (Blueprint $table) {
            $table->string('server_id', 26)->primary();
            $table->string('organization_id', 26)->index();
            $table->boolean('enabled')->default(false);
            // Whole DNS names as metric labels (default off: their registrable domain).
            $table->boolean('full_dns_names')->default(false);
            // An explicit re-enable after the agent stopped the probe over its budget, until delivered.
            $table->boolean('reenable')->default(false);
            // pending (to send or in flight) | applied | failed
            $table->string('status', 16)->default('pending');
            $table->string('command_id', 26)->nullable()->index();
            // sha256 of {enabled, full_dns_names}: in flight, and as the agent applied it.
            $table->char('pending_hash', 64)->nullable();
            $table->char('applied_hash', 64)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_ebpf_settings');
    }
};
