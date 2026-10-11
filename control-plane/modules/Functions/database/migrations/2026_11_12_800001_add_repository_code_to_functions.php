<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Functions of a falak.yaml (docs/plans/CONFIG_AS_CODE.md, phase 3): their code is the repository's files under
        // `root` (null: written in the editor).
        Schema::table('functions_functions', function (Blueprint $table) {
            $table->string('root', 255)->nullable();
        });

        // The version a repository commit's files are (versions never change; one version serves every commit with the
        // same code): a deployment of that commit runs that version.
        Schema::create('functions_version_commits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('function_id');
            $table->string('commit', 64);
            $table->ulid('version_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['function_id', 'commit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functions_version_commits');
        Schema::table('functions_functions', fn (Blueprint $table) => $table->dropColumn('root'));
    }
};
