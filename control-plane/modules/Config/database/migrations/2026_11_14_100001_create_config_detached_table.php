<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a run detached: gone from the file (or its environment released) but kept, no longer managed. The
        // dashboard lists them ("Unmanaged resources") with the module's own delete.
        Schema::create('config_detached', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id');
            $table->ulid('environment_id');
            $table->string('unit', 64)->nullable();
            $table->string('resource_type', 32);
            $table->ulid('resource_id');
            // the site the resource belongs to (a worker's, a domain's): deleting the site drops the row
            $table->ulid('site_id')->nullable()->index();
            $table->string('label', 255);
            // removed_from_file | environment_released
            $table->string('reason', 32);
            $table->string('delete_url', 1000)->nullable();
            // what that delete route needs in its body (a site's typed name)
            $table->json('delete_body')->nullable();
            $table->ulid('run_id')->nullable();
            $table->timestamp('detached_at');
            $table->timestamps();

            $table->index(['project_id', 'environment_id']);
            $table->unique(['environment_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_detached');
    }
};
