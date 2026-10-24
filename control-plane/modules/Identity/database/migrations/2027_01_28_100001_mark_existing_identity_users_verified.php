<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts from before email verification (controlplane.managed) count as verified: turning managed mode on
        // must not lock out the people already using the panel.
        DB::table('identity_users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: which accounts were verified before is not recorded.
    }
};
