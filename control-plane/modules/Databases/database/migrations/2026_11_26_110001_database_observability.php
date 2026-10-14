<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database observability (docs/plans/OBSERVABILITY.md phase 3): the agent's latest `db.observed` report on the instance,
 * query insights (a restart: opt-in for existing instances), top query shapes with hourly stats, and daily size samples
 * (the disk forecast). Stats and samples are pruned after databases.observability.retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases_instances', function (Blueprint $table) {
            $table->boolean('query_insights')->default(false);
            $table->json('observed')->nullable();
            $table->timestamp('observed_at')->nullable();
        });

        Schema::create('databases_query_shapes', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id');
            $table->ulid('database_instance_id');
            // pg:<queryid> | my:<digest> | sql:<hash> | cmd:<NAME>:<keys>
            $table->string('fingerprint', 128);
            $table->string('database', 128)->nullable();
            // Normalized: no literals.
            $table->text('query');
            $table->string('source', 32);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['database_instance_id', 'fingerprint']);
            $table->index('last_seen_at');
        });

        Schema::create('databases_query_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shape_id');
            $table->ulid('database_instance_id');
            $table->timestamp('hour');
            $table->unsignedBigInteger('calls')->default(0);
            $table->double('total_ms')->default(0);
            $table->double('max_ms')->default(0);
            $table->unsignedBigInteger('rows')->default(0);
            $table->unsignedBigInteger('no_index')->default(0);
            $table->unique(['shape_id', 'hour']);
            $table->index(['database_instance_id', 'hour']);
        });

        Schema::create('databases_size_samples', function (Blueprint $table) {
            $table->ulid('database_instance_id');
            $table->date('day');
            $table->unsignedBigInteger('size_bytes');
            $table->primary(['database_instance_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases_size_samples');
        Schema::dropIfExists('databases_query_stats');
        Schema::dropIfExists('databases_query_shapes');

        Schema::table('databases_instances', function (Blueprint $table) {
            $table->dropColumn(['query_insights', 'observed', 'observed_at']);
        });
    }
};
