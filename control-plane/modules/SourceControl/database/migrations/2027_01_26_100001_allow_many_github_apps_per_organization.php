<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One GitHub App per GitHub owner: a private app can only be installed on the account that owns it.
        Schema::table('source_control_github_apps', function (Blueprint $table) {
            $table->dropUnique(['organization_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('source_control_github_apps', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->unique('organization_id');
        });
    }
};
