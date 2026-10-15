<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path rate limits at the edge (docs/plans/network/protection.md): requests per client IP on a path of a site, rendered
 * by the server's Caddy (Falak's build with caddy-ratelimit). Unlike the Cloudflare rate limits (edge_domains), they
 * work for every site, proxied or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_path_rate_limits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('path', 200);
            $table->unsignedInteger('events');
            $table->unsignedInteger('window_s');
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'path']);
        });

        // Why the server's edge applied its config without the rate limits (the agent's result), null when it did.
        Schema::table('edge_server_states', function (Blueprint $table) {
            $table->string('rate_limits_refused', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_server_states', fn (Blueprint $table) => $table->dropColumn('rate_limits_refused'));
        Schema::dropIfExists('edge_path_rate_limits');
    }
};
