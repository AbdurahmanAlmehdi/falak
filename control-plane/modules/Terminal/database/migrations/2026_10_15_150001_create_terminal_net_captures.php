<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packet captures (docs/plans/network/capture.md): one row per net.capture command. The pcap itself is stored encrypted,
 * chunk by chunk, on the terminal.captures.disk and deleted when the capture expires (24 h).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_net_captures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('user_id')->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->string('target_type', 16);
            $table->ulid('target_id')->nullable();
            $table->string('target_name')->nullable();
            $table->string('container')->nullable();
            $table->string('interface', 16)->nullable();
            $table->json('filter')->nullable();
            $table->integer('snaplen');
            $table->integer('duration_s');
            $table->bigInteger('max_bytes');
            $table->ulid('command_id')->nullable()->unique();
            $table->string('status', 16)->index();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->bigInteger('bytes_captured')->default(0);
            $table->bigInteger('packets_captured')->default(0);
            $table->bigInteger('bytes_received')->default(0);
            $table->bigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('last_chunk_sha256', 64)->nullable();
            $table->text('data_key');
            $table->json('result')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['organization_id', 'target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_net_captures');
    }
};
