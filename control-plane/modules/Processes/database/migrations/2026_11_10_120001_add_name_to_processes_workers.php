<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes_workers', function (Blueprint $table) {
            // The worker's key in falak.yaml (`workers.<name>`); null for workers made in the dashboard.
            $table->string('name', 64)->nullable()->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('processes_workers', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
