<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tailscale only" (docs/plans/TAILSCALE.md): the site's allow list becomes the tailnet ranges (plus its own entries and
 * the control plane).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_site_settings', fn (Blueprint $table) => $table->boolean('tailnet_only')->default(false)->after('encode'));
    }

    public function down(): void
    {
        Schema::table('edge_site_settings', fn (Blueprint $table) => $table->dropColumn('tailnet_only'));
    }
};
