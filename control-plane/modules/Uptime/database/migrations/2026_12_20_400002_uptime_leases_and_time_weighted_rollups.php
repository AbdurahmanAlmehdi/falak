<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uptime: check leases (claimed_at: a queued / running check, counted against the organization's in-flight cap),
 * resumed_at (an incident after a pause never starts before it), and time-weighted rollups (seconds / down_seconds:
 * each check stands for the time since the previous one, at most its interval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->index(['organization_id', 'claimed_at']);
        });

        Schema::table('uptime_rollups', function (Blueprint $table) {
            $table->unsignedBigInteger('seconds')->default(0);
            $table->unsignedBigInteger('down_seconds')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('uptime_rollups', function (Blueprint $table) {
            $table->dropColumn(['seconds', 'down_seconds']);
        });

        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'claimed_at']);
            $table->dropColumn(['claimed_at', 'resumed_at']);
        });
    }
};
