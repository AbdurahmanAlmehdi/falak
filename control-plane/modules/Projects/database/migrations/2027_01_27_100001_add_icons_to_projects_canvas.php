<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Icons picked on the canvas: a Cool Icons slug, optionally with a variant ("docker", "docker/svgl-dark").
        // UI only: falak.yaml never reads or writes them. A service / group without one keeps its derived icon.
        Schema::table('projects_projects', function (Blueprint $table) {
            $table->string('icon', 128)->nullable()->change();
        });

        Schema::table('projects_services', function (Blueprint $table) {
            $table->string('icon', 128)->nullable();
        });

        Schema::table('projects_groups', function (Blueprint $table) {
            $table->string('icon', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects_groups', function (Blueprint $table) {
            $table->dropColumn('icon');
        });

        Schema::table('projects_services', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
