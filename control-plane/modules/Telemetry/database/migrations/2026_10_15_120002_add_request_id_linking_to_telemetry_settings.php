<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request ID linking (docs/plans/network/http.md): off until an organization turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_settings', function (Blueprint $table) {
            $table->boolean('request_id_linking')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_settings', function (Blueprint $table) {
            $table->dropColumn('request_id_linking');
        });
    }
};
