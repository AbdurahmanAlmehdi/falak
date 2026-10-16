<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.20: a server can opt out of sandboxes (docs/plans/SANDBOXES.md "Limits and quotas").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->boolean('sandboxes_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('servers_servers', function (Blueprint $table) {
            $table->dropColumn('sandboxes_enabled');
        });
    }
};
