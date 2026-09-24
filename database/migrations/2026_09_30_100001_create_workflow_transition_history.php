<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** REQ-WFL-001: generic, append-only transition history for the shared state machine engine. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transition_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('machine', 80);
            $t->unsignedSmallInteger('machine_version')->default(1);
            $t->string('subject_type', 80);
            $t->string('subject_id', 64);
            $t->string('event', 80);
            $t->string('from_state', 64);
            $t->string('to_state', 64);
            $t->string('actor_id', 64)->nullable();
            $t->string('actor_role', 64)->nullable();
            $t->text('reason')->nullable();
            $t->string('domain_event', 120)->nullable();
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('occurred_at', 6);
            $t->index(['subject_type', 'subject_id', 'occurred_at']);
            $t->index(['machine', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_history');
    }
};
