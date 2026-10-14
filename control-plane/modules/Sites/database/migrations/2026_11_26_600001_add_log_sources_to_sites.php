<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            // Site → Logs → Sources (docs/plans/OBSERVABILITY.md phase 2): files inside the site's containers the agent
            // tails, and the PHP-FPM slow log threshold in seconds (null or 0 = off). Existing sites stay null: the agent
            // upgrade that brings the slow log must not rewrite their pools (a reload per site); new ones start at 5 s.
            $table->json('log_sources')->nullable()->after('compose_limits');
            $table->unsignedSmallInteger('fpm_slowlog_timeout')->nullable()->after('log_sources');
        });
    }

    public function down(): void
    {
        Schema::table('sites_sites', function (Blueprint $table) {
            $table->dropColumn(['log_sources', 'fpm_slowlog_timeout']);
        });
    }
};
