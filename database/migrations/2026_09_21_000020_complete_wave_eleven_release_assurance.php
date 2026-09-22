<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('release_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version', 64)->unique();
            $table->string('commit_sha', 64);
            $table->string('environment', 24);
            $table->string('status', 24)->default('DRAFT')->index();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('release_gate_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_candidate_id')->index();
            $table->string('gate', 32);
            $table->string('status', 16);
            $table->jsonb('evidence')->default('{}');
            $table->char('evidence_hash', 64);
            $table->uuid('assessed_by');
            $table->timestampTz('assessed_at');
            $table->timestampsTz();
            $table->unique(['release_candidate_id', 'gate']);
        });

        Schema::create('security_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_candidate_id')->nullable()->index();
            $table->string('source', 32);
            $table->string('severity', 16)->index();
            $table->string('title', 255);
            $table->text('description');
            $table->string('status', 24)->default('OPEN')->index();
            $table->string('cve', 32)->nullable();
            $table->uuid('owner_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('recovery_exercises', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('environment', 24);
            $table->string('exercise_type', 32);
            $table->string('status', 16)->default('PLANNED');
            $table->unsignedInteger('target_rto_minutes');
            $table->unsignedInteger('target_rpo_minutes');
            $table->unsignedInteger('actual_rto_minutes')->nullable();
            $table->unsignedInteger('actual_rpo_minutes')->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->uuid('conducted_by')->nullable();
            $table->timestampTz('conducted_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_exercises');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('release_gate_results');
        Schema::dropIfExists('release_candidates');
    }
};
