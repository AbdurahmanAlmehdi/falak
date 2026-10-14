<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network tool runs (docs/plans/network/tools.md): one row per net.tool command, the audit trail and the panel's
 * history. The output stays in Fleet's command output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_net_tool_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('user_id')->index();
            $table->ulid('server_id')->index();
            $table->string('server_name');
            $table->string('target_type', 16);
            $table->ulid('target_id')->nullable();
            $table->string('target_name')->nullable();
            $table->string('container')->nullable();
            $table->string('tool', 16);
            $table->json('input');
            $table->ulid('command_id')->nullable()->unique();
            $table->string('status', 16)->index();
            $table->integer('exit_code')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_net_tool_runs');
    }
};
