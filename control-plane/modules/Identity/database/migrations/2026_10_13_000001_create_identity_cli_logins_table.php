<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `falak login` in the browser (RFC 8628 device authorization). Codes are stored hashed; the token is minted
        // only when the CLI collects it, so no plain token is ever stored.
        Schema::create('identity_cli_logins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('device_code_hash', 64)->unique();
            $table->string('user_code_hash', 64)->unique();
            $table->string('client_name', 100);
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 16)->default('pending'); // pending, approved, denied, consumed
            $table->ulid('user_id')->nullable();
            $table->ulid('organization_id')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // A CLI token expires after this many days without use (expires_at slides on every request).
        Schema::table('identity_personal_access_tokens', function (Blueprint $table) {
            $table->unsignedSmallInteger('idle_expiry_days')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('identity_personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('idle_expiry_days');
        });
        Schema::dropIfExists('identity_cli_logins');
    }
};
