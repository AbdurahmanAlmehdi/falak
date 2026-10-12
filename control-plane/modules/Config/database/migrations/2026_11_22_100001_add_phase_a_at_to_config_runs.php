<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_runs', function (Blueprint $table) {
            // When the run's phase A committed: from then on its resolved file is what the environment is configured
            // with (drift's baseline), even if a deployment failed afterwards.
            $table->timestamp('phase_a_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('config_runs', fn (Blueprint $table) => $table->dropColumn('phase_a_at'));
    }
};
