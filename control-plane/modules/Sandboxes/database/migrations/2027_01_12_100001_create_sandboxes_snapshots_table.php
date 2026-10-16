<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sandboxes v0.21 (docs/plans/SANDBOXES.md "Contracts (v0.21)"): snapshots, idle pause, sandboxes made from a snapshot,
// a database copy asked for on create.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandboxes_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            // The sandbox it was taken of (the row outlives the sandbox; sandboxes are only deleted with their organization).
            $table->ulid('sandbox_id')->index();
            $table->ulid('server_id')->index();
            $table->string('name', 64)->nullable();
            // creating | ready | failed | deleted
            $table->string('status', 16)->default('creating');
            $table->string('error', 1000)->nullable();
            // The source sandbox's spec: what every sandbox made from it gets.
            $table->string('image', 255);
            $table->string('image_digest', 200)->nullable();
            $table->decimal('cpus', 5, 2);
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_mb');
            $table->string('network', 16);
            $table->json('allow_hosts')->nullable();
            // Sealed JSON; carried over into its forks (the agent restores it, the control plane shows its keys).
            $table->text('env')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('created_by_type', 8);
            $table->ulid('created_by_id')->nullable();
            $table->string('created_by_name', 255)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            // When the agent is known not to keep it any more; SyncSandboxes sends sandbox.snapshot.delete until then.
            $table->timestamp('delete_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['server_id', 'status']);
        });

        // Names are unique among an organization's snapshots that aren't deleted (TakeSnapshot also checks under a lock).
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX sandboxes_snapshots_live_name_unique ON sandboxes_snapshots (organization_id, name) WHERE status <> 'deleted'");
        }

        Schema::table('sandboxes_sandboxes', function (Blueprint $table) {
            // Paused after this many seconds without exec, file calls, consoles or API calls on it (null: never).
            $table->unsignedInteger('idle_pause_seconds')->nullable();
            $table->ulid('from_snapshot_id')->nullable()->index();
            // {instance, instance_name, engine, database}: copied in by sandbox.db.import once the create succeeded.
            $table->json('db_import')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sandboxes_sandboxes', function (Blueprint $table) {
            $table->dropIndex(['from_snapshot_id']);
            $table->dropColumn(['idle_pause_seconds', 'from_snapshot_id', 'db_import']);
        });
        Schema::dropIfExists('sandboxes_snapshots');
    }
};
