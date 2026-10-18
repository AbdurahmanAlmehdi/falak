<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23: the control plane's own machine as a server ("This machine" in Add server). At most one per install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->boolean('control_plane_host')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->dropColumn('control_plane_host');
        });
    }
};
