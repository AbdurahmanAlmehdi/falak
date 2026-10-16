<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandboxes_sandboxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('server_id')->index();
            $table->string('name', 64);
            $table->string('image', 255);
            $table->string('image_digest', 200)->nullable();
            // creating | running | failed | killed (paused / stopped: v0.21)
            $table->string('status', 16)->default('creating');
            $table->string('error', 1000)->nullable();
            $table->decimal('cpus', 5, 2);
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_mb');
            // none | internet | allowlist
            $table->string('network', 16);
            $table->json('allow_hosts')->nullable();
            // Sealed JSON; the API only ever shows its keys.
            $table->text('env')->nullable();
            $table->json('repo')->nullable();
            $table->unsignedInteger('ttl_seconds');
            $table->timestamp('expires_at')->index();
            // The expiry the agent was last given (sandbox.create, sandbox.extend): an extend while creating differs.
            $table->timestamp('agent_expires_at')->nullable();
            $table->string('ip', 45)->nullable();
            // user | token: who created it (a token's user is created_by_id, its name created_by_name).
            $table->string('created_by_type', 8);
            $table->ulid('created_by_id')->nullable();
            $table->string('created_by_name', 255)->nullable();
            $table->ulid('command_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('killed_at')->nullable();
            // When the agent is known not to run it any more (sandbox.kill finished, it reaped it, or never had it);
            // SyncSandboxes sends sandbox.kill again until then.
            $table->timestamp('kill_confirmed_at')->nullable();
            // killed | expired | oom | exited | lost | server_deleted; a failed create: the agent's error
            $table->string('kill_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['server_id', 'status']);
        });

        // Names are unique among an organization's live sandboxes (CreateSandbox also checks under a lock).
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX sandboxes_sandboxes_live_name_unique ON sandboxes_sandboxes (organization_id, name) WHERE status <> 'killed' AND status <> 'failed'");
        }

        // The audit trail of a sandbox: every exec and file write or delete (output itself is not stored).
        Schema::create('sandboxes_execs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sandbox_id')->constrained('sandboxes_sandboxes')->cascadeOnDelete();
            $table->string('actor_type', 8);
            $table->ulid('actor_id')->nullable();
            $table->string('actor', 255)->nullable();
            $table->text('cmd');
            $table->string('cwd', 4096)->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('stdout_bytes')->default(0);
            $table->unsignedBigInteger('stderr_bytes')->default(0);
            $table->boolean('timed_out')->default(false);
            // Why the call failed (no exit code then): the agent's or the control plane's error.
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at');
            $table->index(['sandbox_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandboxes_execs');
        Schema::dropIfExists('sandboxes_sandboxes');
    }
};
