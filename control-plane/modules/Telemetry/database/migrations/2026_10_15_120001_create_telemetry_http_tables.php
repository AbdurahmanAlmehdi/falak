<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HTTP analytics (docs/plans/network/http.md): per-site thresholds and hourly bandwidth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_http_settings', function (Blueprint $table) {
            $table->ulid('site_id')->primary();
            $table->ulid('organization_id')->index();
            $table->unsignedInteger('slow_ms')->nullable();
            $table->unsignedInteger('slow_min_requests')->nullable();
            $table->unsignedBigInteger('bandwidth_alert_bytes')->nullable();
            $table->timestamps();
        });

        // Bytes per site (slug: the access log's and the metrics' key) and UTC hour.
        Schema::create('telemetry_http_bandwidth', function (Blueprint $table) {
            $table->ulid('organization_id');
            $table->string('site_slug', 63);
            $table->timestamp('hour');
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
            $table->primary(['organization_id', 'site_slug', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_http_bandwidth');
        Schema::dropIfExists('telemetry_http_settings');
    }
};
