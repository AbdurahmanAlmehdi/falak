<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Move to another server": one site's move. steps holds each step's items and their state (SiteMoveRunner
        // advances them); batch_id groups a server's "Move all sites" (queued moves start one after another).
        Schema::create('recovery_site_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('site_name');
            $table->ulid('from_server_id')->index();
            $table->string('from_server_name');
            $table->ulid('target_server_id');
            $table->string('target_server_name');
            $table->ulid('storage_provider_id')->nullable();
            $table->ulid('batch_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('current_step', 32)->nullable();
            $table->json('steps');
            $table->ulid('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_site_moves');
    }
};
