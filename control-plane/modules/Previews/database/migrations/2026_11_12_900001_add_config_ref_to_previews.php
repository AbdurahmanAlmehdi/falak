<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The commit (or branch) of the falak.yaml the preview was last built from (projects following a file).
        Schema::table('previews_previews', function (Blueprint $table) {
            $table->string('config_ref', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('previews_previews', fn (Blueprint $table) => $table->dropColumn('config_ref'));
    }
};
