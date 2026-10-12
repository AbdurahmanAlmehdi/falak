<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_sources', function (Blueprint $table) {
            // An admin setting: the requester of a run can't approve it.
            $table->boolean('separate_approver')->default(false);
        });

        Schema::table('config_runs', function (Blueprint $table) {
            $table->timestamp('approval_expires_at')->nullable();
            // The risky changes the approver saw ([{path, rule}]): an approval covers only them.
            $table->json('approved_risky')->nullable();
            $table->text('approval_comment')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            // superseded | rejected | expired | source_disabled | user
            $table->string('cancel_reason', 24)->nullable();
        });

        Schema::table('config_pull_requests', function (Blueprint $table) {
            // The plan comment as last posted (a waiting approval is appended to it).
            $table->longText('body')->nullable();
        });

        // The last drift check of an environment.
        Schema::create('config_drift', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id');
            $table->ulid('environment_id')->unique();
            // in_sync | drifted | unknown
            $table->string('status', 16);
            $table->ulid('baseline_run_id')->nullable();
            $table->string('signature', 64)->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('since')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('alerted_signature', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_drift');
        Schema::table('config_pull_requests', fn (Blueprint $table) => $table->dropColumn('body'));
        Schema::table('config_runs', fn (Blueprint $table) => $table->dropColumn(['approval_expires_at', 'approved_risky', 'approval_comment', 'rejected_by', 'rejected_at', 'cancel_reason']));
        Schema::table('config_sources', fn (Blueprint $table) => $table->dropColumn('separate_approver'));
    }
};
