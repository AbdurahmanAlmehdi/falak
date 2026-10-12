<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project's falak.yaml (docs/plans/CONFIG_AS_CODE.md, "Tables").
        Schema::create('config_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id')->unique();
            $table->ulid('source_connection_id');
            $table->string('repository');
            $table->string('path')->default('falak.yaml');
            $table->boolean('enabled')->default(true);
            // Until approvals land (phase 4) risky plans are refused instead of waiting.
            $table->boolean('experimental')->default(true);
            // Names of the organization's certificates and DNS credentials an admin lets the file use (besides those
            // the project's domains already use).
            $table->json('allowed_credentials')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // One plan + apply of the file for one environment.
        Schema::create('config_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id');
            $table->ulid('environment_id')->nullable();
            $table->string('environment', 64);
            $table->string('commit', 64)->nullable(); // SHA-1 or SHA-256 repositories
            $table->string('branch')->nullable();
            // push | pr | cli | manual | rollback
            $table->string('trigger', 16);
            // planning | awaiting_approval | applying | applied | failed | cancelled | refused
            $table->string('status', 24);
            $table->longText('file');
            // yaml | json
            $table->string('format', 8)->default('yaml');
            $table->json('resolved')->nullable();
            $table->json('plan')->nullable();
            // [{unit, site_id, deployment_id}] the units deployed
            $table->json('deploy')->nullable();
            // ConfigIssue list (errors and warnings)
            $table->json('errors')->nullable();
            $table->text('error')->nullable();
            $table->boolean('allow_destroy')->default(false);
            $table->ulid('requested_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['environment_id', 'status']);
        });

        // Fields the file manages: drives the read-only UI and safe deletion.
        Schema::create('config_ownership', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id');
            $table->ulid('environment_id');
            $table->string('resource_type', 32);
            $table->ulid('resource_id');
            $table->string('unit', 64)->nullable();
            // JSON Pointer relative to the unit (/deploy/strategy, /workers/queue, /domains/shop.example.com)
            $table->string('path', 500);
            $table->boolean('created_by_file')->default(false);
            $table->ulid('run_id');
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id', 'path']);
            $table->index(['project_id', 'environment_id', 'unit']);
        });

        // **[decision]** Falak's one plan comment per pull request (edited on each update, never posted twice).
        Schema::create('config_pull_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('project_id');
            $table->unsignedInteger('number');
            $table->string('comment_id')->nullable();
            $table->string('head_sha', 64)->nullable();
            $table->string('state', 16)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_pull_requests');
        Schema::dropIfExists('config_ownership');
        Schema::dropIfExists('config_runs');
        Schema::dropIfExists('config_sources');
    }
};
