<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain ownership verification for managed (multi-tenant) installs (controlplane.managed).
 *
 * verification_token — random token the operator puts in _falak-challenge.<domain> TXT record.
 * verified_at        — set when the TXT record is confirmed; null = unverified (not pushed to edge in managed mode).
 *
 * Existing rows are marked verified so existing installs keep working after the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_domains', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->after('dns_credential_id');
            $table->timestamp('verified_at')->nullable()->after('verification_token');
        });

        // Mark every existing domain as already verified: self-hosted upgrades must not block live traffic.
        DB::table('edge_domains')->update(['verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('edge_domains', function (Blueprint $table) {
            $table->dropColumn(['verification_token', 'verified_at']);
        });
    }
};
