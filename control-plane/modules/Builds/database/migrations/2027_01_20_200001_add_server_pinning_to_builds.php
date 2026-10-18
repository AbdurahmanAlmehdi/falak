<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-server builds: a build pinned to a server (builder_server_id) is only claimed by the builder running on that
 * server, and a builder installed for the on-server builds of a site server (`pool` false) claims nothing else.
 * `caps` records the systemd/BuildKit limits last rendered for a server builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->ulid('builder_server_id')->nullable()->index();
        });

        Schema::table('builds_builders', function (Blueprint $table) {
            $table->boolean('pool')->default(true);
            $table->json('caps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builds_builds', function (Blueprint $table) {
            $table->dropIndex(['builder_server_id']);
            $table->dropColumn('builder_server_id');
        });

        Schema::table('builds_builders', function (Blueprint $table) {
            $table->dropColumn(['pool', 'caps']);
        });
    }
};
