<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consoles (docs/plans/CONSOLE.md): a session's target (server, site or database container) and whether the agent was
 * given a relay token. Existing rows are server shells.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_sessions', function (Blueprint $table) {
            $table->string('target_type', 16)->nullable()->default('server')->after('server_name');
            $table->ulid('target_id')->nullable()->after('target_type')->index();
            $table->string('target_name')->nullable()->after('target_id');
            $table->string('container')->nullable()->after('target_name');
            $table->boolean('relayed')->nullable()->default(false)->after('container');
        });
    }

    public function down(): void
    {
        Schema::table('terminal_sessions', function (Blueprint $table) {
            $table->dropIndex(['target_id']);
            $table->dropColumn(['target_type', 'target_id', 'target_name', 'container', 'relayed']);
        });
    }
};
