<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uptime (docs/plans/network/uptime.md). uptime_checks is the volume table (one row per check, kept 7 days, pruned by
 * checked_at); uptime_rollups holds hourly / daily counters per monitor for uptime % and charts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id')->nullable();
            $table->string('source', 8);
            // Automatic monitors: "site:<site id>:<domain>" (unique per organization).
            $table->string('auto_key')->nullable();
            $table->string('type', 8);
            $table->string('name', 120);
            $table->string('url', 2048)->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('expected_status', 64)->default('2xx,3xx');
            $table->string('keyword', 200)->nullable();
            $table->boolean('keyword_absent')->default(false);
            $table->unsignedTinyInteger('max_redirects')->default(5);
            $table->unsignedTinyInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('interval_seconds')->default(60);
            $table->unsignedInteger('slow_ms')->nullable();
            $table->string('status', 8)->default('pending');
            $table->boolean('armed')->default(false);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('last_total_ms')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error_kind', 16)->nullable();
            $table->string('last_error', 300)->nullable();
            $table->json('tls')->nullable();
            $table->timestamp('tls_checked_at')->nullable();
            $table->timestamp('flapping_until')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'auto_key']);
            $table->index(['organization_id', 'site_id']);
            $table->index(['paused_at', 'next_check_at']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->ulid('monitor_id');
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->boolean('down')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error_kind', 16)->nullable();
            $table->string('error', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('dns_ms')->nullable();
            $table->unsignedInteger('connect_ms')->nullable();
            $table->unsignedInteger('tls_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);

            $table->index(['monitor_id', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('uptime_rollups', function (Blueprint $table) {
            $table->ulid('monitor_id');
            $table->string('period', 4);
            $table->timestamp('bucket_start');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('down_checks')->default(0);
            $table->unsignedBigInteger('latency_sum_ms')->default(0);
            $table->unsignedInteger('latency_count')->default(0);
            $table->unsignedInteger('latency_max_ms')->default(0);
            $table->unsignedBigInteger('dns_sum_ms')->default(0);
            $table->unsignedBigInteger('connect_sum_ms')->default(0);
            $table->unsignedBigInteger('tls_sum_ms')->default(0);
            $table->unsignedBigInteger('ttfb_sum_ms')->default(0);

            $table->primary(['monitor_id', 'period', 'bucket_start']);
            $table->index(['period', 'bucket_start']);
        });

        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('monitor_id');
            $table->timestamp('started_at');
            $table->timestamp('confirmed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('cause_kind', 16)->nullable();
            $table->string('cause', 300)->nullable();
            $table->unsignedSmallInteger('cause_status_code')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->string('resolution', 16)->nullable();
            $table->ulid('issue_id')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'started_at']);
            $table->index(['organization_id', 'started_at']);
        });

        Schema::create('uptime_status_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('project_id')->unique();
            $table->string('slug', 64)->unique();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::create('uptime_status_page_monitors', function (Blueprint $table) {
            $table->ulid('status_page_id');
            $table->ulid('monitor_id');
            $table->string('display_name', 120);
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['status_page_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_status_page_monitors');
        Schema::dropIfExists('uptime_status_pages');
        Schema::dropIfExists('uptime_incidents');
        Schema::dropIfExists('uptime_rollups');
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('uptime_monitors');
    }
};
