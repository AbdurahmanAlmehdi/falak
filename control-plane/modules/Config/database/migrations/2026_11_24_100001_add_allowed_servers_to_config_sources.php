<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_sources', function (Blueprint $table) {
            // Server names an admin lets the file place units on besides those the project already uses (a new
            // project uses none).
            $table->json('allowed_servers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('config_sources', fn (Blueprint $table) => $table->dropColumn('allowed_servers'));
    }
};
